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
