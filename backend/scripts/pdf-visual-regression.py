#!/usr/bin/env python3
"""
Visual regression from the ACTUAL PDF (not the print DOM). Rasterises every page of a freshly
generated PDF and compares it, pixel-for-pixel, to an approved baseline PNG. Fails on any change beyond
a small threshold — a moved/vanished chart, reversed Arabic, a font change, an extra/blank page, a
clipped element or a lost footer all move pixels and trip the diff.

Baselines are NEVER updated automatically. `--update` (mapped to npm run test:pdf-visual:update) writes
new baselines and must be an intentional, reviewed commit.

Usage:
  pdf-visual-regression.py <pdf> <baseline-dir> [--update] [--threshold 0.008]
Exit 0 = passed, 2 = regression, 3 = baseline missing (run --update first).
"""
import sys
import os

import pdfplumber
from PIL import Image, ImageChops

DPI = 90


def render_pages(pdf_path):
    pages = []
    pdf = pdfplumber.open(pdf_path)
    for p in pdf.pages:
        pages.append(p.to_image(resolution=DPI).original.convert("RGB"))
    return pages


def diff_ratio(a, b):
    if a.size != b.size:
        b = b.resize(a.size)
    d = ImageChops.difference(a, b).convert("L")
    # fraction of pixels that differ meaningfully (>24/255)
    hist = d.histogram()
    changed = sum(hist[25:])
    total = a.size[0] * a.size[1]
    return changed / total if total else 0.0


def main():
    args = sys.argv[1:]
    update = "--update" in args
    args = [a for a in args if a != "--update"]
    threshold = 0.008
    if "--threshold" in args:
        i = args.index("--threshold")
        threshold = float(args[i + 1])
        del args[i:i + 2]
    pdf_path, baseline_dir = args[0], args[1]
    os.makedirs(baseline_dir, exist_ok=True)

    pages = render_pages(pdf_path)

    if update:
        for i, im in enumerate(pages):
            im.save(os.path.join(baseline_dir, f"page-{i + 1:02d}.png"))
        print(f"[update] wrote {len(pages)} baselines to {baseline_dir}")
        return 0

    baselines = sorted(f for f in os.listdir(baseline_dir) if f.startswith("page-") and f.endswith(".png"))
    if not baselines:
        print(f"[missing] no baselines in {baseline_dir} — run with --update first")
        return 3

    if len(baselines) != len(pages):
        print(f"[FAIL] page count changed: baseline {len(baselines)} vs current {len(pages)}")
        return 2

    diff_dir = os.path.join(baseline_dir, "visual-diff")
    os.makedirs(diff_dir, exist_ok=True)
    failed = []
    for i, im in enumerate(pages):
        base = Image.open(os.path.join(baseline_dir, f"page-{i + 1:02d}.png")).convert("RGB")
        r = diff_ratio(im, base)
        if r > threshold:
            failed.append((i + 1, round(r, 4)))
            ImageChops.difference(im.resize(base.size), base).save(os.path.join(diff_dir, f"page-{i + 1:02d}-diff.png"))
    if failed:
        print(f"[FAIL] {len(failed)} page(s) changed beyond {threshold}: " + ", ".join(f"p{p}={r}" for p, r in failed))
        return 2
    print(f"[PASS] {len(pages)} pages match baseline (threshold {threshold})")
    return 0


if __name__ == "__main__":
    sys.exit(main())
