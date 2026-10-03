import { Check } from 'lucide-react'
import { platformColor } from '@/features/analytics/components'
import { PlatformMark, hasPlatformMark } from '@/components/brand/PlatformMark'

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
 *
 * ## `marks` — the same chips, drawn as the platforms' own logos
 *
 * Seven names is the widest control on a dashboard bar, and the one a reader recognises fastest from
 * its logo. With `marks` the chip carries the platform's mark instead of its name; everything else
 * is unchanged, including the selection contract.
 *
 * The name does not disappear — it moves to `aria-label` and `title`, so a screen reader hears it
 * and a pointer reveals it. A control identified only by a shape is unusable to somebody who cannot
 * see the shape, and unreadable to anybody who does not already know the brand. A platform with no
 * mark keeps its name on the chip rather than becoming a blank pill.
 */
export function FilterPlatforms({
  label,
  allLabel,
  values,
  options,
  onChange,
  testid,
  marks = false,
}: {
  label: string
  allLabel: string
  values: string[]
  options: Array<{ value: string; label: string }>
  onChange: (next: string[]) => void
  testid?: string
  /** Draw each platform as its own logo rather than its name — see the note above. */
  marks?: boolean
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

        const asMark = marks && hasPlatformMark(opt.value)

        return (
          <button
            key={opt.value}
            type="button"
            aria-pressed={on}
            /*
             * The name, always, for anybody not reading the logo. `title` is the pointer's version of
             * the same fact — a mark nobody recognises is a control nobody can use.
             */
            aria-label={asMark ? opt.label : undefined}
            title={asMark ? opt.label : undefined}
            data-testid={testid ? `${testid}-${opt.value}` : undefined}
            onClick={() => toggle(opt.value)}
            className={`flex items-center justify-center gap-1.5 rounded-full border text-xs font-bold transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 ${
              asMark ? 'h-7 w-7 p-0' : 'px-2.5 py-1'
            } ${
              on
                ? 'border-transparent text-white'
                : 'border-border bg-surface text-text-secondary hover:border-brand-300 hover:bg-surface-hover'
            }`}
            /*
             * The brand colour fills the chip when selected and marks it with a dot when not. An
             * unselected chip keeps the page's own greys — seven saturated pills side by side read as
             * seven alerts, and none of them is one.
             *
             * A mark chip is the same rule with the mark itself carrying the colour when unselected:
             * white on the brand colour when on, the brand colour on the page's surface when off, so
             * the state is legible without reading anything.
             */
            style={on ? { backgroundColor: color } : undefined}
          >
            {asMark ? (
              <span style={on ? undefined : { color }} className="flex">
                <PlatformMark platform={opt.value} size={14} />
              </span>
            ) : on ? (
              <Check size={11} strokeWidth={3.5} aria-hidden />
            ) : (
              <span className="h-2 w-2 shrink-0 rounded-full" style={{ backgroundColor: color }} aria-hidden />
            )}
            {!asMark && opt.label}
          </button>
        )
      })}
    </div>
  )
}
