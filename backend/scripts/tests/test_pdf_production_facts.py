#!/usr/bin/env python3
"""
Contract for the production PDF fact inspector — REPORT-EXPORT-FUNCTIONAL-001.

The inspector is what decides whether a real production PDF met the acceptance bar, so a measurement
bug in it is indistinguishable from a product defect. It has already had two, and both were caught by
running it on a real Chromium file rather than by reading it:

  1. «Charts are blank» — it counted image XObjects, and this product's charts are VECTOR. A deck with
     every chart drawn reported `pages_with_images: 0`.
  2. «The report contains no digits» — one pair-regex was run over both CMap shapes, so every
     `beginbfrange` had its `<lo> <hi>` bounds read as a source and a destination. Contiguous runs are
     exactly how digits are emitted, so 0-9 vanished from the measurement.

Both are pinned below, each on a fixture that fails against the old behaviour.

Run directly (`python3 test_pdf_production_facts.py`) or through `PdfProductionFactsTest`.
"""
import json
import os
import subprocess
import sys
import tempfile

import pikepdf

HERE = os.path.dirname(os.path.abspath(__file__))
INSPECTOR = os.path.join(os.path.dirname(HERE), "pdf-production-facts.py")

A4_LANDSCAPE = (841.89, 595.276)


def _cmap(pairs=(), ranges=(), arrays=()):
    """A ToUnicode CMap carrying any mixture of the three shapes a real one uses."""
    out = [
        "/CIDInit /ProcSet findresource begin 12 dict begin begincmap",
        "/CMapName /Adobe-Identity-UCS def",
        "1 begincodespacerange", "<0000> <FFFF>", "endcodespacerange",
    ]
    if pairs:
        out.append(f"{len(pairs)} beginbfchar")
        out += [f"<{s}> <{d}>" for s, d in pairs]
        out.append("endbfchar")
    if ranges or arrays:
        out.append(f"{len(ranges) + len(arrays)} beginbfrange")
        out += [f"<{lo}> <{hi}> <{d}>" for lo, hi, d in ranges]
        out += [f"<{lo}> <{hi}> [{' '.join('<' + x + '>' for x in ds)}]" for lo, hi, ds in arrays]
        out.append("endbfrange")
    out.append("endcmap CMapName currentdict /CMap defineresource pop end end")
    return "\n".join(out).encode("latin-1")


def _build(path, cmap, *, pages=1, size=A4_LANDSCAPE, content=None, tagged=True):
    pdf = pikepdf.new()
    for _ in range(pages):
        pdf.add_blank_page(page_size=size)
    tu = pdf.make_stream(cmap)
    font = pdf.make_indirect(pikepdf.Dictionary(
        Type=pikepdf.Name.Font, Subtype=pikepdf.Name.Type0,
        BaseFont=pikepdf.Name("/AAAAAA+IBMPlexSansArabic-Regular"), ToUnicode=tu,
    ))
    for page in pdf.pages:
        page.Resources = pikepdf.Dictionary(Font=pikepdf.Dictionary(F0=font))
        if content is not None:
            page.Contents = pdf.make_stream(content)
    if tagged:
        pdf.Root.MarkInfo = pikepdf.Dictionary(Marked=True)
    pdf.save(path)
    pdf.close()


def facts(path):
    p = subprocess.run([sys.executable, INSPECTOR, path], capture_output=True, text=True)
    assert p.returncode == 0, p.stderr
    return json.loads(p.stdout)


def test_a_bfrange_of_digits_is_counted():
    """The bug: digits are emitted as a RANGE, and a pair-regex read the range bounds instead."""
    with tempfile.TemporaryDirectory() as d:
        path = os.path.join(d, "digits.pdf")
        # <0010>..<0019> map onto U+0030..U+0039 — ten Latin digits, stated as one range.
        _build(path, _cmap(ranges=[("0010", "0019", "0030")]))

        f = facts(path)

        assert f["latin_digit_codepoints"] == 10, f
        assert f["arabic_indic_digit_codepoints"] == 0, f


def test_arabic_indic_digits_are_reported_when_present():
    """A client's PDF must show Latin digits; Arabic-Indic ones are a defect, so they are counted."""
    with tempfile.TemporaryDirectory() as d:
        path = os.path.join(d, "indic.pdf")
        _build(path, _cmap(ranges=[("0010", "0019", "0660")]))

        f = facts(path)

        assert f["arabic_indic_digit_codepoints"] == 10, f
        assert f["latin_digit_codepoints"] == 0, f


def test_base_arabic_and_presentation_forms_are_told_apart():
    with tempfile.TemporaryDirectory() as d:
        path = os.path.join(d, "arabic.pdf")
        _build(path, _cmap(
            pairs=[("0061", "0627"), ("0062", "0644")],   # ا ل — base letters
            arrays=[("0070", "0071", ["FEF1", "FEDF"])],  # presentation forms
        ))

        f = facts(path)

        assert f["arabic_base_codepoints"] == 2, f
        assert f["arabic_presentation_codepoints"] == 2, f


def test_a_vector_only_page_is_not_called_blank():
    """The other bug: charts here are drawn as paths, and an image count says nothing about them."""
    with tempfile.TemporaryDirectory() as d:
        drawn, blank = os.path.join(d, "drawn.pdf"), os.path.join(d, "blank.pdf")

        # A painted path and nothing else — no image anywhere in the file.
        _build(drawn, _cmap(pairs=[("0061", "0627")]),
               content=b"0 0 1 rg 10 10 200 100 re f\n" * 40)
        _build(blank, _cmap(pairs=[("0061", "0627")]), content=b" ")

        d_facts, b_facts = facts(drawn), facts(blank)

        assert d_facts["pages_with_images"] == 0, d_facts
        assert d_facts["blank_pages"] == 0, d_facts
        assert d_facts["page_drawn"][0]["paint_ops"] > 0, d_facts
        assert b_facts["blank_pages"] == 1, b_facts


def test_a4_is_recognised_in_either_orientation():
    with tempfile.TemporaryDirectory() as d:
        land, port, other = (os.path.join(d, n) for n in ("l.pdf", "p.pdf", "o.pdf"))
        cmap = _cmap(pairs=[("0061", "0627")])
        _build(land, cmap, size=A4_LANDSCAPE, pages=2)
        _build(port, cmap, size=(595.276, 841.89), pages=3)
        _build(other, cmap, size=(612, 792), pages=1)  # US Letter

        assert facts(land)["pages_a4"] == 2
        assert facts(port)["pages_a4"] == 3
        assert facts(other)["pages_a4"] == 0


def test_the_structural_floor_is_measured():
    with tempfile.TemporaryDirectory() as d:
        path = os.path.join(d, "struct.pdf")
        _build(path, _cmap(pairs=[("0061", "0627")]), tagged=True)

        f = facts(path)

        assert f["starts_with_pdf_header"] is True, f
        assert f["ends_with_eof"] is True, f
        assert f["tagged"] is True, f
        assert f["mentions_dompdf"] is False, f
        assert f["has_arabic_font"] is True, f
        assert f["uuid_shaped_strings"] == 0, f


def test_an_identifier_in_the_file_is_counted_not_printed():
    with tempfile.TemporaryDirectory() as d:
        path = os.path.join(d, "leak.pdf")
        leaked = "70589c4f-e362-596f-8b12-2ea611ebc7b2"
        _build(path, _cmap(pairs=[("0061", "0627")]),
               content=("BT (" + leaked + ") Tj ET\n").encode("latin-1"))

        p = subprocess.run([sys.executable, INSPECTOR, path], capture_output=True, text=True)
        assert p.returncode == 0, p.stderr

        assert json.loads(p.stdout)["uuid_shaped_strings"] == 1
        assert leaked not in p.stdout, "the inspector printed the identifier it found"


if __name__ == "__main__":
    fns = [v for k, v in sorted(globals().items()) if k.startswith("test_") and callable(v)]
    for fn in fns:
        fn()
        print(f"  ok {fn.__name__}")
    print(f"all pdf production-facts contract tests passed ({len(fns)})")
