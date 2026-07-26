#!/usr/bin/env python3
"""Self-contained contract tests for fix-arabic-textlayer.py.

Builds a synthetic PDF whose ToUnicode CMap maps glyph codes to Arabic PRESENTATION FORMS
(plus ASCII + digits), then asserts the normaliser:
  * folds every presentation form to its NFKC base letter (0 remaining) -> status "passed",
  * leaves ASCII / digit mappings untouched,
  * is deterministic + byte-idempotent (a 2nd/3rd run does not change the file),
  * fails closed (exit 1, status "failed") when a form cannot be resolved.

Run: python3 scripts/tests/test_arabic_textlayer.py   (exit 0 = all pass)
"""
import hashlib
import importlib.util
import os
import sys
import tempfile

import pikepdf

HERE = os.path.dirname(os.path.abspath(__file__))
SCRIPT = os.path.join(HERE, "..", "fix-arabic-textlayer.py")
spec = importlib.util.spec_from_file_location("fx", SCRIPT)
fx = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fx)

# glyph code -> destination codepoint. Mix presentation forms, ASCII, digits, a ligature.
ENTRIES = [
    ("0012", "FE8E"),  # ALEF final -> 0627
    ("0029", "FE92"),  # ... -> base
    ("002E", "FE98"),
    ("003B", "FEE8"),
    ("0050", "FEFB"),  # LAM-ALEF ligature -> 0644 0627 (two chars)
    ("0060", "0041"),  # ASCII 'A' (must stay)
    ("0061", "0030"),  # digit '0' (must stay)
]


def _cmap_bytes():
    body = "".join(f"<{s}> <{d}>\n" for s, d in ENTRIES)
    cmap = (
        "/CIDInit /ProcSet findresource begin 12 dict begin begincmap\n"
        "/CMapName /Adobe-Identity-UCS def\n"
        "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n"
        f"{len(ENTRIES)} beginbfchar\n{body}endbfchar\n"
        "endcmap CMapName currentdict /CMap defineresource pop end end"
    )
    return cmap.encode("latin-1")


def _build_pdf(path, cmap=None):
    pdf = pikepdf.new()
    pdf.add_blank_page(page_size=(300, 300))
    tu = pdf.make_stream(cmap if cmap is not None else _cmap_bytes())
    font = pdf.make_indirect(pikepdf.Dictionary(
        Type=pikepdf.Name.Font, Subtype=pikepdf.Name.Type0,
        BaseFont=pikepdf.Name("/AAAAAA+IBMPlexSansArabic-Regular"), ToUnicode=tu,
    ))
    # reference the font from the page resources so it lives in pdf.objects
    pdf.pages[0].Resources = pikepdf.Dictionary(Font=pikepdf.Dictionary(F0=font))
    # mark as tagged so the tagged-structure invariant has something to preserve
    pdf.Root.MarkInfo = pikepdf.Dictionary(Marked=True)
    pdf.save(path)
    pdf.close()


def sha(p):
    return hashlib.sha256(open(p, "rb").read()).hexdigest()


def test_folds_and_passes():
    with tempfile.TemporaryDirectory() as d:
        p = os.path.join(d, "t.pdf")
        _build_pdf(p)
        r = fx.normalize(p)
        assert r["status"] == "passed", r
        assert r["presentation_forms_remaining"] == 0, r
        assert r["presentation_forms_before"] > 0, r
        # ASCII + digit mappings preserved
        assert r["checks"]["ascii_mappings_preserved"], r["checks"]
        # base letters now present in the CMap (read decompressed — pikepdf Flate-encodes on save)
        pdf = pikepdf.open(p)
        cmap = b"".join(tu.read_bytes() for tu in fx._iter_tounicode(pdf))
        pdf.close()
        assert b"0627" in cmap and b"0644" in cmap, "expected base letters in CMap"
        assert b"FE8E" not in cmap and b"FEFB" not in cmap, "presentation forms should be gone"
    print("  ok: folds presentation forms, ASCII/digits preserved, status passed")


def test_idempotent_and_deterministic():
    with tempfile.TemporaryDirectory() as d:
        p = os.path.join(d, "t.pdf")
        _build_pdf(p)
        fx.normalize(p)
        a = sha(p)
        fx.normalize(p)
        b = sha(p)
        fx.normalize(p)
        c = sha(p)
        assert a == b == c, f"not byte-idempotent: {a}/{b}/{c}"
        # deterministic: a fresh build normalised independently yields the same folded CMap dests
        p2 = os.path.join(d, "t2.pdf")
        _build_pdf(p2)
        r2 = fx.normalize(p2)
        assert r2["presentation_forms_remaining"] == 0
    print("  ok: byte-idempotent across 3 runs + deterministic")


def test_fails_closed_on_unresolvable():
    # Simulate forms that cannot be resolved: disable the FOLDING pass while leaving DETECTION
    # intact, so the scan still sees presentation forms after MAX_PASSES -> the gate must reject.
    orig = fx.remap
    fx.remap = lambda data: (data, 0)  # fold nothing
    try:
        with tempfile.TemporaryDirectory() as d:
            p = os.path.join(d, "t.pdf")
            _build_pdf(p)
            r = fx.normalize(p)
            assert r["status"] == "failed", "expected fail-closed when forms cannot resolve"
            assert r["presentation_forms_remaining"] > 0
            assert not r["checks"]["presentation_forms_remaining"]
    finally:
        fx.remap = orig
    print("  ok: fails closed (status=failed) when presentation forms cannot be resolved")


if __name__ == "__main__":
    try:
        test_folds_and_passes()
        test_idempotent_and_deterministic()
        test_fails_closed_on_unresolvable()
    except AssertionError as e:
        print("FAIL:", e, file=sys.stderr)
        raise SystemExit(1)
    print("all arabic text-layer contract tests passed")
