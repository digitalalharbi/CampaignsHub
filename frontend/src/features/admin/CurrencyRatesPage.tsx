import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Calculator, CheckCircle2, Coins, History, Plus, RefreshCw, XCircle } from 'lucide-react'
import {
  fetchCurrencyRates, fetchRateHistory, previewConversion, recomputeConverted, recordCurrencyRate,
  setWorkspaceDisplayCurrency, updateFxPolicy, type UsedCurrency,
} from './api'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { DateField } from '@/components/ui/DateField'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { Switch } from '@/components/ui/Switch'
import { Num } from '@/components/ui/Num'
import { toApiError } from '@/lib/api/client'
import { formatConversion, rateKindLabel, type RateKind } from '@/lib/money/fx'
import { useUi } from '@/stores/ui'

/**
 * FX-ENGINE-001 — every exchange rate is managed here (Owner order). Compact and visual: status chips, short labels, no prose.
 *
 * Base currency USD. A rate is in force on the amount's own date (nearest earlier within 7 days; older ⚠; pegged = every
 * date; none = withheld). Editing a rate makes a NEW version (history below); every change is audited.
 */

const COPY = {
  ar: {
    title: 'أسعار الصرف', base: 'العملة الأساسية', display: 'عملة العرض', auto: 'مصدر تلقائي مجاني (ECB)', autoOff: 'متوقف',
    feedTitle: 'المصدر', awaiting: 'لم يُختَر مزوّد أسعار', awaitingWhy: 'لا يُخترَع سعر — المبلغ بلا سعر يُحجب.',
    unusable: 'المزوّد غير جاهز', ready: 'المصدر جاهز', lastRate: 'آخر سعر', ratesOnFile: 'أسعار', none: '—',
    alerts: 'تنبيهات', missing: 'بلا سعر', stale: 'قديم', valid: 'صالح', baseChip: 'أساسية',
    used: 'العملات المستخدمة', currency: 'العملة', accounts: 'حسابات', last: 'آخر سعر', date: 'التاريخ', kind: 'النوع',
    status: 'الحالة', withheldN: 'محجوب', record: 'سجّل', unmetTitle: 'تحويلات محجوبة', unmetEmpty: 'لا توجد أرقام محجوبة',
    withheld: 'محجوب', advertising: 'إعلانات', commerce: 'متاجر',
    addTitle: 'سعر جديد / تعديل', from: 'من', to: 'إلى', rate: 'السعر', start: 'يبدأ', end: 'ينتهي', note: 'ملاحظة / مرجع',
    pegged: 'مثبّت', save: 'حفظ', saving: 'جارٍ الحفظ…', saved: 'حُفظ', ratesTitle: 'الأسعار المسجّلة', history: 'السجل',
    preview: 'حاسبة', amount: 'المبلغ', calc: 'احسب', recompute: 'إعادة حساب الأرقام المحوّلة', dry: 'معاينة', apply: 'تطبيق',
    changed: 'تغيّر', released: 'تحرّر', becameWithheld: 'حُجب', unchanged: 'بدون تغيير', budgets: 'ميزانيات', before: 'قبل', after: 'بعد',
    workspaces: 'عملة العرض لكل مساحة', platformDefault: 'افتراضي المنصة', guard: 'الحارس اليومي', guardOk: 'سليم', guardBad: 'أرقام تجاوزت التحويل',
    by: 'بواسطة', version: 'نسخة', shadowed: 'مُتجاوَز',
  },
  en: {
    title: 'Exchange rates', base: 'Base currency', display: 'Display currency', auto: 'Free automatic source (ECB)', autoOff: 'Off',
    feedTitle: 'Source', awaiting: 'No rate source chosen', awaitingWhy: 'No rate is invented — an amount with no rate is withheld.',
    unusable: 'Source not ready', ready: 'Source ready', lastRate: 'Latest rate', ratesOnFile: 'Rates', none: '—',
    alerts: 'Alerts', missing: 'No rate', stale: 'Stale', valid: 'Valid', baseChip: 'Base',
    used: 'Currencies in use', currency: 'Currency', accounts: 'Accounts', last: 'Last rate', date: 'Date', kind: 'Kind',
    status: 'Status', withheldN: 'Withheld', record: 'Record', unmetTitle: 'Conversions withheld', unmetEmpty: 'Nothing is withheld',
    withheld: 'withheld', advertising: 'Advertising', commerce: 'Stores',
    addTitle: 'New rate / edit', from: 'From', to: 'To', rate: 'Rate', start: 'Starts', end: 'Ends', note: 'Note / reference',
    pegged: 'Pegged', save: 'Save', saving: 'Saving…', saved: 'Saved', ratesTitle: 'Rates on file', history: 'History',
    preview: 'Calculator', amount: 'Amount', calc: 'Calculate', recompute: 'Recompute converted figures', dry: 'Preview', apply: 'Apply',
    changed: 'Changed', released: 'Released', becameWithheld: 'Withheld', unchanged: 'Unchanged', budgets: 'Budgets', before: 'Before', after: 'After',
    workspaces: 'Display currency per workspace', platformDefault: 'Platform default', guard: 'Daily guard', guardOk: 'Clean', guardBad: 'Figures bypassed conversion',
    by: 'by', version: 'v', shadowed: 'shadowed',
  },
} as const

type T = (typeof COPY)['ar'] | (typeof COPY)['en']

const card = 'rounded-2xl border border-border bg-surface p-4'
const h2 = 'flex items-center gap-2 font-heading text-sm font-bold text-text-primary'

export function CurrencyRatesPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const t: T = ar ? COPY.ar : COPY.en
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['admin', 'fx-rates'], queryFn: fetchCurrencyRates })
  const refresh = () => void qc.invalidateQueries({ queryKey: ['admin', 'fx-rates'] })

  const [form, setForm] = useState({ base: '', quote: 'USD', rate: '', rate_date: '', effective_to: '', note: '', pegged: false })
  const save = useMutation({
    mutationFn: () => recordCurrencyRate({
      base: form.base.trim().toUpperCase(),
      quote: form.quote.trim().toUpperCase(),
      rate: Number(form.rate),
      rate_date: form.rate_date,
      effective_to: form.effective_to || null,
      note: form.note.trim() || null,
      kind: form.pegged ? 'pegged' : 'manual',
    }),
    onSuccess: () => { setForm((f) => ({ ...f, base: '', rate: '', note: '', effective_to: '' })); refresh() },
  })
  const policy = useMutation({ mutationFn: updateFxPolicy, onSuccess: refresh })

  if (q.isPending) return <Skeleton className="h-96" />
  if (q.isError || !q.data) return <ErrorState error={q.error} ar={ar} title={t.title} onRetry={() => void q.refetch()} />

  const { feed, unmet_pairs: unmet, rates, currencies = [], alerts = [], workspaces = [], guard = null } = q.data
  const pol = q.data.policy ?? { display_currency: 'USD' as const, auto_source_enabled: false, auto_source: 'ecb_reference', auto_source_label: '' }
  const ready = feed.state === 'ready'
  const pick = (c: UsedCurrency) => setForm((f) => ({ ...f, base: c.currency, quote: 'USD', rate: c.rate ? String(c.rate) : '' }))

  return (
    <div className="space-y-3" data-testid="admin-fx-rates">
      <header className="flex flex-wrap items-center gap-2">
        <h1 className="me-auto font-heading text-2xl font-bold text-text-primary">{t.title}</h1>
        <Badge tone="info">{t.base} <bdi dir="ltr">USD</bdi></Badge>
        <span className="inline-flex items-center gap-1 rounded-xl border border-border p-0.5 text-xs" role="group" aria-label={t.display} data-testid="fx-display">
          <span className="px-1.5 text-text-secondary">{t.display}</span>
          {(['USD', 'SAR'] as const).map((c) => (
            <button key={c} type="button" data-testid={`fx-display-${c}`} aria-pressed={pol.display_currency === c}
              onClick={() => policy.mutate({ display_currency: c })}
              className={`rounded-lg px-2 py-1 font-bold ${pol.display_currency === c ? 'bg-brand-600 text-white' : 'text-text-secondary'}`}>{c}</button>
          ))}
        </span>
        <Switch checked={pol.auto_source_enabled} onCheckedChange={(v) => policy.mutate({ auto_source_enabled: v })} label={t.auto} testId="fx-auto-source" />
      </header>

      {alerts.length > 0 && (
        <section className="flex flex-wrap gap-2" data-testid="fx-alerts" aria-label={t.alerts}>
          {alerts.map((a) => (
            <Badge key={a.id} tone={a.kind === 'fx_rate_missing' ? 'danger' : 'warning'}>
              <bdi dir="ltr">{a.currency}</bdi> {a.kind === 'fx_rate_missing' ? `✗ ${t.missing}` : `⚠ ${t.stale}`}
              {a.rate_date && <Num>{a.rate_date}</Num>}
              {a.accounts > 0 && <span>· <Num>{a.accounts}</Num> {t.accounts}</span>}
            </Badge>
          ))}
        </section>
      )}

      <div className="grid gap-3 lg:grid-cols-3">
        <section data-testid="fx-feed-state" className={`${card} ${ready ? '' : 'bg-[var(--warning-background)]'}`}>
          <h2 className={h2}><Coins size={16} aria-hidden /> {t.feedTitle}</h2>
          <p className={`mt-1 text-sm font-semibold ${ready ? 'text-text-primary' : 'text-warning'}`}>
            {ready ? (feed.label ?? t.ready) : feed.state === 'driver_not_configured' ? t.unusable : t.awaiting}
          </p>
          {!ready && <p className="text-xs text-text-secondary">{t.awaitingWhy}</p>}
          <dl className="mt-2 grid grid-cols-2 gap-2 text-xs">
            <div><dt className="text-text-secondary">{t.lastRate}</dt><dd className="tnum font-bold"><Num>{feed.last_rate_date ?? t.none}</Num></dd></div>
            <div><dt className="text-text-secondary">{t.ratesOnFile}</dt><dd className="tnum font-bold"><Num>{feed.rates}</Num></dd></div>
          </dl>
        </section>

        <section className={card}>
          <h2 className={h2}><AlertTriangle size={16} aria-hidden /> {t.unmetTitle}</h2>
          {unmet.length === 0 ? (
            <p data-testid="fx-unmet-empty" className="mt-2 text-xs text-text-secondary">{t.unmetEmpty}</p>
          ) : (
            <ul data-testid="fx-unmet" className="mt-2 grid gap-1.5">
              {unmet.map((p) => (
                <li key={`${p.base}-${p.quote}`} className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-surface-secondary px-2 py-1.5 text-xs">
                  <bdi dir="ltr" className="font-bold">{p.base} → {p.quote}</bdi>
                  <span><span className="tnum font-bold text-warning" dir="ltr">{p.withheld}</span> {t.withheld}
                    {' · '}{p.sources.map((s) => (s === 'advertising' ? t.advertising : t.commerce)).join('، ')}</span>
                </li>
              ))}
            </ul>
          )}
        </section>

        <GuardCard t={t} guard={guard} />
      </div>

      <section className={card}>
        <h2 className={h2}>{t.used}</h2>
        <div className="mt-2 overflow-x-auto">
          <table className="w-full text-xs" data-testid="fx-currencies">
            <thead><tr className="text-text-secondary">
              {[t.currency, t.accounts, t.last, t.date, t.kind, t.status, t.withheldN, ''].map((h, i) => <th key={i} className="px-2 py-1 text-start font-semibold">{h}</th>)}
            </tr></thead>
            <tbody>
              {currencies.map((c) => (
                <tr key={c.currency} className="border-t border-border" data-testid={`fx-cur-${c.currency}`}>
                  <td className="px-2 py-1.5 font-bold"><bdi dir="ltr">{c.currency}</bdi></td>
                  <td className="px-2"><Num className="tnum">{c.accounts}</Num></td>
                  <td className="px-2"><bdi dir="ltr" className="tnum">{c.rate === null ? '—' : c.rate.toPrecision(6)}</bdi></td>
                  <td className="px-2"><Num className="tnum">{c.rate_date ?? '—'}</Num></td>
                  <td className="px-2">{rateKindLabel(c.kind as RateKind | null, ar)}</td>
                  <td className="px-2"><StatusChip status={c.status} t={t} /></td>
                  <td className="px-2"><Num className="tnum">{c.withheld}</Num></td>
                  <td className="px-2">{c.status !== 'base' && <Button size="sm" variant="ghost" onClick={() => pick(c)} data-testid={`fx-pick-${c.currency}`}><Plus size={12} aria-hidden /> {t.record}</Button>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <div className="grid gap-3 lg:grid-cols-2">
        <section className={card}>
          <h2 className={h2}><Plus size={16} aria-hidden /> {t.addTitle}</h2>
          <form data-testid="fx-rate-form" className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
            <Field label={t.from} value={form.base} onChange={(v) => setForm({ ...form, base: v })} testId="fx-base" placeholder="EUR" />
            <Field label={t.to} value={form.quote} onChange={(v) => setForm({ ...form, quote: v })} testId="fx-quote" placeholder="USD" />
            <Field label={t.rate} value={form.rate} onChange={(v) => setForm({ ...form, rate: v })} testId="fx-rate" placeholder="1.12" inputMode="decimal" />
            <label className="text-xs font-semibold text-text-secondary">{t.start}
              <DateField value={form.rate_date} onChange={(v) => setForm({ ...form, rate_date: v })} data-testid="fx-date" />
            </label>
            <label className="text-xs font-semibold text-text-secondary">{t.end}
              <DateField value={form.effective_to} onChange={(v) => setForm({ ...form, effective_to: v })} data-testid="fx-end" />
            </label>
            <Field label={t.note} value={form.note} onChange={(v) => setForm({ ...form, note: v })} testId="fx-note" dir="auto" />
            <label className="col-span-2 flex items-center gap-2 text-xs font-semibold text-text-secondary sm:col-span-3">
              <input type="checkbox" checked={form.pegged} onChange={(e) => setForm({ ...form, pegged: e.target.checked })} data-testid="fx-pegged" /> {t.pegged}
            </label>
            <div className="col-span-2 flex flex-wrap items-center gap-2 sm:col-span-3">
              <Button type="submit" disabled={save.isPending} data-testid="fx-save"><Plus size={14} aria-hidden /> {save.isPending ? t.saving : t.save}</Button>
              {save.isError && <span data-testid="fx-error" className="text-xs text-danger">{toApiError(save.error).message}</span>}
              {save.isSuccess && !save.isPending && <span data-testid="fx-saved" className="text-xs text-success">{t.saved}</span>}
            </div>
          </form>
        </section>

        <PreviewCard t={t} ar={ar} />
      </div>

      <RecomputeCard t={t} onDone={refresh} />

      {rates.length > 0 && (
        <section className={card}>
          <h2 className={h2}>{t.ratesTitle}</h2>
          <ul data-testid="fx-rates-list" className="mt-2 grid gap-1">
            {rates.map((r) => <RateRow key={`${r.base}-${r.quote}-${r.rate_date}`} r={r} t={t} ar={ar} />)}
          </ul>
        </section>
      )}

      {workspaces.length > 0 && <WorkspacesCard t={t} workspaces={workspaces} platformDefault={pol.display_currency} onDone={refresh} />}
    </div>
  )
}

function StatusChip({ status, t }: { status: UsedCurrency['status']; t: T }) {
  if (status === 'missing') return <Badge tone="danger"><XCircle size={12} aria-hidden /> {t.missing}</Badge>
  if (status === 'stale') return <Badge tone="warning"><AlertTriangle size={12} aria-hidden /> {t.stale}</Badge>
  if (status === 'base') return <Badge tone="info">{t.baseChip}</Badge>
  return <Badge tone="success"><CheckCircle2 size={12} aria-hidden /> {t.valid}</Badge>
}

function GuardCard({ t, guard }: { t: T; guard: { ok: boolean; stored_without_rate: number; unit_unknown_but_converted: number; checked_from: string } | null }) {
  return (
    <section className={card} data-testid="fx-guard">
      <h2 className={h2}><CheckCircle2 size={16} aria-hidden /> {t.guard}</h2>
      {guard === null ? <p className="mt-2 text-xs text-text-secondary">—</p> : (
        <div className="mt-2 flex flex-wrap items-center gap-2 text-xs">
          <Badge tone={guard.ok ? 'success' : 'danger'}>{guard.ok ? t.guardOk : t.guardBad}</Badge>
          {!guard.ok && <Num>{guard.stored_without_rate + guard.unit_unknown_but_converted}</Num>}
          <span className="text-text-secondary"><Num>{guard.checked_from}</Num> →</span>
        </div>
      )}
    </section>
  )
}

function RateRow({ r, t, ar }: { r: { base: string; quote: string; rate: number; rate_date: string; source: string; kind?: string; effective_to?: string | null; note?: string | null; recorded_by?: string | null; version?: number }; t: T; ar: boolean }) {
  const [open, setOpen] = useState(false)
  const h = useQuery({ queryKey: ['admin', 'fx-history', r.base, r.quote], queryFn: () => fetchRateHistory(r.base, r.quote), enabled: open })

  return (
    <li className="rounded-lg bg-surface-secondary px-2 py-1.5 text-xs">
      <div className="flex flex-wrap items-center gap-2">
        <bdi dir="ltr" className="tnum font-semibold">{r.base} → {r.quote} · {r.rate}</bdi>
        {r.kind && <Badge tone={r.kind === 'pegged' ? 'info' : 'neutral'}>{rateKindLabel(r.kind as RateKind, ar)}</Badge>}
        <span className="tnum text-text-secondary" dir="ltr">{r.rate_date}{r.effective_to ? ` → ${r.effective_to}` : ''} · {r.source}</span>
        {r.version && r.version > 1 && <span className="text-text-secondary">{t.version}<Num>{r.version}</Num></span>}
        {r.note && <span className="truncate text-text-secondary" dir="auto">{r.note}</span>}
        <Button size="sm" variant="ghost" className="ms-auto" onClick={() => setOpen((o) => !o)} data-testid={`fx-history-${r.base}-${r.quote}`}><History size={12} aria-hidden /> {t.history}</Button>
      </div>
      {open && h.data && (
        <ol className="mt-1 grid gap-0.5 border-s-2 border-border ps-2" data-testid="fx-history">
          {h.data.versions.map((v) => (
            <li key={v.id} className="text-text-secondary"><bdi dir="ltr" className="tnum">
              {t.version}{v.version} · {v.previous_rate ?? '—'} → {v.rate} · {v.rate_date} · {v.kind}{v.outcome === 'shadowed' ? ` · ${t.shadowed}` : ''}{v.recorded_by ? ` · ${v.recorded_by}` : ''} · {v.recorded_at.slice(0, 16).replace('T', ' ')}
            </bdi></li>
          ))}
        </ol>
      )}
    </li>
  )
}

function PreviewCard({ t, ar }: { t: T; ar: boolean }) {
  const [p, setP] = useState({ amount: '253', from: 'EUR', to: 'USD', date: '' })
  const run = useMutation({ mutationFn: () => previewConversion({ amount: Number(p.amount), from: p.from.trim().toUpperCase(), to: p.to.trim().toUpperCase(), date: p.date }) })
  const out = run.data ? formatConversion(run.data, ar) : null

  return (
    <section className={card}>
      <h2 className={h2}><Calculator size={16} aria-hidden /> {t.preview}</h2>
      <form className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4" data-testid="fx-preview" onSubmit={(e) => { e.preventDefault(); run.mutate() }}>
        <Field label={t.amount} value={p.amount} onChange={(v) => setP({ ...p, amount: v })} testId="fx-preview-amount" inputMode="decimal" />
        <Field label={t.from} value={p.from} onChange={(v) => setP({ ...p, from: v })} testId="fx-preview-from" />
        <Field label={t.to} value={p.to} onChange={(v) => setP({ ...p, to: v })} testId="fx-preview-to" />
        <label className="text-xs font-semibold text-text-secondary">{t.date}<DateField value={p.date} onChange={(v) => setP({ ...p, date: v })} data-testid="fx-preview-date" /></label>
        <div className="col-span-2 flex flex-wrap items-center gap-2 sm:col-span-4">
          <Button type="submit" size="sm" disabled={run.isPending} data-testid="fx-preview-run">{t.calc}</Button>
          {out && run.data && (
            <span data-testid="fx-preview-result" className="flex flex-wrap items-center gap-2 text-xs">
              <bdi dir="ltr" className="tnum font-bold">{run.data.original_amount} {run.data.original_currency} =</bdi>
              {out.withheld
                ? <a href={out.link ?? '#'} className="font-bold text-warning">{out.text}</a>
                : <span className="tnum font-bold text-text-primary"><Num>{out.text}</Num></span>}
              {out.stale && <Badge tone="warning">⚠ {t.stale}</Badge>}
              {out.basis && <span className="tnum text-text-secondary" dir="ltr">{out.basis}</span>}
              {run.data.recorded_by && <span className="text-text-secondary">{t.by} <bdi dir="ltr">{run.data.recorded_by}</bdi></span>}
            </span>
          )}
          {run.isError && <span className="text-xs text-danger">{toApiError(run.error).message}</span>}
        </div>
      </form>
    </section>
  )
}

function RecomputeCard({ t, onDone }: { t: T; onDone: () => void }) {
  const [r, setR] = useState({ from: '', to: '' })
  const run = useMutation({ mutationFn: (apply: boolean) => recomputeConverted({ ...r, apply }), onSuccess: (d) => { if (d.applied) onDone() } })
  const s = run.data

  return (
    <section className={card}>
      <h2 className={h2}><RefreshCw size={16} aria-hidden /> {t.recompute}</h2>
      <div className="mt-2 flex flex-wrap items-end gap-2" data-testid="fx-recompute">
        <label className="text-xs font-semibold text-text-secondary">{t.start}<DateField value={r.from} onChange={(v) => setR({ ...r, from: v })} aria-label={`${t.recompute} · ${t.start}`} /></label>
        <label className="text-xs font-semibold text-text-secondary">{t.end}<DateField value={r.to} onChange={(v) => setR({ ...r, to: v })} aria-label={`${t.recompute} · ${t.end}`} /></label>
        <Button size="sm" variant="secondary" disabled={run.isPending || !r.from || !r.to} onClick={() => run.mutate(false)} data-testid="fx-recompute-dry">{t.dry}</Button>
        <Button size="sm" disabled={run.isPending || !s || s.applied} onClick={() => run.mutate(true)} data-testid="fx-recompute-apply">{t.apply}</Button>
        {run.isError && <span className="text-xs text-danger">{toApiError(run.error).message}</span>}
      </div>
      {s && (
        <div className="mt-2 grid gap-2 text-xs" data-testid="fx-recompute-summary">
          <div className="flex flex-wrap gap-2">
            <Badge tone="info">{t.changed} <Num>{s.changed}</Num></Badge>
            <Badge tone="success">{t.released} <Num>{s.released}</Num></Badge>
            <Badge tone="warning">{t.becameWithheld} <Num>{s.withheld}</Num></Badge>
            <Badge>{t.unchanged} <Num>{s.unchanged}</Num></Badge>
            {s.applied && <Badge tone="success">{t.budgets} <Num>{s.budgets_refreshed}</Num></Badge>}
          </div>
          {s.by_currency.length > 0 && (
            <table className="w-full max-w-md">
              <thead><tr className="text-text-secondary"><th className="text-start">{t.currency}</th><th className="text-start">{t.changed}</th><th className="text-start">{t.before}</th><th className="text-start">{t.after}</th></tr></thead>
              <tbody>{s.by_currency.map((b) => (
                <tr key={b.currency} className="border-t border-border"><td><bdi dir="ltr">{b.currency}</bdi></td><td><Num className="tnum">{b.changed}</Num></td><td><bdi dir="ltr" className="tnum">{b.before}</bdi></td><td><bdi dir="ltr" className="tnum">{b.after}</bdi></td></tr>
              ))}</tbody>
            </table>
          )}
        </div>
      )}
    </section>
  )
}

function WorkspacesCard({ t, workspaces, platformDefault, onDone }: { t: T; workspaces: Array<{ id: string; name: string; display_currency: 'USD' | 'SAR' | null; effective: 'USD' | 'SAR' }>; platformDefault: 'USD' | 'SAR'; onDone: () => void }) {
  const set = useMutation({ mutationFn: ({ id, c }: { id: string; c: 'USD' | 'SAR' | null }) => setWorkspaceDisplayCurrency(id, c), onSuccess: onDone })

  return (
    <section className={card}>
      <h2 className={h2}>{t.workspaces}</h2>
      <ul className="mt-2 grid gap-1 sm:grid-cols-2" data-testid="fx-workspaces">
        {workspaces.map((w) => (
          <li key={w.id} className="flex items-center justify-between gap-2 rounded-lg bg-surface-secondary px-2 py-1 text-xs">
            <span className="truncate font-semibold" dir="auto">{w.name}</span>
            <select aria-label={w.name} value={w.display_currency ?? ''} data-testid={`fx-ws-${w.id}`}
              onChange={(e) => set.mutate({ id: w.id, c: (e.target.value || null) as 'USD' | 'SAR' | null })}
              className="rounded-lg border border-border bg-surface px-2 py-1">
              <option value="">{t.platformDefault} ({platformDefault})</option>
              <option value="USD">USD</option>
              <option value="SAR">SAR</option>
            </select>
          </li>
        ))}
      </ul>
    </section>
  )
}

function Field({ label, value, onChange, testId, placeholder, inputMode, dir = 'ltr' }: {
  label: string
  value: string
  onChange: (v: string) => void
  testId: string
  placeholder?: string
  inputMode?: 'decimal'
  dir?: 'ltr' | 'auto'
}) {
  return (
    <label className="text-xs font-semibold text-text-secondary">
      {label}
      <input data-testid={testId} value={value} inputMode={inputMode} placeholder={placeholder} onChange={(e) => onChange(e.target.value)} dir={dir}
        className="mt-1 w-full rounded-xl border border-border bg-surface px-3 py-2 text-sm text-text-primary" />
    </label>
  )
}
