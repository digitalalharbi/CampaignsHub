import { useState } from 'react'
import { Eye, EyeOff, SlidersHorizontal } from 'lucide-react'
import { ELEMENT_GROUPS, forPreset, presetOf, type Ga4Preset } from './visibility'

/**
 * GA4-VISIBILITY-001 — one control for what the store / GA4 layer shows: four preset chips and, under
 * «مخصص», every element as its own switch. The same control sits in the report builder, on a link and in
 * Analytics, and it only chooses: the server cuts, so a hidden figure is absent from the data, not from the CSS.
 */
const PRESETS: Array<{ key: Ga4Preset; ar: string; en: string }> = [
  { key: 'marketing', ar: 'تسويق', en: 'Marketing' },
  { key: 'sales', ar: 'مبيعات', en: 'Sales' },
  { key: 'all', ar: 'الكل', en: 'All' },
  { key: 'custom', ar: 'مخصص', en: 'Custom' },
]

export function Ga4VisibilityControl({ hidden, onChange, ar, testId = 'ga4-visibility' }: { hidden: string[]; onChange: (next: string[]) => void; ar: boolean; testId?: string }) {
  const preset = presetOf(hidden)
  const [open, setOpen] = useState(preset === 'custom')
  const set = new Set(hidden)
  const toggle = (key: string) => {
    const next = new Set(set)
    if (next.has(key)) next.delete(key)
    else next.add(key)
    onChange([...next])
  }

  return (
    <div data-testid={testId} data-preset={preset} className="flex min-w-0 flex-col gap-2">
      <div role="radiogroup" aria-label={ar ? 'ما يظهر' : 'Visibility'} className="flex flex-wrap items-center gap-1.5">
        {PRESETS.map((p) => (
          <button key={p.key} type="button" role="radio" aria-checked={preset === p.key} data-testid={`${testId}-preset-${p.key}`}
            onClick={() => { if (p.key === 'custom') setOpen(true); else { onChange(forPreset(p.key)); setOpen(false) } }}
            className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold transition-colors ${preset === p.key ? 'bg-brand-600 text-white' : 'bg-surface-secondary text-text-secondary hover:text-text-primary'}`}>
            {p.key === 'all' ? <Eye size={12} /> : p.key === 'custom' ? <SlidersHorizontal size={12} /> : <EyeOff size={12} />}
            {ar ? p.ar : p.en}
          </button>
        ))}
      </div>
      {open && (
        <div data-testid={`${testId}-elements`} className="grid grid-cols-1 gap-3 rounded-xl border border-border p-3 sm:grid-cols-2 xl:grid-cols-3">
          {ELEMENT_GROUPS.map((g) => (
            <fieldset key={g.key} className="min-w-0">
              <legend className="mb-1 text-xs font-extrabold text-text-primary">{ar ? g.label.ar : g.label.en}</legend>
              <div className="flex flex-wrap gap-1">
                {g.items.map((i) => {
                  const shown = !set.has(i.key)

                  return (
                    <label key={i.key} className={`inline-flex cursor-pointer items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-semibold ${shown ? 'border-border text-text-primary' : 'border-dashed border-border text-text-muted line-through'}`}>
                      <input type="checkbox" className="sr-only" data-testid={`${testId}-el-${i.key}`} checked={shown} onChange={() => toggle(i.key)} />
                      <span aria-hidden className={`h-1.5 w-1.5 rounded-full ${i.sales ? 'bg-success' : 'bg-info'}`} />
                      {ar ? i.label.ar : i.label.en}
                    </label>
                  )
                })}
              </div>
            </fieldset>
          ))}
          <div className="col-span-full flex items-center gap-3 text-[11px] text-text-muted">
            <span className="inline-flex items-center gap-1"><span className="h-1.5 w-1.5 rounded-full bg-info" />{ar ? 'تسويق' : 'Marketing'}</span>
            <span className="inline-flex items-center gap-1"><span className="h-1.5 w-1.5 rounded-full bg-success" />{ar ? 'مبيعات' : 'Sales'}</span>
          </div>
        </div>
      )}
    </div>
  )
}
