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
- Module entitlements via permissions (`paid_media.access`, `influencer_marketing.access`, `requests.*`).
  See the official terminology glossary below — these slugs are canonical.

## Reports
- Objective selection is BACKEND-driven (`ReportTemplateEngine::objectiveTemplate`) — the single source
  of truth for primary/secondary/hidden KPIs, charts, funnel, and creative rank metric per objective.

## Color identity — original, NOT the reference images (2026-07-27)
The reference screenshots were used only for QUALITY + layout, never colors. CampaignsHub keeps its
OWN identity — deliberately distinct from InfluencerHub's purple/blue:
- **Primary: Emerald/Teal green** (existing `--brand`) — reads as performance/analytics, not social.
- **Marketing / auth panel: Deep Graphite** gradient, now tokenised as `--auth-panel-from/via/to`
  (`#0b1116 → #0e1a1c`, a whisper of teal, **no indigo/purple**) with two soft glows both in the
  emerald/teal family — premium and calm, no heavy glassmorphism.
- **States: fixed** — success=emerald, warning/opportunity=warm amber, danger=red, info=restrained cyan.
- **Neutral grays** for surfaces/borders; subtle gradients only.

### Finalised 2026-07-27 — purple/green conflict eliminated on `/login`
Three directions were weighed internally — **1) Emerald + Graphite**, 2) Teal + Charcoal, 3) Forest
Green + Slate. **Emerald + Graphite adopted**: best WCAG-AA contrast on the dark panel, clearly
non-InfluencerHub, and it matches the existing `--brand` tokens so there is no app-wide recolor churn.
Concrete changes:
- Removed the purple glow orb from `AuthShell` (the only purple-vs-green clash on the login page);
  replaced with an emerald + teal glow pair.
- Purple (`--purple #7c3aed`) is now **data-viz only** (the "conversions" chart series) — a rare
  functional accent, never part of the brand identity, never on auth surfaces.
- Added central semantic tokens: `brand-primary/-hover/-soft`, `surface-primary/-elevated`,
  `border-default`, `brand-300` — components reference these, not raw hex.
- Login button + focus ring are emerald (`brand-600` / `brand-500`), never purple.
- Verified live in the Browser pane: light + dark, desktop split-layout + mobile form-first,
  RTL, no horizontal overflow (320/375), no console errors. Demo credentials moved to a separate
  dev-only card with copy-email / copy-password buttons (never auto-filled).

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

## Official terminology — adopted 2026-07-27 (binding across UI, docs, forms, DB)
Single canonical name for the primary module — never mix synonyms on one page.
- **Primary (official):** `إدارة الحملات الإعلانية المدفوعة` / **Paid Advertising Management**
- **Short (cards/lists/nav):** `الحملات المدفوعة` / **Paid Advertising**
- **Marketing-only synonym (supporting copy, sparing):** `الحملات الممولة` — familiar to clients, NOT the module name.
- **Do NOT scatter** within a single page: الحملات الرقمية / الحملات الممولة / الحملات المدفوعة / الإعلانات الممولة.

Future / second module: `إدارة حملات المؤثرين ومحتوى UGC` / **Influencer & UGC Campaign Management**.
Combined offering: `إدارة متكاملة للحملات المدفوعة والمؤثرين` / **Integrated Paid Advertising & Influencer Management**.

Internal enum values (DB + entitlements): `paid_media`, `influencer_marketing`, `combined`.
Permission slugs: `paid_media.access`, `influencer_marketing.access`.

First application: auth marketing copy (`i18n.ts` auth_hero_eyebrow/title/subtitle, sign_in/create_account
subtitles) now leads with the official term. Nav/marketing/onboarding/requests must reuse these exact strings.

## Visual refinement — homepage + login rebalance (2026-07-27)
Reference images used ONLY for balance/hierarchy/type-scale, never colors (Emerald-on-Graphite kept).
- Homepage hero rebalanced to a wide value+preview column beside a ~360px journey card (equal height,
  verified 706==706px); the interactive preview is the primary visual; added a workflow strip; H1 60px.
- Login marketing panel widened to ~57% with a big title and FOUR described value props; fields → 56px;
  demo card moved below the form. Commits 4756b17 (marketing) + 7c54bc7 (auth).
- Prior visual baselines were discarded (old design no longer accepted) and regenerated for both surfaces.
