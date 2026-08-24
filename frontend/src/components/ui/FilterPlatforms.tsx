import { Check } from 'lucide-react'
import { platformColor } from '@/features/analytics/components'

/**
 * UX-FILTERS-001 — the platforms, VISIBLE, as the thing they are.
 *
 * This was a `FilterMulti`: a button labelled «المنصة» that opened a popover of six checkboxes. Six
 * is not a long list, and hiding six items behind a click costs the operator the one thing the bar
 * exists to give them — knowing, at a glance, what they are currently looking at. A dashboard where
 * «is Snapchat included?» takes a click to answer is a dashboard that gets read wrong.
 *
 * So the six are on the surface, each carrying its own brand colour. Colour is not decoration here:
 * the same six colours key every chart on the page, so the chip a person switches off and the arc
 * that disappears from the donut are recognisably the same thing.
 *
 * ## Selection semantics
 *
 * Empty means ALL — the same contract `FilterMulti` had, and the same one the API expects, so
 * nothing downstream changes. That is stated on screen rather than left to be inferred: the «الكل»
 * chip is active exactly when nothing is selected, and pressing it clears rather than selects.
 *
 * `aria-pressed` on every chip, because these are toggles and not links. A screen reader gets the
 * same fact the colour gives everyone else.
 */
export function FilterPlatforms({
  label,
  allLabel,
  values,
  options,
  onChange,
  testid,
}: {
  label: string
  allLabel: string
  values: string[]
  options: Array<{ value: string; label: string }>
  onChange: (next: string[]) => void
  testid?: string
}) {
  const toggle = (value: string) =>
    onChange(values.includes(value) ? values.filter((v) => v !== value) : [...values, value])

  const all = values.length === 0

  return (
    <div className="flex flex-wrap items-center gap-1.5" data-testid={testid}>
      <span className="me-0.5 text-xs font-bold text-text-muted">{label}</span>

      <button
        type="button"
        aria-pressed={all}
        data-testid={testid ? `${testid}-all` : undefined}
        onClick={() => onChange([])}
        className={`rounded-full border px-2.5 py-1 text-xs font-bold transition-colors ${
          all
            ? 'border-brand-500 bg-brand-primary-soft text-brand-700'
            : 'border-border bg-surface text-text-secondary hover:border-brand-300 hover:bg-surface-hover'
        }`}
      >
        {allLabel}
      </button>

      {options.map((opt) => {
        const on = values.includes(opt.value)
        const color = platformColor(opt.value)

        return (
          <button
            key={opt.value}
            type="button"
            aria-pressed={on}
            data-testid={testid ? `${testid}-${opt.value}` : undefined}
            onClick={() => toggle(opt.value)}
            className={`flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-bold transition-colors ${
              on
                ? 'border-transparent text-white'
                : 'border-border bg-surface text-text-secondary hover:border-brand-300 hover:bg-surface-hover'
            }`}
            /*
             * The brand colour fills the chip when selected and marks it with a dot when not. An
             * unselected chip keeps the page's own greys — six saturated pills side by side read as
             * six alerts, and none of them is one.
             */
            style={on ? { backgroundColor: color } : undefined}
          >
            {on ? (
              <Check size={11} strokeWidth={3.5} aria-hidden />
            ) : (
              <span className="h-2 w-2 shrink-0 rounded-full" style={{ backgroundColor: color }} aria-hidden />
            )}
            {opt.label}
          </button>
        )
      })}
    </div>
  )
}
