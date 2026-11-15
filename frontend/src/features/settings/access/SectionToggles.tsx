import { Check, Minus } from 'lucide-react'
import type { Area, SectionStates } from './accessApi'

/**
 * ACCESS-SECTIONS-001 — «الأقسام»: the seven sections, one switch each, pre-filled with where the person stands
 * now. Off = hidden from their navigation and refused by the API on their next request. The server re-checks every
 * switch (grant only what you hold), so a refused «on» comes back as a message, never as a silent widening.
 */
export function SectionToggles({ sections, states, base, onChange, ar, testId = 'section-toggles' }: {
  sections: Area[]
  states: SectionStates
  base?: SectionStates
  onChange: (key: string, on: boolean) => void
  ar: boolean
  testId?: string
}) {
  const on = sections.filter((s) => states[s.key]).length
  return (
    <div data-testid={testId}>
      <div className="mb-1.5 text-xs text-text-muted">
        {ar ? 'يظهر له' : 'Can open'} <span className="tnum font-bold text-text-primary">{on.toLocaleString('en-US')}</span> {ar ? 'من' : 'of'} <span className="tnum">{sections.length.toLocaleString('en-US')}</span>
      </div>
      <ul className="flex flex-wrap gap-1.5">
        {sections.map((s) => {
          const value = states[s.key] ?? false
          const changed = base !== undefined && (base[s.key] ?? false) !== value
          return (
            <li key={s.key}>
              <button type="button" role="switch" aria-checked={value} data-testid={`section-${s.key}`} onClick={() => onChange(s.key, !value)}
                className={`inline-flex items-center gap-1 rounded-full border px-3 py-1 text-xs font-semibold ${value ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-border bg-surface-secondary text-text-muted line-through'} ${changed ? 'ring-2 ring-warning/50' : ''}`}>
                {value ? <Check size={12} aria-hidden /> : <Minus size={12} aria-hidden />}
                {ar ? s.ar : s.en}
              </button>
            </li>
          )
        })}
      </ul>
    </div>
  )
}
