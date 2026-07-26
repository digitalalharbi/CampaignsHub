# Report Deliverables — final-v2 (generated via the SYSTEM export service)

Renderer: chromium (chromium-1228) · template v2 · fail-closed pipeline

| File | Renderer | Pages | Empty | Footer-only | Searchable (AR) | Internal terms | Narrative |
|---|---|---|---|---|---|---|---|
| client-weekly-ar-v2 | chromium | 12 | 0 | 0 | 4/4 | ✅ none | ✅ consistent |
| client-monthly-ar-v2 | chromium | 12 | 0 | 0 | 4/4 | ✅ none | ✅ consistent |
| client-platform-comparison-ar-v2 | chromium | 12 | 0 | 0 | 4/4 | ✅ none | ✅ consistent |

## UI Export Button Test (real browser click-through)
- **UI Export Button Test: PASSED** — `e2e/report-pdf-download.spec.ts` (create → generate → export → queue → Chromium → download).
- **Browser Download Audit: PASSED** — downloaded file: IBM Plex embedded, not Dompdf, tagged, 4/4 Arabic searchable, no internal terms.
- **Stored/Downloaded Checksum: MATCHED** — the download endpoint streams the stored export byte-for-byte.
- Export row provenance: renderer=chromium, renderer_version=chromium-1228, template_version=2, locale=ar, layout_mode=presentation, validation_status=passed.
- See `browser-downloads/UI-EXPORT-AUDIT.md`.
