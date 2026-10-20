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

### Link reference (IP-TRACE-001) — traceable without a person in it
Every shared report link is born with an opaque, random, unique reference (`REF XXXXX-XXXXX`). It names a
**link**, never a recipient: the system does not know who a link was forwarded to, so nothing about a person
can appear in it. It is drawn small (8px, faint, `aria-hidden`) in a corner of every page of a watermarked
link and of its PDF, and written into the manifest of the CSV and the spreadsheet built from the link (the
«Reference / المرجع» row). The PDF's reference is minted by the server into the print token, never read from
the URL. The Owner who issued the link asks which link a copy came from (`GET /projects/{p}/report-links/trace/{ref}`,
permission `reports.share`, scoped to the project and tenant) and gets the link, its report, its issuer, whether
it is still live and how it was opened — enough to ask the right person, revoke it or reissue. It is a trace,
not a claim that a copy cannot be taken.

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

## Audits that keep it honest (IP-AUDIT-001)
- **Browser bundle.** A test reads every shipped source file as a stranger would: no credential-shaped string,
  no private key, no `VITE_` variable that is really a secret (Vite inlines every one), no server-only client
  imported, and no production source maps in the build configuration.
- **Public API surface.** `PublicSurfaceInventoryTest` records every `/api` route that has no authentication
  middleware; a new one fails the suite until it is added with a reason, and no operator-workspace prefix may
  appear on it.
- **Link scope.** A shared link is looked up through its report on every owner action (revoke, renew, edit,
  analytics, access log); presenting another report's link id under this report's address is a 404.
- **Server-side only.** Provider credentials, signing secrets and the rules that decide, optimise or attribute
  stay in the backend; the browser receives results, never the credential or the algorithm.
