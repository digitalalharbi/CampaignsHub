import { useState } from 'react'
import { Plus, X } from 'lucide-react'
import { DateField } from '@/components/ui/DateField'
import { Input } from '@/components/ui/Input'
import { providerLabel } from '@/features/campaigns/labels'
import { PLATFORM_ORDER } from '@/lib/platforms'
import type { PaymentMode, PlanInput } from './fundingApi'
import { L, MODE } from './labels'

type Lang = { ar: string; en: string }

// ---------------------------------------------------------------------------------------------------------------- mode

/**
 * The mode as three big chips: مباشر · بالأجل say HOW the client pays (one of the two); مخطّطة says the budget has a
 * platform split (on or off, with either). Default: direct, no split.
 */
export function ModeChips({ mode, planned, onMode, onPlanned, ar }: {
  mode: PaymentMode
  planned: boolean
  onMode: (m: PaymentMode) => void
  onPlanned: (p: boolean) => void
  ar: boolean
}) {
  const chip = (label: Lang, on: boolean, click: () => void, testid: string, radio: boolean) => (
    <button
      key={testid}
      type="button"
      role={radio ? 'radio' : undefined}
      aria-checked={radio ? on : undefined}
      aria-pressed={radio ? undefined : on}
      onClick={click}
      data-testid={testid}
      className={`min-h-10 flex-1 rounded-[10px] border px-3 py-2 text-sm font-extrabold transition ${on ? 'border-brand-600 bg-brand-600/10 text-brand-700' : 'border-border text-text-secondary hover:border-border-strong'}`}
    >
      {ar ? label.ar : label.en}
    </button>
  )

  return (
    <div className="flex gap-2" role="radiogroup" aria-label={ar ? L.payMode.ar : L.payMode.en} data-testid="budget-mode">
      {chip(MODE.direct, mode === 'direct', () => onMode('direct'), 'mode-direct', true)}
      {chip(MODE.credit, mode === 'credit', () => onMode('credit'), 'mode-credit', true)}
      {chip(MODE.planned, planned, () => onPlanned(!planned), 'mode-planned', false)}
    </div>
  )
}

// -------------------------------------------------------------------------------------------------------------- period

export type PeriodPreset = 'open' | 'this_month' | 'next_month' | 'from_today' | 'custom'

const ymd = (d: Date): string => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

/**
 * The range a preset stands for. `open` is the smart default and what a budget gets when nothing is chosen: from the first
 * day of real spend (the launch), no end — both empty. A period may start in the future («next month»).
 */
export function presetRange(preset: PeriodPreset, now: Date = new Date()): { start: string; end: string } | null {
  const y = now.getFullYear()
  const m = now.getMonth()
  switch (preset) {
    case 'open': return { start: '', end: '' }
    case 'this_month': return { start: ymd(new Date(y, m, 1)), end: ymd(new Date(y, m + 1, 0)) }
    case 'next_month': return { start: ymd(new Date(y, m + 1, 1)), end: ymd(new Date(y, m + 2, 0)) }
    case 'from_today': return { start: ymd(now), end: '' }
    case 'custom': return null
  }
}

const PRESETS: { key: PeriodPreset; label: Lang }[] = [
  { key: 'open', label: { ar: 'مفتوحة', en: 'Open' } },
  { key: 'this_month', label: { ar: 'هذا الشهر', en: 'This month' } },
  { key: 'next_month', label: { ar: 'الشهر القادم', en: 'Next month' } },
  { key: 'from_today', label: { ar: 'من اليوم', en: 'From today' } },
  { key: 'custom', label: { ar: 'مخصص', en: 'Custom' } },
]

/** The preset a stored period looks like, so the edit dialog opens on the chip that matches it. */
export function presetOf(start: string, end: string, now: Date = new Date()): PeriodPreset {
  for (const k of ['open', 'this_month', 'next_month', 'from_today'] as const) {
    const r = presetRange(k, now)
    if (r && r.start === start && r.end === end) return k
  }

  return 'custom'
}

/**
 * «الفترة» — ONE chip showing the period; opening it shows the quick choices (مفتوحة · هذا الشهر · الشهر القادم · من اليوم ·
 * مخصص). Collapsed by default, so no period input is ever a required field: the default runs from the first spend, no end.
 */
export function PeriodChoice({ preset, start, end, onPreset, onStart, onEnd, ar, idPrefix }: {
  preset: PeriodPreset
  start: string
  end: string
  onPreset: (p: PeriodPreset, range: { start: string; end: string } | null) => void
  onStart: (v: string) => void
  onEnd: (v: string) => void
  ar: boolean
  idPrefix: string
}) {
  const [open, setOpen] = useState(false)
  const orderOk = !(start && end && end < start)
  const current = PRESETS.find((p) => p.key === preset)?.label

  return (
    <div className="flex min-w-0 flex-col gap-2" data-testid="period-choice">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        data-testid="period-toggle"
        className="inline-flex w-fit items-center gap-1.5 rounded-[var(--radius-pill)] border border-border px-3 py-1 text-xs font-bold text-text-secondary hover:border-border-strong"
      >
        {ar ? L.periodChip.ar : L.periodChip.en} · <span className="text-text-primary">{current ? (ar ? current.ar : current.en) : ''}</span>
      </button>
      {open && (
        <>
          <div className="flex flex-wrap gap-1.5" role="radiogroup" aria-label={ar ? L.periodChip.ar : L.periodChip.en}>
            {PRESETS.map((p) => (
              <button
                key={p.key}
                type="button"
                role="radio"
                aria-checked={preset === p.key}
                data-testid={`period-${p.key}`}
                onClick={() => onPreset(p.key, presetRange(p.key))}
                className={`rounded-[8px] border px-2.5 py-1 text-xs font-bold ${preset === p.key ? 'border-brand-600 text-brand-700' : 'border-border text-text-secondary'}`}
              >
                {ar ? p.label.ar : p.label.en}
              </button>
            ))}
          </div>
          {preset === 'custom' && (
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
              <label className="flex min-w-0 flex-col gap-1 text-xs font-semibold text-text-secondary" htmlFor={`${idPrefix}-start`}>
                {ar ? L.from.ar : L.from.en}
                <DateField id={`${idPrefix}-start`} value={start} onChange={onStart} placeholder={ar ? 'من أول إنفاق' : 'From first spend'} />
              </label>
              <label className="flex min-w-0 flex-col gap-1 text-xs font-semibold text-text-secondary" htmlFor={`${idPrefix}-end`}>
                {ar ? L.to.ar : L.to.en}
                <DateField id={`${idPrefix}-end`} value={end} onChange={onEnd} placeholder={ar ? L.open.ar : L.open.en} />
              </label>
              {!orderOk && <p role="alert" className="text-xs font-semibold text-danger sm:col-span-2">{ar ? 'النهاية قبل البداية' : 'End is before start'}</p>}
            </div>
          )}
        </>
      )}
    </div>
  )
}

// ------------------------------------------------------------------------------------------------------- the platform split

// The canonical platform order; a plan row is keyed by the provider key the spend rows carry (Google's is `google_ads`).
export const PLAN_PROVIDERS: string[] = PLATFORM_ORDER.map((p) => (p === 'google' ? 'google_ads' : p))

export interface PlanDraftRow { provider: string; value: string }

export const emptyPlan = (): PlanDraftRow[] => [{ provider: 'snapchat', value: '' }, { provider: 'meta', value: '' }]

/** The typed shares as percentages: they must add up to exactly 100, each platform once. */
export function planState(rows: PlanDraftRow[]): { sum: number; ok: boolean } {
  const sum = Math.round(rows.reduce((s, r) => s + (Number(r.value) || 0), 0) * 100) / 100
  const filled = rows.length > 0 && rows.every((r) => r.provider !== '' && Number(r.value) > 0)
  const distinct = new Set(rows.map((r) => r.provider)).size === rows.length

  return { sum, ok: filled && distinct && Math.abs(sum - 100) <= 0.01 }
}

export function planPayload(rows: PlanDraftRow[]): PlanInput[] {
  return rows.map((r) => ({ provider: r.provider, percent: Number(r.value) }))
}

/** One row per platform with its % — nothing else. The sum chip turns green at 100. */
export function PlanEditor({ rows, onRows, ar }: { rows: PlanDraftRow[]; onRows: (r: PlanDraftRow[]) => void; ar: boolean }) {
  const state = planState(rows)
  const used = new Set(rows.map((r) => r.provider))
  const free = PLAN_PROVIDERS.filter((p) => !used.has(p))
  const set = (i: number, patch: Partial<PlanDraftRow>) => onRows(rows.map((r, n) => (n === i ? { ...r, ...patch } : r)))
  const loc = ar ? 'ar' : 'en'

  return (
    <div className="flex min-w-0 flex-col gap-2 rounded-[10px] border border-border p-2.5" data-testid="plan-editor">
      <ul className="flex flex-col gap-1.5">
        {rows.map((r, i) => (
          <li key={i} className="flex min-w-0 items-center gap-1.5" data-testid="plan-row">
            <select
              aria-label={ar ? L.platform.ar : L.platform.en}
              value={r.provider}
              onChange={(e) => set(i, { provider: e.target.value })}
              className="min-w-0 flex-1 rounded-[8px] border border-border bg-surface px-2 py-1.5 text-sm"
            >
              {[r.provider, ...free].map((p) => <option key={p} value={p}>{providerLabel(p, loc)}</option>)}
            </select>
            <Input
              aria-label={`${providerLabel(r.provider, loc)} %`}
              inputMode="decimal"
              dir="ltr"
              value={r.value}
              onChange={(e) => set(i, { value: e.target.value.replace(/[^\d.]/g, '') })}
              className="w-20"
            />
            <span className="w-4 shrink-0 text-xs text-text-muted" aria-hidden>%</span>
            <button type="button" onClick={() => onRows(rows.filter((_, n) => n !== i))} aria-label={ar ? 'احذف' : 'Remove'} className="shrink-0 rounded-md p-1 text-text-muted hover:bg-surface-hover">
              <X className="h-4 w-4" aria-hidden />
            </button>
          </li>
        ))}
      </ul>
      <div className="flex items-center justify-between gap-2 text-xs">
        <button
          type="button"
          disabled={free.length === 0 || rows.length >= 12}
          onClick={() => onRows([...rows, { provider: free[0] ?? '', value: '' }])}
          data-testid="plan-add"
          className="flex items-center gap-1 font-bold text-brand-700 disabled:opacity-40"
        >
          <Plus className="h-3.5 w-3.5" aria-hidden /> {ar ? L.addPlatform.ar : L.addPlatform.en}
        </button>
        <span className={`tnum font-extrabold ${state.ok ? 'text-success' : 'text-warning'}`} data-testid="plan-sum" data-ok={state.ok}>
          <bdi dir="ltr">{state.sum} / 100%</bdi>
        </span>
      </div>
    </div>
  )
}
