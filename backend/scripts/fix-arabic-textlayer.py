#!/usr/bin/env python3
"""Fail-closed Arabic text-layer normaliser + validator for Chromium-printed PDFs.

Chromium's HTML->PDF writes Arabic glyphs whose /ToUnicode entries point at Arabic
Presentation-Forms-A/B codepoints (U+FB50..U+FDFF, U+FE70..U+FEFF) laid out in visual order,
so copy/search returns reversed, disjointed text. We rewrite every /ToUnicode destination
through Unicode NFKC, folding each presentation form to its canonical base letter
(e.g. U+FE97 -> U+062A, U+FEFB -> U+0644 U+0627). Visible glyphs are untouched — the rendered
page is byte-identical; only the copy/search/AT text layer improves.

This is NOT best-effort. It runs at most MAX_PASSES sweeps, then VALIDATES the result and
exits non-zero if any invariant fails, so the caller can fail the export rather than ship a
client PDF with a broken text layer. A machine-readable report is written to --report.

Invariants enforced after normalisation:
  * presentation_forms_remaining == 0   (foldable glyphs in FB50-FDFF / FE70-FEFF)
  * ToUnicode stream count preserved    (no font lost its text mapping)
  * page count preserved
  * tagged-PDF structure preserved      (MarkInfo/Marked)
  * ASCII / Latin / digit mappings untouched (we only fold the Arabic PF ranges)

Deterministic + idempotent: NFKC is a fixed point, so a second run after success remaps 0.
"""
import json
import re
import sys
import time
import unicodedata

import pikepdf

HEX = r"<([0-9A-Fa-f]+)>"
MAX_PASSES = 3

# Arabic presentation-form blocks (what Chromium emits and we must eliminate).
PF_RANGES = ((0xFB50, 0xFDFF), (0xFE70, 0xFEFF))


def _in_pf(cp: int) -> bool:
    return any(lo <= cp <= hi for lo, hi in PF_RANGES)


def _foldable_pf(s: str) -> bool:
    """True if s is a presentation-form string that NFKC would still change."""
    return any(_in_pf(ord(c)) for c in s) and unicodedata.normalize("NFKC", s) != s


def _fold_hex(h: str) -> str:
    """UTF-16BE hex -> NFKC -> UTF-16BE hex. Returns original on any failure."""
    try:
        s = bytes.fromhex(h if len(h) % 2 == 0 else "0" + h).decode("utf-16-be")
    except Exception:
        return h
    n = unicodedata.normalize("NFKC", s)
    return n.encode("utf-16-be").hex().upper() if n != s else h


def _rewrite_bfchar(block: str, counter: list) -> str:
    def repl(m):
        dst = _fold_hex(m.group(2))
        if dst != m.group(2):
            counter[0] += 1
        return f"<{m.group(1)}> <{dst}>"
    return re.sub(HEX + r"\s*" + HEX, repl, block)


def _rewrite_bfrange(block: str, counter: list) -> str:
    def repl(m):
        dst = _fold_hex(m.group(3))
        if dst != m.group(3):
            counter[0] += 1
        return f"<{m.group(1)}> <{m.group(2)}> <{dst}>"
    return re.sub(HEX + r"\s*" + HEX + r"\s*" + HEX, repl, block)


def remap(data: bytes) -> tuple[bytes, int]:
    text = data.decode("latin-1")
    counter = [0]
    text = re.sub(r"beginbfchar(.*?)endbfchar",
                  lambda m: "beginbfchar" + _rewrite_bfchar(m.group(1), counter) + "endbfchar",
                  text, flags=re.S)
    text = re.sub(r"beginbfrange(.*?)endbfrange",
                  lambda m: "beginbfrange" + _rewrite_bfrange(m.group(1), counter) + "endbfrange",
                  text, flags=re.S)
    return text.encode("latin-1"), counter[0]


def _iter_tounicode(pdf):
    seen = set()
    for obj in pdf.objects:
        try:
            if isinstance(obj, pikepdf.Object) and "/ToUnicode" in obj.keys():
                tu = obj["/ToUnicode"]
                key = (getattr(tu, "objgen", None) or id(tu))
                if key in seen:
                    continue
                seen.add(key)
                yield tu
        except Exception:
            continue


def _scan(pdf) -> dict:
    """Count presentation-form + ASCII destinations across all ToUnicode streams."""
    pf = ascii_dests = tu_streams = 0
    for tu in _iter_tounicode(pdf):
        tu_streams += 1
        try:
            txt = tu.read_bytes().decode("latin-1")
        except Exception:
            continue
        for dst in re.findall(r"<[0-9A-Fa-f]+>\s*<([0-9A-Fa-f]{2,})>", txt):
            try:
                s = bytes.fromhex(dst if len(dst) % 2 == 0 else "0" + dst).decode("utf-16-be")
            except Exception:
                continue
            if _foldable_pf(s):
                pf += 1
            if all(ord(c) < 0x80 for c in s):
                ascii_dests += 1
    return {"presentation_forms": pf, "ascii_dests": ascii_dests, "tounicode_streams": tu_streams}


def _pass(pdf) -> int:
    changed = 0
    for tu in _iter_tounicode(pdf):
        try:
            new, c = remap(tu.read_bytes())
        except Exception:
            continue
        if c:
            tu.write(new)
            changed += c
    return changed


def _tagged(raw: bytes) -> bool:
    return b"/MarkInfo" in raw and b"/Marked true" in raw


def normalize(path: str, report_path: str | None = None) -> dict:
    raw_before = open(path, "rb").read()
    pdf = pikepdf.open(path, allow_overwriting_input=True)
    before = _scan(pdf)
    page_count_before = len(pdf.pages)

    passes = []
    total_changed = 0
    for i in range(MAX_PASSES):
        t0 = time.time()
        pre = _scan(pdf)["presentation_forms"]
        changed = _pass(pdf)
        total_changed += changed
        post = _scan(pdf)["presentation_forms"]
        passes.append({
            "pass": i + 1,
            "presentation_forms_before": pre,
            "presentation_forms_after": post,
            "cmaps_modified": changed,
            "duration_ms": round((time.time() - t0) * 1000, 1),
        })
        if post == 0 and changed == 0:
            break

    after = _scan(pdf)
    # Byte-idempotency: only rewrite the file when we actually changed something. A second run on
    # an already-normalised file remaps 0, skips the save, and leaves the bytes untouched.
    if total_changed > 0:
        pdf.save(path)
    pdf.close()
    raw_after = open(path, "rb").read()

    checks = {
        "presentation_forms_remaining": after["presentation_forms"] == 0,
        "tounicode_preserved": after["tounicode_streams"] == before["tounicode_streams"] and after["tounicode_streams"] > 0,
        "page_count_preserved": page_count_before == len(pikepdf.open(path).pages),
        "tagged_structure_preserved": _tagged(raw_before) == _tagged(raw_after),
        "ascii_mappings_preserved": after["ascii_dests"] == before["ascii_dests"],
        "within_pass_budget": after["presentation_forms"] == 0,
    }
    status = "passed" if all(checks.values()) else "failed"
    report = {
        "file": path,
        "fonts": len(set(re.findall(rb"/BaseFont\s*/([A-Za-z0-9+\-]+)", raw_after))),
        "tounicode_streams": after["tounicode_streams"],
        "presentation_forms_before": before["presentation_forms"],
        "presentation_forms_remaining": after["presentation_forms"],
        "passes": passes,
        "max_passes": MAX_PASSES,
        "checks": checks,
        "status": status,
    }
    if report_path:
        with open(report_path, "w") as f:
            json.dump(report, f, ensure_ascii=False, indent=2)
    return report


def main(argv) -> int:
    if not argv:
        print("usage: fix-arabic-textlayer.py <pdf> [--report out.json]", file=sys.stderr)
        return 2
    path = argv[0]
    report_path = None
    if "--report" in argv:
        report_path = argv[argv.index("--report") + 1]
    report = normalize(path, report_path)
    line = (f"text-layer {report['status']}: "
            f"{report['presentation_forms_before']} -> {report['presentation_forms_remaining']} "
            f"presentation forms in {len(report['passes'])} pass(es), {report['tounicode_streams']} CMaps")
    if report["status"] == "passed":
        print(line)
        return 0
    print("ARABIC TEXT-LAYER VALIDATION FAILED: " + line, file=sys.stderr)
    print(json.dumps(report["checks"]), file=sys.stderr)
    return 1


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
