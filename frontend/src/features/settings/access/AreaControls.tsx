import { Check, Eye, Minus } from 'lucide-react'
import { canGrant, LEVELS, type Area, type Level } from './accessApi'

/** ACCESS-CENTER-001 — the words for the three states, short and the same everywhere. */
export const LEVEL_LABEL: Record<Level, { ar: string; en: string }> = {
  none: { ar: 'مخفي', en: 'Hidden' },
  view: { ar: 'عرض', en: 'View' },
  manage: { ar: 'إدارة', en: 'Manage' },
}

/**
 * «ماذا سيرى» — every area as one chip: on (view / manage) or off. Read at a glance, before anything is sent.
 */
export function AreaChips({ areas, levels, ar, changed = [], testId, total }: { areas: Area[]; levels: Record<string, Level>; ar: boolean; changed?: string[]; testId?: string; total?: number }) {
  const on = areas.filter((a) => (levels[a.key] ?? 'none') !== 'none').length
  return (
    <div data-testid={testId}>
      <div className="mb-1.5 text-xs text-text-muted">
        {ar ? 'يظهر له' : 'Can open'} <span className="tnum font-bold text-text-primary">{on.toLocaleString('en-US')}</span> {ar ? 'من' : 'of'} <span className="tnum">{(total ?? areas.length).toLocaleString('en-US')}</span>
      </div>
      <ul className="flex flex-wrap gap-1.5">
        {areas.map((a) => {
          const lv = levels[a.key] ?? 'none'
          const tone = lv === 'manage'
            ? 'border-success/40 bg-[var(--positive-background)] text-success'
            : lv === 'view' ? 'border-brand-300 bg-brand-50 text-brand-700' : 'border-border bg-surface-secondary text-text-muted line-through'
          return (
            <li key={a.key} data-testid={`area-chip-${a.key}`} data-level={lv}
              className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-semibold ${tone} ${changed.includes(a.key) ? 'ring-2 ring-warning/50' : ''}`}>
              {lv === 'manage' ? <Check size={12} aria-hidden /> : lv === 'view' ? <Eye size={12} aria-hidden /> : <Minus size={12} aria-hidden />}
              {ar ? a.ar : a.en}
              {lv !== 'none' && <span className="font-normal opacity-80">· {ar ? LEVEL_LABEL[lv].ar : LEVEL_LABEL[lv].en}</span>}
            </li>
          )
        })}
      </ul>
    </div>
  )
}

/**
 * One row per area, three buttons: مخفي / عرض / إدارة. A level the reader cannot give is drawn disabled, never
 * offered and then refused. `base` is the category's own level, so a changed row is marked.
 */
export function AreaToggles({ areas, levels, base, grantable, onChange, ar, disabled = false, partial = {}, touched = [] }: {
  areas: Area[]
  levels: Record<string, Level>
  base: Record<string, Level>
  grantable: Record<string, Level>
  onChange: (area: string, level: Level) => void
  ar: boolean
  disabled?: boolean
  /** ACCESS-PARTIAL-LEVEL-001 — areas held only in part: shown as «جزئي x/y» until the reader picks a level. */
  partial?: Record<string, { held: number; total: number }>
  /** Areas the reader clicked: whatever level they chose is sent in full, even when its label matches. */
  touched?: string[]
}) {
  return (
    <ul className="grid gap-1.5 sm:grid-cols-2" data-testid="area-toggles">
      {areas.map((a) => {
        const current = levels[a.key] ?? 'none'
        const isTouched = touched.includes(a.key)
        const part = isTouched ? undefined : partial[a.key]
        const changed = isTouched || current !== (base[a.key] ?? 'none')
        return (
          <li key={a.key} className={`flex min-w-0 items-center justify-between gap-2 rounded-xl border px-2.5 py-1.5 ${changed ? 'border-warning/60' : 'border-border'}`}>
            <span className="flex min-w-0 items-center gap-1.5">
              <span className="min-w-0 truncate text-sm font-semibold text-text-primary">{ar ? a.ar : a.en}</span>
              {part && (
                <span className="tnum shrink-0 rounded-full bg-warning/15 px-1.5 text-[11px] font-semibold text-warning" data-testid={`partial-${a.key}`}
                  title={ar ? 'بعض الصلاحيات فقط — اضغط «إدارة» لمنح الكل بما فيها الإطلاق' : 'Only some actions — press Manage to grant all, launch included'}>
                  {ar ? 'جزئي' : 'Partial'} {part.held}/{part.total}
                </span>
              )}
            </span>
            <div role="radiogroup" aria-label={ar ? a.ar : a.en} className="flex shrink-0 overflow-hidden rounded-lg border border-border">
              {LEVELS.map((lv) => {
                // A partly held level is raised by picking it again, so it is checked as a raise from the level below.
                const from = part && lv === current ? (lv === 'manage' ? 'view' : 'none') : (base[a.key] ?? 'none')
                const allowed = !disabled && canGrant(grantable, a.key, lv, from)
                return (
                  <button key={lv} type="button" role="radio" aria-checked={current === lv && !part} disabled={!allowed}
                    data-testid={`toggle-${a.key}-${lv}`}
                    onClick={() => onChange(a.key, lv)}
                    className={`px-2 py-1 text-xs font-semibold disabled:cursor-not-allowed disabled:opacity-40 ${current === lv ? (part ? 'bg-warning/20 text-text-primary' : 'bg-brand-600 text-white') : 'bg-surface text-text-secondary hover:text-text-primary'}`}>
                    {ar ? LEVEL_LABEL[lv].ar : LEVEL_LABEL[lv].en}
                  </button>
                )
              })}
            </div>
          </li>
        )
      })}
    </ul>
  )
}

/** A chip button row — categories, audiences, roles. */
export function ChipPicker<T extends string>({ options, value, onChange, testId, label }: {
  options: { value: T; label: string; count?: number; disabled?: boolean }[]
  value: T | ''
  onChange: (v: T) => void
  testId: string
  label: string
}) {
  return (
    <div role="radiogroup" aria-label={label} className="flex flex-wrap gap-1.5" data-testid={testId}>
      {options.map((o) => (
        <button key={o.value} type="button" role="radio" aria-checked={value === o.value} disabled={o.disabled}
          data-testid={`${testId}-${o.value}`}
          onClick={() => onChange(o.value)}
          className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold disabled:cursor-not-allowed disabled:opacity-40 ${value === o.value ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-border text-text-secondary hover:text-text-primary'}`}>
          {o.label}
          {o.count !== undefined && <span className="tnum rounded-full bg-surface-secondary px-1.5 text-text-muted">{o.count.toLocaleString('en-US')}</span>}
        </button>
      ))}
    </div>
  )
}
