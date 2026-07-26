# PDF Visual & Numeric Audit

How CampaignsHub proves a report PDF is correct — verified from the **actual generated PDF file**, not
just the snapshot or the pre-print DOM. This closes the class of defects that only appear after export
(Arabic reversal, page spill, missing charts, whitespace) even when the source data is correct.

## Pipeline
```
Canonical Snapshot
 → React Print Route (/reports/print/:token)   # same SlideBody as the interactive report
 → SlideContentFitter (scale-to-fit, readable floor 0.82)
 → Layout gate (__REPORT_LAYOUT__: overflow / overflowX / empty — hard fail)
 → Headless Chromium (Playwright) → PDF
 → pdf-audit.py over the PDF FILE:
     • text extraction (pdfplumber) → numeric parity + provenance
     • page rasterisation (pdfplumber + Pillow) → page-NN.png + contact-sheet.png
     • data-consistency.json + layout-report.json
```

## What is verified from the PDF file
- **Page count == visible slide count** (one full page per slide; `height:100vh` + fit-to-page stops
  the sliver-spill that previously turned 11 slides into 17 pages).
- **Numeric parity**: `spend / revenue / results / ROAS / CPA` present in the PDF text (exact or the
  faithful compact form, e.g. `96K` == 96,121 within compact-rounding tolerance).
- **Provenance**: `report_id`, `snapshot_checksum`, `data_version`, source/attribution/currency/
  timezone/mode — embedded in the PDF `/Title` metadata **and** printed on the methodology page, so a
  file can always be traced to the snapshot it came from.
- **Arabic correctness**: confirmed from the rasterised page images (RTL text extracts in visual order
  by design, so text extraction is used for numbers, images for script correctness).
- **Layout**: per-page `contentUtilization / chartCoverage / textDensity / largestEmptyRegion` measured
  over REAL content (not the page background); cover + methodology are text-page exempt.

## Reproduce
```bash
# backend, with the SPA print route reachable (dev: Vite :5173) and a completed report:
node scripts/report-print.mjs '{"url":"http://localhost:5173/reports/print/<token>?type=presentation&theme=light","out":"/tmp/r.pdf","landscape":true,"requireBase":"<repo>/frontend/package.json"}'
python3 scripts/pdf-audit.py /tmp/r.pdf expected.json out-dir layout.json
```
`expected.json` = `{report_id, checksum, data_version, currency, kpis:{...}}` from the snapshot.

## Latest audit — monthly-ar (presentation, A4 landscape)
| field | result |
|---|---|
| PDF engine | Headless Chromium (Playwright 1.61, chromium-1228) |
| Fonts | IBM Plex Sans Arabic + Inter (embedded, self-hosted) |
| Locale / dir | ar / rtl |
| Pages | 11 (== 11 visible slides) |
| Numeric parity | **PASSED** (0 differences vs snapshot) |
| Provenance in /Title | ✔ report_id + checksum + data_version |
| Overflow / empty pages | 0 / 0 |
| Sparse pages | 0 (cover 0.21 & methodology exempt; content pages 0.62–0.75) |
| Arabic visual | correct (cover, headings, mixed AR/EN, numerals via `<bdi>`) |
| Export parity (CSV/XLSX) | PASSED (`ReportExportParityTest`) |

Audit artifacts (`source.pdf`, `page-NN.png`, `contact-sheet.png`, `data-consistency.json`,
`layout-report.json`) are generated on demand under the scratch/audit dir — not committed (binary
bloat); regenerate with the commands above.

## Remaining before the reports phase is closed
- Generate + audit the other three model PDFs: `weekly-ar`, `platform-comparison-ar`,
  `monthly-en-document` (LTR / A4 portrait / Inter / multi-page tables).
- Recommendation approval workflow (Draft/Reviewed/Approved/Hidden — client link shows Approved only).
- Edge-case reports (single platform/campaign, no revenue/conversions, missing funnel, long names…).
- Visual-regression baselines from the PDF page PNGs (no auto-baseline-update).
