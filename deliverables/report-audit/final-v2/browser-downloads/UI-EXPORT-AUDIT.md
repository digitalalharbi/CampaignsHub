# UI Export Button — Browser Download Audit

The file below was downloaded by a **real browser click** on the export button, captured by the
Playwright spec `e2e/report-pdf-download.spec.ts` (system chain: React button → API → ReportExport
row → queue job → Chromium renderer → validators → storage → download endpoint → browser download).
ReportExporter is never called directly.

**File:** `browser-downloads/client-monthly-ar-ui-download.pdf`

| Check | Result |
|---|---|
| UI Export Button Test | ✅ PASSED (Playwright, full chain) |
| Export request payload | `{ format: "pdf" }` to the opened report |
| Renderer (export row) | chromium |
| renderer_version / template_version | chromium-1228 / 2 (current) |
| locale / layout_mode | ar / presentation |
| validation_status | passed |
| Not a legacy/stale export | ✅ (new token each export; stale ⇒ 409 on download) |
| Browser Download Audit | ✅ PASSED |
| Stored/Downloaded Checksum | ✅ MATCHED (download re-fetch SHA == downloaded SHA) |
| %PDF header | ✅ |
| Pages | 11 |
| IBM Plex Sans Arabic embedded | ✅ |
| Dompdf | ❌ absent |
| Tagged (searchable) | ✅ |
| Arabic search/copy | ✅ 4/4 probe words (PDFKit) |
| Internal terms (burner/checksum) | ✅ none |
| SHA-256 | b887c4891771fe7e26f2133b0ff4ccbcf3092dab8b7108e2ae6c3855bdda754d |
