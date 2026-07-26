# Architecture & Product Decisions (assumptions log)

Autonomous decisions taken while executing the platform directives. Each is reversible; flag any to change.

## Auth & Onboarding (feat/auth-premium)
- **Premium split-layout auth** shared via `AuthShell` (marketing panel + form) across login/register/
  forgot-password — one component, consistent RTL/LTR + light/dark + toggles.
- **Demo credentials are pre-filled ONLY in `import.meta.env.DEV`** — never in production builds.
- **Register** uses the existing `POST /auth/register` (provisions tenant + owner). Post-register it
  currently lands on `/` (dashboard); the **onboarding / module-selection** step is Phase 2 and will
  be inserted before the dashboard.
- **Forgot-password**: `POST /auth/forgot-password` responds with a single generic message for both
  known and unknown emails (**no account enumeration**). Actual email delivery is **Awaiting
  Credentials** (no mailer configured) — the endpoint logs intent and returns success so the UI flow
  is complete end-to-end. Wire `Password::sendResetLink` + a `password_reset_tokens` table once mail
  is available.
- **Google login**: deferred to a feature flag — needs Google OAuth client credentials (Awaiting
  Credentials). The button is shown only when the flag is enabled.

## Shared-Core readiness (for future InfluencerHub merge)
- Keep Identity / Organizations / Clients / Projects / Requests / Team / Notifications / Files / Audit
  / Settings as the SHARED CORE; Paid Media is a module. Do not duplicate these per module.
- Module entitlements via permissions (`paid_media.access`, `influencers.access`, `requests.*`).

## Reports
- Objective selection is BACKEND-driven (`ReportTemplateEngine::objectiveTemplate`) — the single source
  of truth for primary/secondary/hidden KPIs, charts, funnel, and creative rank metric per objective.
