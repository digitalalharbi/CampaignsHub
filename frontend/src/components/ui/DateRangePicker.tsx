import { useCallback, useEffect, useId, useLayoutEffect, useMemo, useRef, useState, type KeyboardEvent, type PointerEvent } from 'react'
import { CalendarDays, ChevronDown, ChevronLeft, ChevronRight, X } from 'lucide-react'
import { useUi } from '@/stores/ui'
import { RangeCalendar, stepForKey } from '@/components/ui/RangeCalendar'
import { PeriodStatus } from '@/components/ui/PeriodStatus'
import { usePeriod } from '@/lib/period/usePeriod'
import {
  COMPARE_LABEL, MONTHS_AR, MONTHS_EN, PRESETS, addDays, addMonths, formatSpan, isValidYmd, parseYmd, periodSummary, presetLabel,
  type CompareMode, type PresetKey,
} from '@/lib/period/periodMath'
import { resolveSelection, type Selection } from '@/lib/period/periodState'

/**
 * DATE-RANGE-001 — the one period control for the whole system.
 *
 * A button that says the period in words and dates («آخر 30 يوم: 16 أكتوبر – 14 نوفمبر 2026»). It opens a
 * panel with presets, two months side by side, from/to fields you can type into, a comparison, and
 * «تحديث» / «إلغاء» in a footer that stays visible. The panel edits a DRAFT: «تحديث» or a press outside the
 * panel saves it; «إلغاء» and Escape discard it.
 *
 * On a phone the same panel is a bottom sheet with ONE month and the presets as a sideways-scrolling row.
 *
 * Choosing a range: two clicks, or press-and-drag with a mouse (a finger scrolls, so touch uses two taps).
 * Typing: `YYYY-MM-DD`, Gregorian, Latin digits — what every browser, Safari included, can parse.
 *
 * The period is Riyadh time and the footer says so. Days after today cannot be picked.
 */
export function DateRangePicker({ projectId, earliest, budgetHint = false, className = '', testId = 'date-range' }: {
  /** The page shows budgets or spend limits: say they are counted on their own period, not on this one. */
  budgetHint?: boolean
  /** When given, the «جاري» / «بيانات غير مكتملة» badges are shown beside the button for this project. */
  projectId?: string | null
  /** First day the page has data — «طوال المدة» starts there. */
  earliest?: string | null
  className?: string
  testId?: string
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const state = usePeriod({ earliest })
  const { period, comparison, selection, ctx, recent } = state

  const [open, setOpen] = useState(false)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const close = useCallback(() => { setOpen(false); triggerRef.current?.focus() }, [])

  return (
    <div className={`flex flex-wrap items-center gap-2 ${className}`} data-testid={testId}>
      <div className="min-w-0 max-w-full">
        <button
          ref={triggerRef} type="button" data-testid={`${testId}-button`}
          aria-haspopup="dialog" aria-expanded={open}
          onClick={() => setOpen((v) => !v)}
          className="flex min-h-11 w-full max-w-full items-center gap-2 rounded-xl border border-border-strong bg-surface px-3 py-1.5 text-start text-sm font-semibold text-text-primary hover:bg-surface-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 sm:min-h-10"
        >
          <CalendarDays className="h-4 w-4 shrink-0 text-text-secondary" aria-hidden />
          <span className="min-w-0 flex-1">
            <span className="block truncate" data-testid={`${testId}-summary`}>{periodSummary(period, ar)}</span>
            {comparison && (
              <span className="block truncate text-xs font-normal text-info" data-testid={`${testId}-compare-summary`}>
                {ar ? 'مقارنة بـ ' : 'Compared with '}{formatSpan(comparison, ar)}
              </span>
            )}
          </span>
          <ChevronDown className="h-4 w-4 shrink-0 text-text-muted" aria-hidden />
        </button>
      </div>
      <PeriodStatus projectId={projectId} period={period} testId={`${testId}-status`} />
      {budgetHint && (
        <p className="w-full text-xs text-text-muted" data-testid={`${testId}-budget-hint`}>
          {ar ? 'الميزانية تُحسب على فترتها، لا على فترة العرض' : 'Budgets are counted on their own period, not on the viewing period'}
        </p>
      )}

      {open && (
        <PickerPanel
          ar={ar} anchor={triggerRef} initial={selection} ctx={ctx} recent={recent} testId={testId}
          onCancel={close}
          onApply={(next) => { state.setSelection(next); close() }}
        />
      )}
    </div>
  )
}

type Target = 'main' | 'compare'

function useIsPhone(): boolean {
  const [phone, setPhone] = useState(() => typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia('(max-width: 639px)').matches)
  useEffect(() => {
    if (typeof window.matchMedia !== 'function') return
    const mq = window.matchMedia('(max-width: 639px)')
    const on = () => setPhone(mq.matches)
    // Safari < 14 only has addListener.
    if (mq.addEventListener) mq.addEventListener('change', on); else mq.addListener(on)

    return () => { if (mq.removeEventListener) mq.removeEventListener('change', on); else mq.removeListener(on) }
  }, [])

  return phone
}

export function PickerPanel({ ar, anchor, initial, ctx, recent, testId, onCancel, onApply, allowCompare = true }: {
  allowCompare?: boolean
  ar: boolean
  anchor: React.RefObject<HTMLButtonElement | null>
  initial: Selection
  ctx: { today: string; earliest?: string | null }
  recent: Selection[]
  testId: string
  onCancel: () => void
  onApply: (s: Selection) => void
}) {
  const phone = useIsPhone()
  const panelRef = useRef<HTMLDivElement>(null)
  const titleId = useId()
  const [draft, setDraft] = useState<Selection>(initial)
  const [target, setTarget] = useState<Target>('main')
  const [anchorDay, setAnchorDay] = useState<string | null>(null)
  const [hover, setHover] = useState<string | null>(null)
  const drag = useRef<{ start: string; moved: boolean } | null>(null)
  const suppressClick = useRef(false)
  const resolved = useMemo(() => resolveSelection(draft, ctx), [draft, ctx])

  const todayP = parseYmd(ctx.today)!
  // First displayed month: the month before the period's last month (so the period's end is on the right), or that month alone on a phone.
  const endP = parseYmd(resolved.period.to)!
  const [view, setView] = useState(() => (phone ? { y: endP.y, m: endP.m } : fromMonth(addMonths(`${endP.y}-${String(endP.m).padStart(2, '0')}-01`, -1))))
  const showView = (day: string) => {
    const p = parseYmd(day)!
    setView(phone ? { y: p.y, m: p.m } : fromMonth(addMonths(`${p.y}-${String(p.m).padStart(2, '0')}-01`, -1)))
  }

  /* Position: fixed under the button, kept inside the screen; a phone gets a bottom sheet instead. */
  const [pos, setPos] = useState<{ top: number; left: number; maxH: number } | null>(null)
  useLayoutEffect(() => {
    if (phone) return
    const place = () => {
      const a = anchor.current?.getBoundingClientRect()
      const panel = panelRef.current
      if (!a || !panel) return
      const w = panel.offsetWidth || 600
      const rtl = document.documentElement.dir === 'rtl'
      const wanted = rtl ? a.right - w : a.left
      const left = Math.max(8, Math.min(wanted, window.innerWidth - w - 8))
      // Never taller than the screen; slide up when the button sits low, so every control stays reachable.
      const maxH = window.innerHeight - 16
      const h = Math.min(panel.scrollHeight, maxH)
      const top = Math.max(8, Math.min(a.bottom + 8, window.innerHeight - 8 - h))
      setPos((cur) => (cur && cur.top === top && cur.left === left && cur.maxH === maxH ? cur : { top, left, maxH }))
    }
    place()
    window.addEventListener('resize', place)
    window.addEventListener('scroll', place) // the page's own scroll only: an inner scroll does not bubble
    const ro = typeof ResizeObserver === 'function' && panelRef.current ? new ResizeObserver(place) : null
    if (ro && panelRef.current) ro.observe(panelRef.current)

    return () => { window.removeEventListener('resize', place); window.removeEventListener('scroll', place); ro?.disconnect() }
  }, [phone, anchor])

  /* A press outside SAVES what was chosen (a change is never lost by clicking away); Escape and «إلغاء» discard. */
  const onOutside = useRef<() => void>(onCancel)

  /* Escape closes; Tab stays inside; a press outside closes (never on the button that opened it). */
  useEffect(() => {
    const el = panelRef.current
    el?.focus()
    const onKey = (e: globalThis.KeyboardEvent) => {
      if (e.key === 'Escape') { e.stopPropagation(); onCancel() }
      if (e.key === 'Tab' && el) {
        const f = Array.from(el.querySelectorAll<HTMLElement>('button:not([disabled]), input, select, [tabindex="0"]')).filter((n) => n.offsetParent !== null)
        if (f.length === 0) return
        const first = f[0]; const last = f[f.length - 1]
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus() }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus() }
      }
    }
    const onDown = (e: globalThis.PointerEvent) => {
      const t = e.target as Node
      if (el && !el.contains(t) && !anchor.current?.contains(t)) onOutside.current()
    }
    document.addEventListener('keydown', onKey)
    document.addEventListener('pointerdown', onDown)
    const endDrag = () => { drag.current = null }
    window.addEventListener('pointerup', endDrag)

    return () => { document.removeEventListener('keydown', onKey); document.removeEventListener('pointerdown', onDown); window.removeEventListener('pointerup', endDrag) }
  }, [anchor, onCancel])

  /* ── the draft ── */
  const targetSpan = target === 'main' ? resolved.period : resolved.comparison
  const setTargetSpan = (from: string, to: string) => {
    const [a, b] = from <= to ? [from, to] : [to, from]
    if (target === 'main') setDraft((d) => ({ ...d, key: 'custom', from: a, to: b }))
    else setDraft((d) => ({ ...d, compare: 'custom', cfrom: a, cto: b }))
  }

  const chooseDay = (day: string) => {
    if (day > ctx.today) return
    if (anchorDay === null) { setAnchorDay(day); setHover(day); setTargetSpan(day, day); return }
    setTargetSpan(anchorDay, day)
    setAnchorDay(null); setHover(null)
  }

  const onDayClick = (day: string) => {
    if (suppressClick.current) { suppressClick.current = false; return }
    chooseDay(day)
  }
  const onDayPointerDown = (day: string, e: PointerEvent) => {
    suppressClick.current = false
    if (e.pointerType === 'mouse' && e.button === 0 && day <= ctx.today) drag.current = { start: day, moved: false }
  }
  const onDayPointerEnter = (day: string) => {
    if (anchorDay !== null || drag.current) setHover(day)
    if (drag.current && day !== drag.current.start) drag.current.moved = true
  }
  const onDayPointerUp = (day: string, e: PointerEvent) => {
    const d = drag.current
    drag.current = null
    if (d && d.moved && day !== d.start && day <= ctx.today && e.pointerType === 'mouse') {
      suppressClick.current = true
      setTargetSpan(d.start, day)
      setAnchorDay(null); setHover(null)
    }
  }
  const onDayKeyDown = (day: string, e: KeyboardEvent) => {
    const step = stepForKey(e.key, document.documentElement.dir === 'rtl')
    if (step === null) return
    e.preventDefault()
    const next = addDays(day, step)
    if (next > ctx.today) return
    const p = parseYmd(next)!
    const shown = [view, nextMonth(view)].slice(0, phone ? 1 : 2)
    if (!shown.some((s) => s.y === p.y && s.m === p.m)) setView(phone ? { y: p.y, m: p.m } : fromMonth(next.slice(0, 7) + '-01'))
    requestAnimationFrame(() => panelRef.current?.querySelector<HTMLElement>(`[data-day="${next}"]`)?.focus())
  }

  /* While choosing, paint the preview between the first day and wherever the pointer is. */
  const preview = anchorDay !== null || drag.current ? (() => {
    const a = anchorDay ?? drag.current!.start
    const b = hover ?? a

    return a <= b ? { from: a, to: b } : { from: b, to: a }
  })() : null
  const mainPaint = target === 'main' && preview ? preview : resolved.period
  const cmpPaint = target === 'compare' && preview ? preview : resolved.comparison

  /* ── typed fields ── */
  const choosePreset = (key: PresetKey) => {
    const next: Selection = { key, compare: draft.compare === 'custom' ? 'none' : draft.compare }
    setDraft(next); setTarget('main'); setAnchorDay(null)
    showView(resolveSelection(next, ctx).period.to)
  }

  const applyRecent = (s: Selection) => { setDraft(s); setTarget('main'); setAnchorDay(null); showView(resolveSelection(s, ctx).period.to) }

  const setMode = (mode: CompareMode) => {
    setDraft((d) => ({ ...d, compare: mode, ...(mode === 'custom' ? { cfrom: d.cfrom ?? resolveSelection({ ...d, compare: 'previous' }, ctx).comparison?.from, cto: d.cto ?? resolveSelection({ ...d, compare: 'previous' }, ctx).comparison?.to } : {}) }))
    setTarget(mode === 'custom' ? 'compare' : 'main')
  }

  const apply = () => {
    const out: Selection = { ...draft }
    if (out.key === 'custom') { out.from = resolved.period.from; out.to = resolved.period.to }
    if (out.compare === 'custom') { out.cfrom = resolved.comparison?.from; out.cto = resolved.comparison?.to }
    onApply(out)
  }
  useEffect(() => {
    onOutside.current = () => {
      const changed = JSON.stringify(draft) !== JSON.stringify(initial)
      if (changed) apply(); else onCancel()
    }
  })

  const second = nextMonth(view)
  const canNext = !(phone ? (view.y > todayP.y || (view.y === todayP.y && view.m >= todayP.m)) : (second.y > todayP.y || (second.y === todayP.y && second.m >= todayP.m)))
  const prevIcon = ar ? <ChevronRight className="h-5 w-5" aria-hidden /> : <ChevronLeft className="h-5 w-5" aria-hidden />
  const nextIcon = ar ? <ChevronLeft className="h-5 w-5" aria-hidden /> : <ChevronRight className="h-5 w-5" aria-hidden />
  const years = useMemo(() => Array.from({ length: todayP.y - 2019 }, (_, i) => 2020 + i), [todayP.y])
  const activePreset = draft.key !== 'custom' ? draft.key : null

  const calendars = (
    <div className="min-w-0 flex-1">
      <div className="mb-1 flex items-center justify-between gap-2">
        <button type="button" onClick={() => setView((v) => prevMonth(v))} aria-label={ar ? 'الشهر السابق' : 'Previous month'} data-testid={`${testId}-prev`}
          className="flex h-11 w-11 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover sm:h-7 sm:w-7">{prevIcon}</button>
        <div className="flex items-center gap-2">
          <select aria-label={ar ? 'الشهر' : 'Month'} value={view.m} onChange={(e) => setView((v) => ({ ...v, m: Number(e.target.value) }))} data-testid={`${testId}-month`}
            className="h-11 rounded-lg border border-border-strong bg-surface px-2 text-base text-text-primary sm:h-7 sm:text-xs">
            {(ar ? MONTHS_AR : MONTHS_EN).map((n, i) => <option key={n} value={i + 1}>{n}</option>)}
          </select>
          <select aria-label={ar ? 'السنة' : 'Year'} value={view.y} onChange={(e) => setView((v) => ({ ...v, y: Number(e.target.value) }))} data-testid={`${testId}-year`}
            className="h-11 rounded-lg border border-border-strong bg-surface px-2 text-base text-text-primary tnum sm:h-7 sm:text-xs" dir="ltr">
            {years.map((y) => <option key={y} value={y}>{y}</option>)}
          </select>
        </div>
        <button type="button" disabled={!canNext} onClick={() => setView((v) => nextMonth(v))} aria-label={ar ? 'الشهر التالي' : 'Next month'} data-testid={`${testId}-next`}
          className="flex h-11 w-11 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover disabled:opacity-30 sm:h-9 sm:w-9">{nextIcon}</button>
      </div>
      <div className={`grid ${phone ? 'grid-cols-1 gap-4' : 'grid-cols-2 gap-3'}`}>
        {[view, ...(phone ? [] : [second])].map((v) => (
          <RangeCalendar
            key={`${v.y}-${v.m}`} year={v.y} month={v.m} today={ctx.today} ar={ar} testId={`${testId}-cal-${v.y}-${String(v.m).padStart(2, '0')}`}
            paints={[{ span: mainPaint, tone: 'main' }, { span: cmpPaint, tone: 'compare' }]}
            onDayClick={onDayClick} onDayPointerDown={onDayPointerDown} onDayPointerEnter={onDayPointerEnter} onDayPointerUp={onDayPointerUp} onDayKeyDown={onDayKeyDown}
          />
        ))}
      </div>
    </div>
  )

  const presetButtons = PRESETS.map((p) => (
    <button
      key={p.key} type="button" data-testid={`${testId}-preset-${p.key}`} aria-pressed={activePreset === p.key} onClick={() => choosePreset(p.key)}
      className={`shrink-0 rounded-lg text-start font-semibold ${phone ? 'min-h-11 border border-border px-3 py-2 text-sm' : 'min-h-7 w-full px-2 py-1 text-xs'} ${activePreset === p.key ? 'bg-brand-600 text-white' : 'text-text-primary hover:bg-surface-hover'}`}
    >{ar ? p.ar : p.en}</button>
  ))

  const body = (
    <>
      {recent.length > 0 && (
        <div className="mb-2" data-testid={`${testId}-recent`}>
          <div className="mb-1.5 text-xs font-bold text-text-secondary">{ar ? 'مستخدمة مؤخرًا' : 'Recently used'}</div>
          <div className="flex gap-2 overflow-x-auto pb-1">
            {recent.map((s, i) => {
              const r = resolveSelection(s, ctx)

              return (
                <button key={`${s.key}-${i}`} type="button" onClick={() => applyRecent(s)} data-testid={`${testId}-recent-${i}`}
                  className="min-h-11 shrink-0 rounded-lg border border-border bg-surface-secondary px-3 py-1.5 text-start text-xs font-semibold text-text-primary hover:bg-surface-hover sm:min-h-0 sm:px-2 sm:py-1">
                  <span className="block">{presetLabel(r.period.key, ar)}</span>
                  <span className="block font-normal text-text-secondary">{formatSpan(r.period, ar)}</span>
                </button>
              )
            })}
          </div>
        </div>
      )}

      <div className={phone ? 'flex flex-col gap-3' : 'flex gap-3'}>
        {phone
          ? <div className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1" data-testid={`${testId}-presets`} role="group" aria-label={ar ? 'فترات جاهزة' : 'Presets'}>{presetButtons}</div>
          : <div className="w-32 shrink-0 space-y-px border-e border-border pe-2" data-testid={`${testId}-presets`} role="group" aria-label={ar ? 'فترات جاهزة' : 'Presets'}>{presetButtons}</div>}
        {calendars}
      </div>

      <div className="mt-2 grid gap-2 border-t border-border pt-2 sm:grid-cols-2">
        <DateFields
          ar={ar} label={target === 'main' ? (ar ? 'الفترة' : 'Period') : (ar ? 'فترة المقارنة' : 'Comparison period')} span={targetSpan} today={ctx.today} testId={testId}
          onCommit={(f, t) => { setTargetSpan(f, t); showView(t) }}
        />
        {allowCompare ? <div>
          <label className="flex min-h-11 items-center gap-2 text-sm font-semibold text-text-primary sm:min-h-7 sm:text-xs">
            <input type="checkbox" className="h-5 w-5 accent-[var(--brand-600)] sm:h-4 sm:w-4" checked={draft.compare !== 'none'} data-testid={`${testId}-compare-toggle`}
              onChange={(e) => setMode(e.target.checked ? 'previous' : 'none')} />
            {ar ? 'مقارنة' : 'Compare'}
          </label>
          {draft.compare !== 'none' && (
            <div className="space-y-2">
              <select aria-label={ar ? 'نوع المقارنة' : 'Comparison type'} value={draft.compare} data-testid={`${testId}-compare-mode`} onChange={(e) => setMode(e.target.value as CompareMode)}
                className="h-11 w-full rounded-lg border border-border-strong bg-surface px-2 text-base text-text-primary sm:h-8 sm:text-xs">
                {(['previous', 'last_year', 'custom'] as const).map((m) => <option key={m} value={m}>{ar ? COMPARE_LABEL[m].ar : COMPARE_LABEL[m].en}</option>)}
              </select>
              {resolved.comparison && <div className="text-xs text-text-secondary" data-testid={`${testId}-compare-range`}>{formatSpan(resolved.comparison, ar)}</div>}
              {draft.compare === 'custom' && (
                <div className="inline-flex rounded-lg border border-border p-0.5" role="group" aria-label={ar ? 'ما الذي يُحدَّد في التقويم' : 'What the calendar edits'}>
                  {(['main', 'compare'] as const).map((t) => (
                    <button key={t} type="button" aria-pressed={target === t} onClick={() => { setTarget(t); setAnchorDay(null) }} data-testid={`${testId}-target-${t}`}
                      className={`min-h-11 rounded-md px-3 text-sm font-semibold sm:min-h-7 sm:text-xs ${target === t ? 'bg-surface-secondary text-text-primary' : 'text-text-secondary'}`}>
                      {t === 'main' ? (ar ? 'الفترة' : 'Period') : (ar ? 'المقارنة' : 'Comparison')}
                    </button>
                  ))}
                </div>
              )}
              <Legend ar={ar} />
            </div>
          )}
        </div> : <div />}
      </div>

      <div className="sticky bottom-0 z-10 -mx-4 -mb-4 mt-2 flex flex-wrap items-center justify-between gap-2 border-t border-border bg-surface-elevated px-4 py-2 pb-[max(0.5rem,env(safe-area-inset-bottom))] sm:-mx-3 sm:-mb-3 sm:px-3" data-testid={`${testId}-footer`}>
        <p className="text-xs text-text-secondary" data-testid={`${testId}-zone`}>
          {ar ? 'التواريخ بتوقيت الرياض (GMT+3)' : 'Dates are in Riyadh time (GMT+3)'}
        </p>
        <div className="flex gap-2">
          <button type="button" onClick={onCancel} data-testid={`${testId}-cancel`}
            className="min-h-11 rounded-xl border border-border-strong bg-surface px-4 text-sm font-semibold text-text-primary hover:bg-surface-hover sm:min-h-8 sm:text-xs">{ar ? 'إلغاء' : 'Cancel'}</button>
          <button type="button" onClick={apply} data-testid={`${testId}-apply`}
            className="min-h-11 rounded-xl bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700 sm:min-h-8 sm:text-xs">{ar ? 'تحديث' : 'Update'}</button>
        </div>
      </div>
    </>
  )

  if (phone) {
    return (
      <div className="fixed inset-0 z-[80]" data-testid={`${testId}-sheet`}>
        <div className="absolute inset-0 bg-black/40" aria-hidden />
        <div
          ref={panelRef} tabIndex={-1} role="dialog" aria-modal="true" aria-labelledby={titleId} data-testid={`${testId}-panel`}
          className="absolute inset-x-0 bottom-0 max-h-[92dvh] overflow-y-auto rounded-t-2xl border-t border-border bg-surface-elevated p-4 shadow-[var(--shadow-small)] focus:outline-none"
        >
          <div className="mb-3 flex items-center justify-between">
            <h2 id={titleId} className="text-base font-bold text-text-primary">{ar ? 'الفترة الزمنية' : 'Period'}</h2>
            <button type="button" onClick={onCancel} aria-label={ar ? 'إغلاق' : 'Close'} className="flex h-11 w-11 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover"><X className="h-5 w-5" aria-hidden /></button>
          </div>
          {body}
        </div>
      </div>
    )
  }

  return (
    <div
      ref={panelRef} tabIndex={-1} role="dialog" aria-modal="false" aria-labelledby={titleId} data-testid={`${testId}-panel`}
      style={pos ? { top: pos.top, left: pos.left, maxHeight: pos.maxH } : { visibility: 'hidden', top: 0, left: 0 }}
      className="fixed z-[80] w-[min(600px,calc(100vw-1rem))] overflow-y-auto rounded-xl border border-border-strong bg-surface-elevated p-3 shadow-[0_12px_40px_rgba(0,0,0,.22)] focus:outline-none"
    >
      <h2 id={titleId} className="sr-only">{ar ? 'الفترة الزمنية' : 'Period'}</h2>
      {body}
    </div>
  )
}

function Legend({ ar }: { ar: boolean }) {
  return (
    <div className="flex flex-wrap gap-3 text-xs text-text-secondary" aria-hidden>
      <span className="inline-flex items-center gap-1.5"><span className="h-3 w-3 rounded-sm bg-brand-600" />{ar ? 'الفترة' : 'Period'}</span>
      <span className="inline-flex items-center gap-1.5"><span className="h-3 w-3 rounded-sm border border-dashed border-info bg-[var(--info-background)]" />{ar ? 'المقارنة' : 'Comparison'}</span>
    </div>
  )
}

/** From / to, typed as YYYY-MM-DD. A bad or future date is said so and is not applied. */
function DateFields({ ar, label, span, today, testId, onCommit }: {
  ar: boolean
  label: string
  span: { from: string; to: string } | null
  today: string
  testId: string
  onCommit: (from: string, to: string) => void
}) {
  const [from, setFrom] = useState(span?.from ?? '')
  const [to, setTo] = useState(span?.to ?? '')
  const [err, setErr] = useState<string | null>(null)
  useEffect(() => { setFrom(span?.from ?? ''); setTo(span?.to ?? ''); setErr(null) }, [span?.from, span?.to])

  const tidy = (s: string) => {
    const m = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(s.trim())

    return m ? `${m[1]}-${m[2].padStart(2, '0')}-${m[3].padStart(2, '0')}` : s.trim()
  }
  const commit = () => {
    const f = tidy(from); const t = tidy(to)
    if (!isValidYmd(f) || !isValidYmd(t)) { setErr(ar ? 'اكتب التاريخ هكذا: 2026-11-14' : 'Type the date as 2026-11-14'); return }
    if (f > today || t > today) { setErr(ar ? 'لا يمكن اختيار يوم لم يأتِ بعد' : 'A day that has not come yet cannot be picked'); return }
    setErr(null); onCommit(f, t)
  }
  const onKey = (e: KeyboardEvent) => { if (e.key === 'Enter') { e.preventDefault(); commit() } }
  const field = 'h-11 w-full rounded-lg border bg-surface px-2 text-base text-text-primary tnum sm:h-8 sm:text-xs'

  return (
    <div>
      <div className="mb-1 text-xs font-bold text-text-secondary">{label}</div>
      <div className="flex items-center gap-2" dir="ltr">
        <input aria-label={ar ? 'من' : 'From'} data-testid={`${testId}-from`} inputMode="numeric" autoComplete="off" placeholder="YYYY-MM-DD" value={from} aria-invalid={err !== null}
          onChange={(e) => setFrom(e.target.value)} onBlur={commit} onKeyDown={onKey} className={`${field} ${err ? 'border-danger' : 'border-border-strong'}`} />
        <span className="text-text-muted" aria-hidden>–</span>
        <input aria-label={ar ? 'إلى' : 'To'} data-testid={`${testId}-to`} inputMode="numeric" autoComplete="off" placeholder="YYYY-MM-DD" value={to} aria-invalid={err !== null}
          onChange={(e) => setTo(e.target.value)} onBlur={commit} onKeyDown={onKey} className={`${field} ${err ? 'border-danger' : 'border-border-strong'}`} />
      </div>
      {err && <p role="alert" className="mt-1 text-xs text-danger" data-testid={`${testId}-error`}>{err}</p>}
    </div>
  )
}

const fromMonth = (firstOfMonth: string) => { const p = parseYmd(firstOfMonth)!; return { y: p.y, m: p.m } }
const nextMonth = (v: { y: number; m: number }) => (v.m === 12 ? { y: v.y + 1, m: 1 } : { y: v.y, m: v.m + 1 })
const prevMonth = (v: { y: number; m: number }) => (v.m === 1 ? { y: v.y - 1, m: 12 } : { y: v.y, m: v.m - 1 })
