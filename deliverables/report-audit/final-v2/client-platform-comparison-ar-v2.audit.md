# final-v2 audit — client-platform-comparison-ar-v2

| Field | Value |
|---|---|
| renderer | chromium |
| renderer_version | chromium-1228 |
| template_version | 2 |
| locale | ar |
| direction | RTL |
| font | IBM Plex Sans Arabic (embedded subset) (embedded: True) |
| page_count | 12 |
| page_size | 842x595 |
| empty_pages | 0 (Chromium layout gate hard-fails on empty/overflow before export) |
| footer_only_pages | 0 thin(<40 chars) — 0 by pipeline gate; heuristic count shown |
| overflow | 0 (layout gate) |
| clipped_elements | 0 (measureLayout gate) |
| narrative_consistency | PASS (no zero-results contradiction; NarrativeConsistencyValidator gate) |
| numeric_parity | results/spend reconciled (ReportDataValidator gate) |
| client_content_validation | PASS (no internal terms in PDF text) |
| arabic_visual_validation | PASS (raster inspected: connected, RTL, not reversed) |
| arabic_text_layer_validation | PASS — PDFKit found 4/4 probe words {'الملخص': 1, 'التوصيات': 3, 'الإنفاق': 30, 'الأداء': 26} |
| manual_audit | pending human sign-off |
| sha256 | 292d281f47865735a58660ad2feab64621940bca3dcd301fb1bb2f33fb858812 |
