# Content Protection

CampaignsHub protects sensitive content (reports, media plans, strategies, AI output, client data,
competitor pages, budgets, creative library, knowledge base, private files) with **layered
deterrence, attribution, and access logging** — not with impossible guarantees.

## What CANNOT be prevented (stated plainly)
A web application running in a browser **cannot** stop:
- A photo taken with a phone or external camera.
- Operating-system screen capture / screen recording.
- Browser extensions or devtools a determined user controls on their own machine.

Therefore CampaignsHub **does not** offer, and must never advertise, a feature such as
"Screenshot Protection Guaranteed" or any claim that a user cannot capture the screen. Any UI-level
disabling of print, right-click, text selection, or shortcuts is **deterrence only** and is
documented as such in the product.

## What IS implemented / supported
### Dynamic watermark (`frontend/src/components/Watermark.tsx`)
Tiles the viewer's identity + masked email + timestamp (and an optional label) faintly across
sensitive pages, plus a corner "Confidential" chip. It refreshes over time. Purpose: any leaked
capture is **traceable to the person who viewed it**. Non-obstructive to reading; configurable per
tenant/page.

### Deterrence layers (opt-in policies)
- Hide sensitive values / mask API identifiers.
- Disable context menu / text selection in specific regions (deterrence).
- Discourage print via UI (deterrence) + print-hidden watermark policy.
- View-only reports; export gated by permission.

### Attribution & access control (real)
- Expiring signed share links; download restrictions; export permissions.
- Session timeout; device/session revocation; optional IP restrictions (Enterprise).
- Access logging: who opened a page/report, who exported, who created/copied a share link — with
  IP, device, time, project, and client. (Backend audit log already records connect/approval/AI/
  export-style events; per-view logging is extended per sensitive surface.)
- Visible confidentiality notice.

### Server-side protection of IP
- Business logic (optimization, attribution, sensitive algorithms) stays in the **backend**, never
  shipped in the React bundle.
- No sensitive source maps in production; private API docs not public; rate limiting; authorization;
  feature flags; usage licences.

## What we DO NOT do
- We do not log keystrokes or collect unnecessary spyware-style telemetry.
- We do not claim compliance certifications we have not obtained.

## Summary
Protection here = **watermarking + deterrence + strict permissions + signed/expiring sharing +
access logging + server-side secrecy**. It raises the cost and traces the source of a leak. It does
not, and cannot, make screen capture impossible — and we say so.
