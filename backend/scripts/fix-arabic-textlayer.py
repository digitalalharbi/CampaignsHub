#!/usr/bin/env python3
"""Post-process a Chromium-printed PDF so the *text layer* (copy / extract / screen-reader)
yields real, joinable base Arabic letters instead of isolated/positional PRESENTATION FORMS.

Chromium's HTML->PDF writes Arabic glyphs whose /ToUnicode entries point at Arabic
Presentation-Forms-A/B codepoints (U+FB50..U+FEFF) laid out in visual order. Copying that
text gives "disjointed" glyphs that don't re-shape. We rewrite every /ToUnicode destination
through Unicode NFKC, which folds each presentation form back to its canonical base letter
(e.g. U+FE97 -> U+062A, U+FEFB -> U+0644 U+0627). Visible glyphs are untouched, so the
rendered page is byte-for-byte identical; only the copy/search text improves.

Scope + honesty: this fixes CHARACTER identity, not run ORDER. The engine still emits runs
in visual (RTL-reversed) order; conforming viewers reorder them by Unicode bidi on copy.
Raw byte-order extraction therefore still reads reversed. Idempotent (NFKC is a fixed point).

Handles both `beginbfchar/endbfchar` and `beginbfrange/endbfrange` CMap sections; leaves any
token it cannot parse untouched rather than risk corruption.
"""
import sys
import re
import unicodedata
import pikepdf

HEX = r"<([0-9A-Fa-f]+)>"


def _fold_hex(h: str) -> str:
    """UTF-16BE hex -> NFKC -> UTF-16BE hex. Returns original on any failure."""
    try:
        s = bytes.fromhex(h if len(h) % 2 == 0 else "0" + h).decode("utf-16-be")
    except Exception:
        return h
    n = unicodedata.normalize("NFKC", s)
    return n.encode("utf-16-be").hex().upper() if n != s else h


def _rewrite_bfchar(block: str, counter: list) -> str:
    # entries: <src> <dst>
    def repl(m):
        dst = _fold_hex(m.group(2))
        if dst != m.group(2):
            counter[0] += 1
        return f"<{m.group(1)}> <{dst}>"
    return re.sub(HEX + r"\s*" + HEX, repl, block)


def _rewrite_bfrange(block: str, counter: list) -> str:
    # entries: <lo> <hi> <dst>   (array-form dst [...] left untouched — rare for text)
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


def _pass(pdf) -> int:
    """One sweep over all /ToUnicode streams. Returns how many destinations changed."""
    changed = 0
    for obj in pdf.objects:
        try:
            if isinstance(obj, pikepdf.Object) and "/ToUnicode" in obj.keys():
                tu = obj["/ToUnicode"]
                new, c = remap(tu.read_bytes())
                if c:
                    tu.write(new)
                    changed += c
        except Exception:
            continue
    return changed


def main(path: str) -> None:
    pdf = pikepdf.open(path, allow_overwriting_input=True)
    # Converge: pikepdf can surface the same underlying stream under different object handles,
    # so a single sweep may leave stragglers. Loop until a sweep changes nothing (bounded).
    total = 0
    for _ in range(6):
        c = _pass(pdf)
        total += c
        if c == 0:
            break
    pdf.save(path)
    pdf.close()
    print(f"remapped {total} ToUnicode destinations -> base letters in {path}")


if __name__ == "__main__":
    main(sys.argv[1])
