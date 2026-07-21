# Design Tokens

The single source of truth for colors, typography, radius, and elevation. Implemented as CSS
custom properties in `frontend/src/styles/tokens.css` and exposed to Tailwind via
`tailwind.config.ts`. **Never hard-code a color** — always reference a token.

## Themes
Two themes: light (default) and dark (`:root[data-theme="dark"]`). Every component must render
correctly in both. The theme is toggled by setting `data-theme` on `<html>`.

## Direction
- Arabic → `dir="rtl"`, English → `dir="ltr"` (set on `<html>` by the i18n layer).
- Use logical CSS properties (`margin-inline`, `padding-inline`, `border-inline-*`) so layouts
  mirror automatically. The active-nav indicator flips side with direction.
- **Numbers, dates, IDs, and currency always render in Latin digits**, even in Arabic UI.

## Color groups
| Token group | Purpose |
|---|---|
| `--brand-*` (50–700) | Primary brand green; `--brand-600` for primary buttons |
| `--success/--warning/--danger/--info/--purple/--teal` | Semantic status colors |
| `--background/--surface/--surface-secondary` | Page and card backgrounds |
| `--border/--border-strong/--ring` | Borders and focus rings |
| `--text-primary/--text-secondary/--text-muted` | Text hierarchy |
| `--*-background` | Tinted status backgrounds (positive/negative/warning/info/purple/brand) |
| `--shadow-small/medium/large` | Elevation |

## Typography
- Body (Arabic-first): `"IBM Plex Sans Arabic", system-ui, sans-serif`.
- Headings / prominent values: `"Plus Jakarta Sans", "IBM Plex Sans Arabic", sans-serif`.
- Numbers / dates / IDs: `"JetBrains Mono", monospace` with `font-variant-numeric: tabular-nums`.

## Radius
`--radius-extra-small: 6px` … `--radius-large: 12px` … `--radius-modal: 16px` …
`--radius-pill: 999px`.

## Accessibility
Contrast ≥ WCAG 2.1 AA. Status is never conveyed by color alone (pair with icon/label). Visible
focus states on all interactive elements; respect `prefers-reduced-motion`.

> The full palette values live in `frontend/src/styles/tokens.css`. This document is the reference;
> the CSS file is the implementation.
