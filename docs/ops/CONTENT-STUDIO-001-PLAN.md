# CONTENT-STUDIO-001 — the content library as one studio: plan and batch ledger

Source order: `CONTENT-STUDIO-001-v3.md` (Owner, 2026-11-21) plus the same day's note: one library that uploads, scores, creates,
edits, downloads, sends to campaigns and promotes; Meta-grade production; creative detail in one place; automatic creation and
editing with on/off switches; no «display only» pages.

It is built as an **extension of the intake path inside «المحتويات»**, on what exists: `features/content/intake/*`,
`Domains/ContentIntake/*` (DeterministicAnalyzer, AiProviderAnalyzer, Drive sync, approvals), `contentScore.ts`,
`creativeVerdict.ts`, `standing.ts`, `CreativeFatigue.php`, `CreativeMetrics.php`.

Zero platform cost: cropping, analysis, layers, motion and export run in the browser; the server stores the result and renders
nothing. AI generation uses the tenant's own key and is hidden without one. No platform AI enhancement is switched on at upload.

## Batches (each small, additive, verified before the next)

| # | Batch | State |
|---|---|---|
| 1 | The rules, as pure code with tests: safe margins (10% top, 20% bottom, 6% sides; inner 6% for 4:5/1:1/1.91:1), copy limits (hook ≤ 6, message ≤ 10, CTA ≤ 3, no hashtag/emoji/forbidden word), the 12s/15s Hook-Message-CTA timeline, and the hard readiness checks that block saving | **this PR** |
| 2 | Image analysis in the browser: product box, 12×20 calm-zone grid, per-zone light/dark, 5-colour palette, quality (resolution, blur, exposure) | **merged #1238** (own saliency, no smartcrop.js) |
| 3 | Design tokens: Brand Kit schema + derivation from the image, contrast lint (WCAG ≥ 4.5), one font pair, ≤ 3 layer colours | **merged #1241** (fonts named, files not yet hosted) |
| 4 | Layout families as zod-validated JSON (seven), placement from the calm-zone map, diversity constraint | next |
| 5 | Motion recipes and the Canvas previewer (no encoding until Save) with the margin overlay | |
| 6 | Export: WebCodecs + mp4-muxer in a Web Worker, 1080×1920 30fps H.264/AAC, ≤ 15s | |
| 7 | Studio drawer inside the library: auto-generate on upload, batch of 3 videos + 4 images, save/send-to-approval | |
| 8 | Migrations: brand_kits, design_tokens, studio_sessions/outputs, layout_families, music_tracks, creative_concepts, readiness_scores | |
| 9 | Readiness score and the post-launch link (file_hash → ads, quality/engagement/conversion rankings, hook and hold rate, learning loop) | |
| 10 | Tenant-key AI: copy, image enhancement, TTS, outpaint, generated video; hidden without a key | |
| 11 | The library restructure itself: one classification, create/edit/score/download/upload-to-campaign in one place | |

## What cannot be claimed yet

Nothing here is `LIVE_VERIFIED`. Batch 1 is logic only; no pixel has been rendered. The visual snapshots, the 200-image property test,
the 3-videos-plus-4-images live batch and the browser export timings are batches 5–7 and are not done.
