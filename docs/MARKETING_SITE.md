# Marketing Site

Public landing at `/welcome` (`frontend/src/features/marketing/MarketingPage.tsx`). Brand-driven
(`src/lib/brand.ts`), RTL/LTR, light/dark, token-based. Outside the authenticated app (no auth gate).

## Sections (built)
Nav (brand + language/theme + sign-in/get-started) · Hero (badge, headline = brand tagline, dual
CTA) · Problem · Solution (numbered journey) · Features (8: projects, integrations, content &
approvals, tracking & reporting, notifications & tasks, client portals, AI BYOK, billing) · Pricing
(Trial/Starter/Professional/Agency/Enterprise — "managed from platform admin", not hard-coded) ·
Security (with explicit note that screen capture is not fully preventable) · FAQ · Final CTA ·
Footer.

## SEO / metadata
`index.html` sets `<title>`, description, Open Graph, schema.org SoftwareApplication, PWA manifest,
theme-color.

## Notes / planned
For strong SSR/SEO the brief allows a separate Next.js marketing app; current implementation is a
Vite SPA route (indexable via SSR/prerender at deploy if needed). Pricing values are placeholders
pending the Billing/plans admin (Phase 9). Request-demo / contact-sales currently link to sign-up.
