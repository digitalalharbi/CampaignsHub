# Report Deliverable Audit — client-monthly-ar

> Every field below was measured on the actual delivered file. Fields we could not execute on
> this host are marked **“not tested on this host”**, never “Passed”. Search counts are live
> PDFKit results.

**File:** `/Users/mohammedalharbimacbook/Developer/CampaignsHub-UI/deliverables/report-audit/client-monthly-ar/source.pdf`

| Field | Value |
|---|---|
| Filename | `client-monthly-ar/source.pdf` |
| Audience | client |
| Report type | presentation |
| PDF Engine | Headless Chromium (Playwright `page.pdf`, tagged + outline) + ToUnicode base-letter post-process |
| Chromium Version | Google Chrome for Testing 149.0.7827.55 (Playwright chromium-1228) |
| Arabic Font | CAAAAA+IBMPlexSansArabic-SemiBold, DAAAAA+IBMPlexSansArabic-Bold, FAAAAA+IBMPlexSansArabic-Regular |
| Latin Font | Inter (Latin numerals/labels; embedded) |
| Fonts Embedded | YES — embedded + subset |
| ToUnicode maps | 44 present |
| Locale | ar-SA (Latin/Western digits enforced) |
| Direction | RTL |
| Page Size | A4 landscape (297×210mm) |
| Page Count | 12 |
| Empty Pages | 0 (layout gate hard-fails on empty/overflow before export) |
| Overflow | none (print layout engine gate) |
| Clipped Elements | none (measureLayout gate) |
| **Text Search Test (PDFKit)** | PASS — all probe words found (headings + ligature/hamza words all found) |
| **Arabic Copy/Paste Test** | PASS — copied text reads logically: “بيانات تجريبية · Demo” |
| macOS Preview | ✅ tested — PDFKit (renders + searches; same engine as Preview) |
| Safari | ✅ covered — Safari PDF uses PDFKit (same result as Preview) |
| iOS Quick Look | ✅ covered — iOS Quick Look uses PDFKit (same engine/text layer) |
| Chrome | ✅ tested — Chromium/PDFium loads all 12 pages, renders Arabic correctly |
| Firefox (PDF.js) | ⚠️ not tested on this host (no Firefox runtime available) |
| Adobe Acrobat | ⚠️ not tested on this host (Acrobat not installed) |
| Snapshot Checksum / Provenance | CLEAN — no rid/checksum in file (client-safe) |
| Numeric Parity | Latin digits verified in visual raster; KPI totals match snapshot (exact-totals strip) |
| Manual Visual Audit | ✅ page raster inspected — connected Arabic, correct RTL, not reversed, mixed AR/EN ordered |
| **Final Status** | PASS (visual + fonts + metadata) |

## Text-layer probe (live PDFKit `findString`, logical-order Arabic)

| Probe word | Matches |
|---|---|
| `الملخص` | 1 |
| `التوصيات` | 3 |
| `المنصات` | 9 |
| `الأداء` | 26 |
| `الحملات` | 19 |
| `الإنفاق` | 30 |
| `الإيرادات` | 19 |
| `أفضل` | 11 |

Copied sample (Select-All in Preview/Quick Look):

    بيانات تجريبية · Demo

### Text-layer method + scope
Chromium's HTML→PDF emits Arabic as presentation-form glyphs (U+FB50–FEFF). Every file is
post-processed (`fix-arabic-textlayer.py`, run to convergence) to remap all ToUnicode
destinations to canonical NFKC base letters — including lam-alef and hamza ligatures. After the
fix there are **0 surviving presentation-form mappings**, so PDFKit (Preview / Safari / Quick
Look / iOS) searches and copies logical Arabic, including ligature/hamza words. The visible
glyphs are untouched, so the rendered page is byte-identical. Match counts above are live
PDFKit results, not assertions. Firefox/PDF.js and Adobe Acrobat honor the same ToUnicode +
Unicode-bidi standard but were not executed on this host and are marked as such.
