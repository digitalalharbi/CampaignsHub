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

## Color identity — original, NOT the reference images (2026-07-27)
The reference screenshots were used only for QUALITY + layout, never colors. CampaignsHub keeps its
OWN identity — deliberately distinct from InfluencerHub's purple/blue:
- **Primary: Emerald/Teal green** (existing `--brand`) — reads as performance/analytics, not social.
- **Marketing / auth panel: Deep Graphite / Midnight** gradient (`#0b1020 → #141a33`) with restrained
  emerald glow — premium and calm, no heavy glassmorphism.
- **States: fixed** — success=emerald, warning/opportunity=warm amber, danger=red, info=restrained cyan.
- **Neutral grays** for surfaces/borders; subtle gradients only.
Three directions were weighed internally (Emerald-on-Graphite / Teal-on-Slate / Cyan-accent); the
**Emerald-on-Graphite** direction was adopted — best contrast (WCAG AA), clearly non-InfluencerHub, and
it matches the existing token system so no app-wide recolor churn. Light + dark both first-class.

## Form design system (2026-07-27)
Central `components/ui/form.tsx` (FormField / TextInput / EmailInput / PasswordInput / TextareaField
with char counter / FormSection / FormActions / FormErrorSummary / RadioCardGroup) + refined shared
`controlClass`: wide 100% fields, ~54px tall, 16px text (prevents iOS zoom), labels ABOVE, balanced
11px radius (never pill), calm borders, clear focus/error. No in-field ornamentation, no
placeholder-as-label. Applied to all auth pages; the standard for onboarding / requests / builders.
Demo credentials show in a separate dev-only copyable box, never auto-filled into fields.

## Workspace modes (2026-07-27) — future rentability without a second system
- `Personal Workspace` (current default) vs `Company Tenant` (future rental) resolved by a
  Navigation Resolver over account_type + enabled_modules + role + permissions + subscription_plan.
  A single-module account skips the module switcher and enters CampaignsHub directly. One codebase;
  tenant isolation + module entitlements + feature flags + plan limits — never a forked build.
