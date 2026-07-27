# Next Steps (rolling)

## In progress
- **Phase 1 — Premium Auth** ✅ login/register/forgot on `feat/auth-premium` (split layout, RTL/LTR,
  light/dark, show/hide password, remember me, dev-only demo prefill; forgot = generic no-enumeration).

## Immediate queue (autonomous)
1. **Phase 2 — Public homepage + Onboarding + Module selection** (journey/account-type/module choice,
   org profile, first client/project, invite team, pick data sources).
2. **Phase 3 — External Requests intake portal** (`/requests/new` dynamic forms, attachments,
   confirmation, `/requests/track/{token}`) + request tables (request_types/categories/…/access_tokens).
3. **Phase 4 — Internal Requests dashboard** (Kanban/Table/Cards, statuses, assignment, SLA, comments,
   files, notifications) + `requests.*` permissions + policies.
4. **Phase 5 — Convert request → client / project / campaign** (no re-entry of data).
5. **Phase 6 — Shared-core readiness** (module switcher, unified design tokens, shared permissions).
6. **Phase 7 — Mobile/PWA, accessibility, cross-browser, security, performance.**
7. **Phase 8 — Full audit, live preview, docs, delivery package.**

## Awaiting Credentials (blocked on external)
- Mail delivery (password reset, request confirmation emails).
- Google OAuth client (Google login).
- Real ad-platform API keys (Snapchat/TikTok/Meta/Google/etc.) — connectors stay `Awaiting Credentials`.

## PDF open items (documented, non-blocking)
- English bold-heading text-layer extraction (Chromium Latin subset quirk).
- Firefox PDF.js + Adobe Acrobat verification (no runtime on host).

---

## Auth visual unification — DONE (2026-07-27, commit 1f5e118)
Acceptance met & live-verified: unified Emerald-on-Graphite, no purple/green conflict, wide fields,
mobile form-first, RTL, light/dark, no overflow (375), no console errors, demo card with copy buttons.
**Remaining auth acceptance (honest):** Firefox + WebKit projects and keyboard-nav/visual-regression
runs are not yet executed (Playwright config is chromium-only) — tracked, not claimed as passed.

## Next queued phase — User profile menu + account settings (new directive)
Unified user menu (topbar avatar + sidebar user card open the SAME menu; full name, full email, role,
workspace, status). Real routes + backend:
- `/settings/profile` (name/avatar/title/phone/locale/timezone/formats/bio) — PATCH /api/me/profile, POST /api/me/avatar
- `/settings/email` (secure change flow — Awaiting Mail Credentials until mail configured)
- `/settings/password` (current+new+confirm, strength meter, revoke other sessions) — PATCH /api/me/password
- `/settings/security` (MFA, recovery codes, active sessions list, revoke one/all, security event log)
- `/settings/preferences` (lang/theme/timezone/currency/week-start/date-format/default page+project/density/campaign view)
- `/settings/notifications` (channels + per-type toggles, quiet hours, test notification, delivery log)
Separate USER settings from WORKSPACE settings (org name/logo/currency/team/roles/modules/plan/sources/report identity — owner only).
Sidebar user card: no cryptic email truncation, tooltip on narrow, role as 2nd line, whole card clickable,
logout is the last item (calm danger). Topbar: unified icon sizes, tooltips, unread count.
Endpoints enforce self-only + tenant isolation + rate limiting (password/email) + audit + no token/hash leakage.
Full E2E: Login → open menu → profile → change name → save → refresh → verify in topbar+sidebar → change password → sessions → logout.

## Then continue (unchanged): Registration/Onboarding → Public Homepage → Requests (classification+dashboard)
→ Clients (classification + command center) → Request→Client/Project/Campaign conversion → Module entitlements
→ simplified Company tenant nav → QA/PWA → final audit + delivery.
