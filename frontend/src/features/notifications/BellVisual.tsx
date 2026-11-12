import type { BellState, BellVisualData } from './api'

/**
 * DIGESTS-CADENCE-BUDGETS-001 — the bell draws an alert the way its email does.
 *
 * The Owner approved the digest grammar for every alert and notification: a status chip and a coloured edge (the row's rail),
 * the problem DRAWN, one short line, and the next steps in order with arrows. The row already had the rail and the line; this
 * adds the drawing and the steps from `visual`, which the server builds from the alert event's own figures — the same
 * `AlertVisual` the email uses, so the bell and the inbox cannot draw one alert two ways. Nothing is drawn without data.
 */
const FILL: Record<BellState, string> = { ok: 'bg-success', watch: 'bg-warning', bad: 'bg-danger', none: 'bg-border' }
const CHIP: Record<BellState, string> = {
  ok: 'bg-success/15 text-success',
  watch: 'bg-warning/15 text-warning',
  bad: 'bg-danger/15 text-danger',
  none: 'bg-surface-secondary text-text-muted',
}
const GLYPH: Record<BellState, string> = { ok: '✓', watch: '!', bad: '✕', none: '–' }

function Bar({ label, pct, tone }: { label: string; pct: number; tone: string }) {
  return (
    <span className="flex items-center gap-1.5">
      <span className="w-20 shrink-0 truncate text-xs text-text-muted">{label}</span>
      <span dir="ltr" className="h-1.5 flex-1 rounded bg-surface-secondary">
        <span className={`block h-1.5 rounded ${tone}`} style={{ width: `${Math.max(4, Math.min(100, pct))}%` }} />
      </span>
    </span>
  )
}

export function BellVisual({ visual, ar }: { visual?: BellVisualData | null; ar: boolean }) {
  if (!visual || (!visual.viz && visual.steps.length === 0)) return null
  const { viz } = visual
  const arrow = ar ? '←' : '→'

  return (
    <span data-testid="bell-visual" data-viz={viz?.type ?? 'none'} className="mt-1 flex flex-col gap-1">
      {viz?.type === 'before_now' && (
        <>
          <Bar label={`${ar ? 'قبل' : 'Before'} ${viz.before_label}`} pct={viz.before} tone="bg-border" />
          <Bar label={`${ar ? 'الآن' : 'Now'} ${viz.now_label}`} pct={viz.now} tone="bg-danger" />
        </>
      )}
      {viz?.type === 'pace' && (
        <>
          <Bar label={viz.plan_label} pct={viz.plan} tone="bg-border" />
          <Bar label={viz.used_label} pct={viz.used} tone={viz.used > viz.plan ? 'bg-danger' : 'bg-warning'} />
        </>
      )}
      {viz?.type === 'steps' && (
        <span className="flex flex-wrap items-center gap-1">
          {viz.steps.map((s, i) => (
            <span key={`${s.label}-${i}`} className="flex items-center gap-1">
              {i > 0 && <span className="text-xs text-text-muted">{arrow}</span>}
              <span data-step-state={s.state} className={`rounded-full px-1.5 py-0.5 text-xs font-bold ${CHIP[s.state]}`}>
                {GLYPH[s.state]} {s.label}
              </span>
            </span>
          ))}
        </span>
      )}
      {viz?.type === 'count' && (
        <span className="flex items-center gap-0.5">
          {Array.from({ length: viz.blocks }, (_, i) => (
            <span key={i} className={`h-2 w-2 rounded-sm ${FILL[viz.state]}`} />
          ))}
          <span className="ms-1 text-xs font-bold text-text-secondary"><span dir="ltr" className="tnum">{viz.more ? '+' : ''}{viz.n}</span> {viz.label}</span>
        </span>
      )}
      {visual.steps.length > 0 && (
        <span data-testid="bell-steps" className="flex flex-wrap items-center gap-1 text-xs text-text-secondary">
          {visual.steps.map((s, i) => (
            <span key={`${s}-${i}`} className="flex items-center gap-1">
              {i > 0 && <span className="text-text-muted">{arrow}</span>}
              <span className="rounded border border-border px-1 py-px"><span dir="ltr" className="tnum font-bold">{i + 1}</span> {s}</span>
            </span>
          ))}
        </span>
      )}
    </span>
  )
}
