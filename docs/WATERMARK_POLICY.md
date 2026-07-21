# Watermark Policy

Component: `frontend/src/components/Watermark.tsx`. Wrap sensitive content to overlay a faint, tiled
watermark + a corner "Confidential" chip.

## Content
Viewer name + **masked** email + timestamp (+ optional label). Timestamp refreshes over time so a
capture is stamped near when it was taken. Non-obstructive (low opacity, `pointer-events:none`).

## Where to apply
Reports, media plans, strategies, AI output, client data, competitor pages, budgets, creative
library, knowledge base, private files. Preview of shared reports and PDFs (per policy) when built.

## Policy toggles (design)
Per-tenant enable/disable; per-page enable; include-in-PDF; include-in-shared-link. Pair with
export permissions and signed/expiring share links.

## Honesty (mandatory)
This is **deterrence + attribution**, NOT screenshot prevention. A browser cannot stop cameras or OS
capture. We never claim "Screenshot Protection Guaranteed". See `CONTENT_PROTECTION.md` for the full
statement of what can and cannot be prevented.
