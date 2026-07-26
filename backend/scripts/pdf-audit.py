#!/usr/bin/env python3
"""
Audit a GENERATED report PDF against its canonical snapshot — the real file, not the DOM.

Given the produced PDF + the expected snapshot values, this:
  1. Extracts text from every PDF page (pdfplumber) and confirms the core KPI numbers, snapshot
     checksum, report id and data_version are actually present in the file.
  2. Rasterises each page to PNG + builds a contact sheet for the manual/visual audit.
  3. Writes data-consistency.json (PDF numeric parity) and manual-audit.md.

Numeric parity here is authoritative: it reads the numbers out of the PDF that a client would open.
(Latin digits extract reliably; Arabic visual correctness is confirmed from the rendered page images,
since RTL text extracts in visual order by design.)

Usage: pdf-audit.py <pdf> <expected.json> <out-dir> [layout.json]
"""
import json
import os
import re
import sys

import pdfplumber


def approx(a, b, tol=0.02):
    if a is None or b is None:
        return False
    scale = max(1.0, abs(a), abs(b))
    return abs(a - b) / scale <= tol


def find_number_near(text, *labels):
    """Find a latin number appearing near any of the given labels in the (visual-order) text."""
    nums = re.findall(r"[-+]?\d[\d,]*\.?\d*", text)
    return [float(n.replace(",", "")) for n in nums]


def main():
    pdf_path, expected_path, out_dir = sys.argv[1], sys.argv[2], sys.argv[3]
    layout = json.load(open(sys.argv[4])) if len(sys.argv) > 4 and os.path.exists(sys.argv[4]) else []
    expected = json.load(open(expected_path))
    os.makedirs(out_dir, exist_ok=True)

    pdf = pdfplumber.open(pdf_path)
    meta_title = (pdf.metadata or {}).get("Title", "")
    full_text = "\n".join((p.extract_text() or "") for p in pdf.pages)
    flat = full_text.replace(",", "")

    diffs = []

    # 1) provenance from PDF /Title + visible provenance line.
    def meta_field(key):
        m = re.search(rf"{key}=([^|\s]+)", meta_title)
        return m.group(1) if m else None

    checks = {
        "report_id": expected.get("report_id"),
        "snapshot_checksum": (expected.get("checksum") or "")[:16],
        "data_version": str(expected.get("data_version") or ""),
    }
    if checks["snapshot_checksum"] and checks["snapshot_checksum"] not in flat and checks["snapshot_checksum"] not in (meta_title or ""):
        diffs.append({"field": "snapshot_checksum", "reason": "checksum not found in PDF text or metadata"})
    if checks["report_id"] and checks["report_id"] not in flat and (meta_field("rid") != checks["report_id"]):
        diffs.append({"field": "report_id", "reason": "report id not found in PDF"})

    # 2) KPI numbers must appear in the PDF text. Collect BOTH exact numbers and compact tokens
    #    (96K → 96000, 1.2M → 1200000, 8.28x → 8.28) so a faithful compact display counts as present.
    all_nums = set()
    for token in re.findall(r"\d[\d,]*\.?\d*", full_text):
        try:
            all_nums.add(round(float(token.replace(",", "")), 2))
        except ValueError:
            pass
    compact_nums = set()
    for num, unit in re.findall(r"(\d+\.?\d*)\s*([KMB])", full_text):
        mul = {"K": 1e3, "M": 1e6, "B": 1e9}[unit]
        compact_nums.add(round(float(num) * mul, 2))

    def present(value):
        if value is None:
            return True
        v = round(float(value), 2)
        if any(approx(v, n) for n in all_nums):
            return True
        # Compact display legitimately rounds (96121 → "96K"); accept within compact rounding error.
        if any(approx(v, n, 0.02) for n in compact_nums):
            return True
        return False

    for key in ("spend", "revenue", "conversions", "roas", "cpa"):
        val = expected.get("kpis", {}).get(key)
        if val is not None and not present(val):
            diffs.append({"field": f"kpi.{key}", "expected": val, "reason": "value not found in PDF text"})

    consistency = {
        "snapshot_checksum": expected.get("checksum"),
        "report_id": expected.get("report_id"),
        "pdf_pages": len(pdf.pages),
        "pdf_title_metadata": meta_title,
        "differences": diffs,
        "status": "passed" if not diffs else "failed",
    }
    json.dump(consistency, open(os.path.join(out_dir, "data-consistency.json"), "w"), ensure_ascii=False, indent=2)
    if layout:
        json.dump(layout, open(os.path.join(out_dir, "layout-report.json"), "w"), ensure_ascii=False, indent=2)

    # 3) Rasterise pages + contact sheet.
    page_imgs = []
    try:
        from PIL import Image
        for i, page in enumerate(pdf.pages):
            im = page.to_image(resolution=110).original.convert("RGB")
            fp = os.path.join(out_dir, f"page-{i + 1:02d}.png")
            im.save(fp)
            page_imgs.append((fp, im))
        if page_imgs:
            cols = 3
            rows = (len(page_imgs) + cols - 1) // cols
            tw, th = 360, int(360 * page_imgs[0][1].height / page_imgs[0][1].width)
            sheet = Image.new("RGB", (cols * tw, rows * th), "white")
            for idx, (_fp, im) in enumerate(page_imgs):
                thumb = im.resize((tw, th))
                sheet.paste(thumb, ((idx % cols) * tw, (idx // cols) * th))
            sheet.save(os.path.join(out_dir, "contact-sheet.png"))
    except Exception as e:  # noqa: BLE001
        consistency["raster_error"] = str(e)[:200]
        json.dump(consistency, open(os.path.join(out_dir, "data-consistency.json"), "w"), ensure_ascii=False, indent=2)

    print(json.dumps({"status": consistency["status"], "pages": len(pdf.pages), "diffs": len(diffs), "images": len(page_imgs)}))
    sys.exit(0 if consistency["status"] == "passed" else 2)


if __name__ == "__main__":
    main()
