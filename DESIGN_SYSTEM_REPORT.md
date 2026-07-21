# Design System Report

Source of truth: `frontend/src/styles/tokens.css` (implementation) and `docs/design-tokens.md`
(reference). A live showcase renders at `/design` in the app (dev).

## Foundations
- **Tokens**: full brand (green) scale, semantic colors (success/warning/danger/info/purple/teal),
  surfaces, borders, text hierarchy, status backgrounds, shadows, radii — for **light and dark**.
- **Theming**: `data-theme` on `<html>`; every component styled for both modes. Verified live.
- **Direction**: RTL (Arabic) / LTR (English) via `dir`; logical CSS properties so layout mirrors;
  active-nav indicator flips side. Numbers/ids render Latin + tabular (`.tnum`). Verified live.
- **Typography**: IBM Plex Sans Arabic (body), Plus Jakarta Sans (headings), JetBrains Mono (numbers).
- **Motion**: `prefers-reduced-motion` respected.

## Components implemented
Button (primary/secondary/ghost/danger/loading), Card (+Title/Description), Badge (icon+text, not
color-only), Field + Input + Textarea + Select + Checkbox + Switch, DataTable (search/sort/
pagination/sticky/loading/empty/error), Modal (focus-trap + Escape + click-outside), Tabs, Alert
(severity), and states: Skeleton / EmptyState / ErrorState / NoPermission.

App Shell: 250px sidebar, sticky topbar, 1240px max content, theme+locale toggles, user + logout.

## Accessibility posture
Built to WCAG 2.1 AA intent: visible focus rings, `aria-*`, labels, focus trap in modal, keyboard
operability, status conveyed by icon+text (never color alone), touch-target sizing, reduced motion.
**Not yet machine-verified** (no axe/Playwright a11y suite) — see `KNOWN_LIMITATIONS.md`.

## Components still to build
Drawer, Toast provider, DatePicker, DateRangePicker, Tooltip, CommandPalette (⌘K), FileUploader,
FilterBar, Pagination (standalone), KpiCard/MetricCard/ChartCard/IntegrationCard variants, Charts
(Recharts/ECharts with the specified palette), mobile card fallback for tables.

## Evidence
Live screenshots captured during the build show: components in Arabic RTL (light) and the DataTable
in English (dark), the CRM Leads screen, and the Integrations status board — all token-driven with
zero console errors.
