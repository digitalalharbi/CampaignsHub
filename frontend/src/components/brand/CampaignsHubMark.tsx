/**
 * BRAND-MARK-001 — the CampaignsHub symbol, and the only copy of its geometry in the product.
 *
 * Three paths converging on one point, with the gold dot at the junction: what the identity calls
 * «ثلاث مسارات تندمج في نقطة واحدة». It is drawn here once so that a second, slightly different
 * mark cannot appear later in a sidebar, a report header or an icon file — which is exactly the
 * drift the identity document warns about and the reason this component exists at all.
 *
 * Colour is NOT baked in. The strokes inherit `currentColor`, so the same file serves light and
 * dark by having its container pass a colour: `--brand-600` (#0d8a6f, the product's own approved
 * green, which is the value the identity file states) in light, and the near-white #f7f5f0 in dark.
 * The gold dot is fixed in both, because it is the one element the identity pins.
 */
export function CampaignsHubMark({
  size = 32,
  className = '',
  title,
}: {
  size?: number
  className?: string
  /**
   * An accessible name, when this mark stands alone. Inside the full lockup the wordmark beside it
   * is already the name, so the mark is marked decorative instead of announcing «CampaignsHub»
   * twice to a screen reader.
   */
  title?: string
}) {
  return (
    <svg
      viewBox="0 0 64 64"
      width={size}
      height={size}
      className={className}
      role={title ? 'img' : undefined}
      aria-label={title}
      aria-hidden={title ? undefined : true}
      focusable="false"
    >
      <g fill="none" stroke="currentColor" strokeWidth="6" strokeLinecap="round">
        <path d="M8 14 H24 C34 14 34 32 42 32" />
        <path d="M8 32 H42" />
        <path d="M8 50 H24 C34 50 34 32 42 32" />
      </g>
      {/* Fixed in both themes — the identity states the gold dot does not change. */}
      <circle cx="52" cy="32" r="7" fill="var(--brand-gold, #e8a33d)" />
    </svg>
  )
}
