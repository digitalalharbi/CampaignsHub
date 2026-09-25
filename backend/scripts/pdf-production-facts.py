#!/usr/bin/env python3
"""
Measure a PRODUCED report PDF, on the box that produced it. Facts only — no judgement, no content.

REPORT-EXPORT-FUNCTIONAL-001. The acceptance bar for this row is a real production PDF that opens and
reads correctly, and the existing audits cannot answer it: `pdf-audit.py` and
`pdf-deliverable-audit.py` both need `pdfplumber` and one of them drives macOS PDFKit, while the
production image carries `py3-pikepdf` and nothing else. So this asks only what pikepdf can answer,
which turns out to be most of it.

## What it will not print

Not one word of the report. The Arabic and Latin counts below are counts of CODEPOINT RANGES in the
text layer's own ToUnicode destinations — enough to say «Arabic is there and it is base letters, not
presentation forms», and never enough to reconstruct a client's figures out of a workflow log. The
same goes for identifiers: it reports HOW MANY uuid-shaped strings it found, never which.

## Why the ToUnicode destinations rather than extracted text

Extraction imposes a reading order, and RTL extraction in visual order is a known trap this repo has
already been caught by. The question here is narrower and does not need order: does the text layer map
to real Arabic base letters. That is exactly what the destinations hold, and it is the same data
`fix-arabic-textlayer.py` validates, so the two cannot disagree about one file.

Usage: pdf-production-facts.py <pdf>   → JSON on stdout, exit 0 if it parsed at all.
"""
import json
import re
import sys

import pikepdf

A4_POINTS = (595.276, 841.89)
TOLERANCE = 3.0

ARABIC_BASE = (0x0600, 0x06FF)
ARABIC_PRESENTATION = ((0xFB50, 0xFDFF), (0xFE70, 0xFEFF))
LATIN = (0x0041, 0x007A)
DIGITS_LATIN = (0x0030, 0x0039)
# CampaignsHub shows Latin digits everywhere, in both languages. Arabic-Indic digits in a client's
# PDF are a product defect, so they are counted rather than assumed absent.
DIGITS_ARABIC_INDIC = ((0x0660, 0x0669), (0x06F0, 0x06F9))

UUID = re.compile(rb"[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}", re.I)
# A ToUnicode CMap states its destinations in two different shapes, and they must be read apart.
#
# `beginbfchar` holds PAIRS — `<src> <dst>`. `beginbfrange` holds TRIPLES — `<lo> <hi> <dst>` — or a
# low/high plus an ARRAY of destinations. A first version ran one pair-regex over both, so every
# bfrange had its `<lo> <hi>` read as a source and a destination: contiguous runs like the digits
# 0-9, which is exactly how digits are emitted, were reported as absent. The measurement said «this
# report contains no numbers», which is not a fact about any report.
BFCHAR_BLOCK = re.compile(rb"beginbfchar(.*?)endbfchar", re.S)
BFRANGE_BLOCK = re.compile(rb"beginbfrange(.*?)endbfrange", re.S)
HEX = re.compile(rb"<([0-9A-Fa-f]+)>")
BFRANGE_ARRAY = re.compile(rb"<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*\[(.*?)\]", re.S)


def in_range(cp, lo, hi):
    return lo <= cp <= hi


def _points(hexs):
    """A UTF-16BE destination as code points. Surrogate pairs are counted as one."""
    out, i = [], 0
    while i + 3 < len(hexs) + 1 and i + 4 <= len(hexs):
        try:
            unit = int(hexs[i:i + 4], 16)
        except ValueError:
            return out
        if 0xD800 <= unit <= 0xDBFF and i + 8 <= len(hexs):
            try:
                low = int(hexs[i + 4:i + 8], 16)
            except ValueError:
                return out
            out.append(0x10000 + ((unit - 0xD800) << 10) + (low - 0xDC00))
            i += 8
            continue
        out.append(unit)
        i += 4
    return out


def codepoints_from_cmaps(pdf):
    """Every code point the text layer claims to map to, across all ToUnicode streams."""
    points = []
    for obj in pdf.objects:
        try:
            if not isinstance(obj, pikepdf.Stream):
                continue
            data = bytes(obj.read_bytes())
        except Exception:
            continue
        if b"beginbfchar" not in data and b"beginbfrange" not in data:
            continue

        # PAIRS: the second hex string of each pair is the destination.
        for block in BFCHAR_BLOCK.findall(data):
            hexes = HEX.findall(block)
            for i in range(1, len(hexes), 2):
                points.extend(_points(hexes[i].decode("ascii", "ignore")))

        # TRIPLES and arrays. An array form is consumed first so its members are not mistaken for a
        # following range's bounds.
        for block in BFRANGE_BLOCK.findall(data):
            arrays = list(BFRANGE_ARRAY.finditer(block))
            consumed = bytearray(block)
            for m in arrays:
                for dst in HEX.findall(m.group(3)):
                    points.extend(_points(dst.decode("ascii", "ignore")))
                # Blank out what was read so the triple pass below does not see it again.
                consumed[m.start():m.end()] = b" " * (m.end() - m.start())

            hexes = HEX.findall(bytes(consumed))
            for i in range(0, len(hexes) - 2, 3):
                lo_hex, hi_hex, dst_hex = hexes[i], hexes[i + 1], hexes[i + 2]
                try:
                    lo = int(lo_hex, 16)
                    hi = int(hi_hex, 16)
                except ValueError:
                    continue
                dst = _points(dst_hex.decode("ascii", "ignore"))
                if not dst or hi < lo or hi - lo > 0xFFFF:
                    continue
                # A range maps lo..hi onto consecutive destinations from dst[0].
                base = dst[0]
                for n in range(hi - lo + 1):
                    points.append(base + n)
    return points


def uuid_hits(pdf, raw):
    """Distinct uuid-shaped strings anywhere a reader or a scraper could find one.

    The raw file catches metadata, outlines and anything uncompressed. Page content streams are read
    DECOMPRESSED, because that is where text drawn onto a page lives and compression would otherwise
    hide exactly the leak this is looking for. Returns a COUNT; the values are never printed.
    """
    found = {m.lower() for m in UUID.findall(raw)}

    for page in pdf.pages:
        if "/Contents" not in page:
            continue
        try:
            data = bytes(page.Contents.read_bytes())
        except Exception:
            try:
                data = b"".join(bytes(c.read_bytes()) for c in page.Contents)
            except Exception:
                continue
        found |= {m.lower() for m in UUID.findall(data)}

    return len(found)


def main():
    if len(sys.argv) < 2:
        print("usage: pdf-production-facts.py <pdf>", file=sys.stderr)
        return 2

    path = sys.argv[1]
    raw = open(path, "rb").read()

    facts = {
        "bytes": len(raw),
        "starts_with_pdf_header": raw[:5] == b"%PDF-",
        "ends_with_eof": b"%%EOF" in raw[-2048:],
        "tagged": b"/MarkInfo" in raw,
        "mentions_dompdf": b"dompdf" in raw.lower(),
        # Counted over DECOMPRESSED content as well as the raw file — see `uuid_hits()`. Scanning the
        # raw bytes alone reported 0 for an identifier sitting in a compressed content stream, which
        # is where a leak in a rendered page would actually be, so the check was answering «I cannot
        # see it» as «it is not there».
        "uuid_shaped_strings": 0,
    }

    with pikepdf.open(path) as pdf:
        pages = list(pdf.pages)
        facts["pages"] = len(pages)

        sizes, a4, with_images, drawn = [], 0, 0, []
        for page in pages:
            try:
                box = [float(v) for v in page.MediaBox]
                w, h = round(box[2] - box[0], 2), round(box[3] - box[1], 2)
            except Exception:
                w = h = 0.0
            sizes.append([w, h])

            short, long_ = min(w, h), max(w, h)
            if (abs(short - A4_POINTS[0]) <= TOLERANCE and abs(long_ - A4_POINTS[1]) <= TOLERANCE):
                a4 += 1

            try:
                res = page.get("/Resources", {})
                xobjs = res.get("/XObject", {}) if res else {}
                if any(str(xobjs[k].get("/Subtype", "")) == "/Image" for k in (xobjs or {})):
                    with_images += 1
            except Exception:
                pass

            # Drawn-ness, measured rather than inferred from image XObjects.
            #
            # A first version counted only images and reported 0 on a deck whose charts are perfectly
            # present — they are VECTOR, drawn as paths by the chart components, and an image count
            # says nothing about them. That is the same shape of mistake as a font check looking for a
            # package nothing installs: a ❌ against a working product. Content-stream size and
            # path-painting operators separate a drawn page from a blank one.
            try:
                content = bytes(page.Contents.read_bytes()) if "/Contents" in page else b""
            except Exception:
                try:
                    content = b"".join(bytes(c.read_bytes()) for c in page.Contents)
                except Exception:
                    content = b""

            ops = len(re.findall(rb"(?:^|[\s])(?:re|m|c|v|y|l)(?=[\s])", content))
            paints = len(re.findall(rb"(?:^|[\s])(?:f|f\*|F|B|B\*|b|b\*|S|s)(?=[\s])", content))
            drawn.append({"content_bytes": len(content), "path_ops": ops, "paint_ops": paints})

        facts["page_sizes_points"] = sizes
        facts["pages_a4"] = a4
        facts["pages_with_images"] = with_images
        facts["page_drawn"] = drawn
        # «Blank» means nothing was painted on it at all — not «no image».
        facts["blank_pages"] = sum(1 for d in drawn if d["paint_ops"] == 0 and d["content_bytes"] < 512)
        facts["min_content_bytes"] = min((d["content_bytes"] for d in drawn), default=0)

        fonts = sorted({m.decode("ascii", "ignore") for m in re.findall(rb"/BaseFont\s*/([A-Za-z0-9+\-]+)", raw)})
        facts["fonts"] = fonts
        facts["has_arabic_font"] = any("Arabic" in f for f in fonts)

        points = codepoints_from_cmaps(pdf)
        facts["uuid_shaped_strings"] = uuid_hits(pdf, raw)
        facts["cmap_codepoints"] = len(points)
        facts["arabic_base_codepoints"] = sum(1 for c in points if in_range(c, *ARABIC_BASE))
        facts["arabic_presentation_codepoints"] = sum(
            1 for c in points if any(in_range(c, lo, hi) for lo, hi in ARABIC_PRESENTATION)
        )
        facts["latin_codepoints"] = sum(1 for c in points if in_range(c, *LATIN))
        facts["latin_digit_codepoints"] = sum(1 for c in points if in_range(c, *DIGITS_LATIN))
        facts["arabic_indic_digit_codepoints"] = sum(
            1 for c in points if any(in_range(c, lo, hi) for lo, hi in DIGITS_ARABIC_INDIC)
        )

    print(json.dumps(facts, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
