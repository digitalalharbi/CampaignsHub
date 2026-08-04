import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, BellRing, CheckCircle2, Clock, ListChecks, Plus, Search, Settings2, Truck } from 'lucide-react'
import { FilterGroup, ViewCustomiser } from '@/components/ui/ViewCustomiser'
import { useUi } from '@/stores/ui'
import {
  createAlertRule, createTaskFromAlert, listAlertEvents, listAlertRules,
  resolveAlert, snoozeAlert, type AlertEvent, type AlertRule, type AlertType, type NewAlertRule,
} from './api'
import { listDeliveries, type NotificationDeliveryRow } from '@/features/notifications/api'
import { getData, putData } from '@/lib/api/client'
import { fmtDateTime } from '@/lib/datetime'
import { ErrorSummary, MultiSelectField, SelectField, type FieldError } from '@/components/forms'
import { useTaxonomyOptions } from '@/features/taxonomy/taxonomyApi'
import { toApiError } from '@/lib/api/client'

/** Bilingual copy — self-contained to this feature (Arabic-first). */
const COPY = {
  ar: {
    title: 'التنبيهات', subtitle: 'راقب المخاطر التشغيلية وتصرّف عليها — الميزانية، النتائج، المزامنة، والتوكنات.',
    tab_alerts: 'التنبيهات', tab_rules: 'القواعد', tab_prefs: 'التفضيلات', tab_deliveries: 'سجل التسليم',
    all: 'الكل', active: 'نشِطة', snoozed: 'مؤجّلة', resolved: 'مُغلقة', none: 'لا يوجد شيء هنا.',
    no_match: 'لا نتائج تطابق البحث أو الفلاتر.', search_ph: 'ابحث في التنبيهات…',
    sum_open: 'مفتوحة', sum_critical: 'حرِجة', sum_snoozed: 'مؤجّلة', sum_resolved: 'مُغلقة', sum_open_hint: 'تحتاج إجراء',
    resolve: 'إغلاق', snooze: 'تأجيل', create_task: 'إنشاء مهمة', task_created: 'أُنشئت المهمة',
    severity: 'الخطورة', source: 'المصدر', value: 'القيمة', threshold: 'الحد', triggered: 'أُطلق',
    sev_info: 'معلومة', sev_warning: 'تحذير', sev_critical: 'حرِج',
    new_rule: 'قاعدة جديدة', rule_name: 'اسم القاعدة', rule_type: 'النوع', cooldown: 'فترة التهدئة (دقائق)',
    channels: 'القنوات', create_task_toggle: 'إنشاء مهمة تلقائيًا', active_toggle: 'مُفعّلة', save: 'حفظ', load_error: 'تعذّر تحميل الخيارات', err_title: 'يرجى تصحيح الأخطاء التالية',
    threshold_hint: 'الحد (اختياري): نسبة/أيام/قيمة حسب النوع.',
    prefs_title: 'قنوات الإشعار وساعات الهدوء', ch_in_app: 'داخل التطبيق', ch_email: 'البريد', ch_whatsapp: 'واتساب',
    quiet: 'ساعات الهدوء', quiet_enabled: 'تفعيل ساعات الهدوء', from: 'من', to: 'إلى', saved: 'تم الحفظ',
    delivery_note: 'التسليم صادق: لا تُسجَّل أي رسالة كـ«مُرسلة» قبل ربط مزوّد حقيقي.',
    dl_channel: 'القناة', dl_status: 'الحالة', dl_type: 'النوع', dl_when: 'التاريخ',
    st_awaiting: 'بانتظار اعتماد المزوّد', st_sent: 'مُرسلة', st_suppressed: 'مكبوتة', st_failed: 'فشلت',
    st_quiet: 'مؤجّلة (ساعات الهدوء)', st_pref: 'مكبوتة (تفضيل)',
    minutes_60: 'ساعة', minutes_240: '4 ساعات', minutes_1440: 'يوم',
  },
  en: {
    title: 'Alerts', subtitle: 'Watch and act on operational risk — budget, results, sync, and tokens.',
    tab_alerts: 'Alerts', tab_rules: 'Rules', tab_prefs: 'Preferences', tab_deliveries: 'Delivery log',
    all: 'All', active: 'Active', snoozed: 'Snoozed', resolved: 'Resolved', none: 'Nothing here.',
    no_match: 'No alerts match your search or filters.', search_ph: 'Search alerts…',
    sum_open: 'Open', sum_critical: 'Critical', sum_snoozed: 'Snoozed', sum_resolved: 'Resolved', sum_open_hint: 'Need action',
    resolve: 'Resolve', snooze: 'Snooze', create_task: 'Create task', task_created: 'Task created',
    severity: 'Severity', source: 'Source', value: 'Value', threshold: 'Threshold', triggered: 'Triggered',
    sev_info: 'Info', sev_warning: 'Warning', sev_critical: 'Critical',
    new_rule: 'New rule', rule_name: 'Rule name', rule_type: 'Type', cooldown: 'Cooldown (minutes)',
    channels: 'Channels', create_task_toggle: 'Auto-create a task', active_toggle: 'Active', save: 'Save', load_error: 'Could not load options', err_title: 'Please fix the following errors',
    threshold_hint: 'Threshold (optional): pct / days / value depending on type.',
    prefs_title: 'Notification channels & quiet hours', ch_in_app: 'In-app', ch_email: 'Email', ch_whatsapp: 'WhatsApp',
    quiet: 'Quiet hours', quiet_enabled: 'Enable quiet hours', from: 'From', to: 'To', saved: 'Saved',
    delivery_note: 'Honest delivery: nothing is logged as "sent" before a real provider is wired.',
    dl_channel: 'Channel', dl_status: 'Status', dl_type: 'Type', dl_when: 'When',
    st_awaiting: 'Awaiting provider credentials', st_sent: 'Sent', st_suppressed: 'Suppressed', st_failed: 'Failed',
    st_quiet: 'Held (quiet hours)', st_pref: 'Suppressed (preference)',
    minutes_60: '1 hour', minutes_240: '4 hours', minutes_1440: '1 day',
  },
}

const TYPE_LABEL: Record<AlertType, { ar: string; en: string }> = {
  budget_risk: { ar: 'خطر الميزانية', en: 'Budget risk' },
  cpa_increase: { ar: 'ارتفاع CPA', en: 'CPA increase' },
  cpl_increase: { ar: 'ارتفاع CPL', en: 'CPL increase' },
  roas_drop: { ar: 'انخفاض ROAS', en: 'ROAS drop' },
  no_results: { ar: 'إنفاق بلا نتائج', en: 'No results' },
  sync_failure: { ar: 'فشل المزامنة', en: 'Sync failure' },
  token_expiry: { ar: 'انتهاء التوكن', en: 'Token expiry' },
  report_failed: { ar: 'فشل التقرير', en: 'Report failed' },
  sla_warning: { ar: 'تحذير SLA', en: 'SLA warning' },
}

const sevClass: Record<AlertEvent['severity'], string> = {
  info: 'bg-info', warning: 'bg-warning', critical: 'bg-danger',
}

type Tab = 'alerts' | 'rules' | 'prefs' | 'deliveries'
type EventFilter = 'open' | 'snoozed' | 'resolved'

export function AlertsPage() {
  const locale = useUi((s) => s.locale)
  const c = COPY[locale]
  const [tab, setTab] = useState<Tab>('alerts')

  const tabs: { id: Tab; label: string; icon: typeof BellRing }[] = [
    { id: 'alerts', label: c.tab_alerts, icon: BellRing },
    { id: 'rules', label: c.tab_rules, icon: ListChecks },
    { id: 'prefs', label: c.tab_prefs, icon: Settings2 },
    { id: 'deliveries', label: c.tab_deliveries, icon: Truck },
  ]

  return (
    <div className="flex w-full flex-col gap-4">
      <header className="flex flex-col gap-1">
        <h1 className="text-3xl font-extrabold tracking-tight text-text-primary">{c.title}</h1>
        <p className="text-sm text-text-secondary">{c.subtitle}</p>
      </header>

      <div className="flex flex-wrap gap-1 border-b border-border">
        {tabs.map((tb) => (
          <button
            key={tb.id}
            onClick={() => setTab(tb.id)}
            className={`flex items-center gap-2 rounded-t-lg px-3 py-2 text-sm font-semibold transition-colors ${
              tab === tb.id ? 'border-b-2 border-brand-600 text-brand-600' : 'text-text-secondary hover:text-text-primary'
            }`}
          >
            <tb.icon size={16} /> {tb.label}
          </button>
        ))}
      </div>

      {tab === 'alerts' && <AlertsTab c={c} locale={locale} />}
      {tab === 'rules' && <RulesTab c={c} locale={locale} />}
      {tab === 'prefs' && <PrefsTab c={c} />}
      {tab === 'deliveries' && <DeliveriesTab c={c} />}
    </div>
  )
}

type Copy = (typeof COPY)['ar']

function AlertsTab({ c, locale }: { c: Copy; locale: 'ar' | 'en' }) {
  const qc = useQueryClient()
  const [filter, setFilter] = useState<EventFilter>('open')
  const [sev, setSev] = useState<'all' | AlertEvent['severity']>('all')
  const [type, setType] = useState<'all' | string>('all')
  const [term, setTerm] = useState('')
  // Live update: poll every 20s so newly-raised alerts appear without a manual refresh.
  // Fetch the full ledger once (backend caps at 200) so the summary cards and filters stay client-side.
  const q = useQuery({ queryKey: ['alert-events', 'all'], queryFn: () => listAlertEvents(), refetchInterval: 20_000 })
  const invalidate = () => qc.invalidateQueries({ queryKey: ['alert-events'] })

  const resolveM = useMutation({ mutationFn: resolveAlert, onSuccess: invalidate })
  const snoozeM = useMutation({ mutationFn: ({ id, m }: { id: string; m: number }) => snoozeAlert(id, m), onSuccess: invalidate })
  const [tasked, setTasked] = useState<Record<string, boolean>>({})
  const taskM = useMutation({
    mutationFn: (e: AlertEvent) => createTaskFromAlert(labelFor(e, locale), messageFor(e, locale), e.project_id),
    onSuccess: (_d, e) => setTasked((t) => ({ ...t, [e.id]: true })),
  })

  const all = q.data ?? []
  const summary = {
    open: all.filter((e) => e.status === 'open').length,
    critical: all.filter((e) => e.status === 'open' && e.severity === 'critical').length,
    snoozed: all.filter((e) => e.status === 'snoozed').length,
    resolved: all.filter((e) => e.status === 'resolved').length,
  }

  const filters: { id: EventFilter; label: string }[] = [
    { id: 'open', label: c.active }, { id: 'snoozed', label: c.snoozed }, { id: 'resolved', label: c.resolved },
  ]
  const sevFilters: { id: 'all' | AlertEvent['severity']; label: string }[] = [
    { id: 'all', label: c.all }, { id: 'critical', label: c.sev_critical },
    { id: 'warning', label: c.sev_warning }, { id: 'info', label: c.sev_info },
  ]
  // Category (type) chips — only the types actually present in the ledger, to keep the row honest and short.
  const presentTypes = [...new Set(all.map((e) => e.type))]

  const needle = term.trim().toLowerCase()
  const events = all.filter((e) => {
    if (e.status !== filter) return false
    if (sev !== 'all' && e.severity !== sev) return false
    if (type !== 'all' && e.type !== type) return false
    if (!needle) return true
    const hay = `${labelFor(e, locale)} ${messageFor(e, locale)} ${TYPE_LABEL[e.type as AlertType]?.[locale] ?? e.type}`.toLowerCase()
    return hay.includes(needle)
  })

  return (
    <div className="flex flex-col gap-4">
      {/* Summary — status of the alert ledger at a glance. */}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <SummaryCard label={c.sum_open} value={summary.open} hint={c.sum_open_hint} tone="brand" />
        <SummaryCard label={c.sum_critical} value={summary.critical} tone="danger" />
        <SummaryCard label={c.sum_snoozed} value={summary.snoozed} tone="warning" />
        <SummaryCard label={c.sum_resolved} value={summary.resolved} tone="success" />
      </div>

      {/* Search + status/severity filters. */}
      <div className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-3 sm:flex-row sm:items-center sm:justify-between">
        <label className="relative flex w-full items-center sm:max-w-xs">
          <Search size={15} className="pointer-events-none absolute start-3 text-text-muted" aria-hidden />
          <input
            value={term}
            onChange={(ev) => setTerm(ev.target.value)}
            placeholder={c.search_ph}
            className="w-full rounded-xl border border-border bg-surface-secondary py-2 pe-3 ps-9 text-sm text-text-primary placeholder:text-text-muted focus:border-brand-500 focus:outline-none"
          />
        </label>
        {/*
          Status stays out in the open — open / snoozed / resolved is how the queue is WORKED, the
          same way tabs are. Severity and source are filters on top of that, and they were two more
          chip rows before the first alert; they fold, with the applied set spelled out (SIMPLIFY-002).
        */}
        <div className="flex flex-wrap items-center gap-2">
          {filters.map((f) => (
            <button
              key={f.id}
              onClick={() => setFilter(f.id)}
              className={`rounded-full px-3 py-1 text-xs font-semibold ${
                filter === f.id ? 'bg-brand-500 text-white' : 'bg-surface-hover text-text-secondary hover:text-text-primary'
              }`}
            >
              {f.label}
            </button>
          ))}
        </div>
      </div>

      <ViewCustomiser
        id="alerts"
        ar={locale === 'ar'}
        active={sev !== sevFilters[0]?.id || type !== 'all'}
        summary={
          [
            sev === sevFilters[0]?.id ? null : sevFilters.find((f) => f.id === sev)?.label,
            type === 'all' ? null : (TYPE_LABEL[type as AlertType]?.[locale] ?? type),
          ].filter(Boolean).join(' · ')
          || (locale === 'ar' ? 'كل الأهميات وكل المصادر' : 'Every severity, every source')
        }
        onClear={() => { setSev(sevFilters[0]!.id); setType('all') }}
      >
        <FilterGroup label={c.severity}>
          {sevFilters.map((f) => (
            <button
              key={f.id}
              onClick={() => setSev(f.id)}
              className={`rounded-full px-3 py-1 text-xs font-semibold ${
                sev === f.id ? 'bg-text-primary text-surface' : 'bg-surface-hover text-text-secondary hover:text-text-primary'
              }`}
            >
              {f.label}
            </button>
          ))}
        </FilterGroup>
        {presentTypes.length > 1 && (
          <FilterGroup label={c.source}>
            <button onClick={() => setType('all')}
              className={`rounded-full px-3 py-1 text-xs font-semibold ${type === 'all' ? 'bg-brand-500 text-white' : 'bg-surface-hover text-text-secondary hover:text-text-primary'}`}>
              {c.all}
            </button>
            {presentTypes.map((tp) => (
              <button key={tp} onClick={() => setType(type === tp ? 'all' : tp)}
                className={`rounded-full px-3 py-1 text-xs font-semibold ${type === tp ? 'bg-brand-500 text-white' : 'bg-surface-hover text-text-secondary hover:text-text-primary'}`}>
                {TYPE_LABEL[tp as AlertType]?.[locale] ?? tp}
              </button>
            ))}
          </FilterGroup>
        )}
      </ViewCustomiser>

      {events.length === 0 ? (
        <p className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-text-secondary">
          {all.length === 0 ? c.none : c.no_match}
        </p>
      ) : (
        <ul className="flex flex-col gap-2">
          {events.map((e) => (
            <li key={e.id} className="flex flex-col gap-2 rounded-2xl border border-border bg-surface p-4">
              <div className="flex items-start gap-3">
                <span className={`mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full ${sevClass[e.severity]}`} aria-hidden />
                <div className="flex flex-1 flex-col gap-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-semibold text-text-primary">{labelFor(e, locale)}</span>
                    <span className="rounded-full bg-surface-hover px-2 py-0.5 text-[11px] font-semibold text-text-secondary">
                      {c.source}: {TYPE_LABEL[e.type as AlertType]?.[locale] ?? e.type}
                    </span>
                    <span className="rounded-full bg-surface-hover px-2 py-0.5 text-[11px] font-semibold text-text-secondary">
                      {c.severity}: {sevLabel(e.severity, c)}
                    </span>
                  </div>
                  <p className="text-sm text-text-secondary">{messageFor(e, locale)}</p>
                  <ContextChips e={e} c={c} />
                  <span className="text-[11px] text-text-tertiary">{c.triggered}: {fmt(e.last_triggered_at)}</span>
                </div>
              </div>
              {filter !== 'resolved' && (
                <div className="flex flex-wrap gap-2">
                  <button
                    onClick={() => resolveM.mutate(e.id)}
                    className="flex items-center gap-1 rounded-lg border border-border px-2.5 py-1 text-xs font-semibold text-text-secondary hover:border-success hover:text-success"
                  >
                    <CheckCircle2 size={13} /> {c.resolve}
                  </button>
                  <SnoozeMenu c={c} onPick={(m) => snoozeM.mutate({ id: e.id, m })} />
                  <button
                    disabled={tasked[e.id] || taskM.isPending}
                    onClick={() => taskM.mutate(e)}
                    className="flex items-center gap-1 rounded-lg border border-border px-2.5 py-1 text-xs font-semibold text-text-secondary hover:border-brand-500 hover:text-brand-600 disabled:opacity-50"
                  >
                    <Plus size={13} /> {tasked[e.id] ? c.task_created : c.create_task}
                  </button>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

function SummaryCard({ label, value, hint, tone }: { label: string; value: number; hint?: string; tone: 'brand' | 'danger' | 'warning' | 'success' }) {
  const dot: Record<typeof tone, string> = { brand: 'bg-brand-500', danger: 'bg-danger', warning: 'bg-warning', success: 'bg-success' }
  return (
    <div className="flex flex-col gap-1 rounded-2xl border border-border bg-surface p-4">
      <div className="flex items-center gap-1.5">
        <span className={`h-2 w-2 rounded-full ${dot[tone]}`} aria-hidden />
        <span className="text-xs font-semibold text-text-secondary">{label}</span>
      </div>
      <span className="text-2xl font-extrabold tnum text-text-primary">{value}</span>
      {hint && <span className="text-[11px] text-text-tertiary">{hint}</span>}
    </div>
  )
}

function ContextChips({ e, c }: { e: AlertEvent; c: Copy }) {
  const ctx = e.context ?? {}
  const chips: string[] = []
  const num = (v: unknown) => (typeof v === 'number' ? Math.round(v * 100) / 100 : v)
  if ('spend' in ctx) chips.push(`${c.value}: ${num(ctx.spend)}`)
  if ('ratio' in ctx) chips.push(`${c.value}: ${Math.round(Number(ctx.ratio) * 100)}%`)
  if ('budget' in ctx) chips.push(`${c.threshold}: ${num(ctx.budget)}`)
  if ('roas_current' in ctx) chips.push(`ROAS: ${num(ctx.roas_current)} ← ${num(ctx.roas_previous)}`)
  if ('conversions' in ctx) chips.push(`${c.value}: ${num(ctx.conversions)}`)
  if ('expires_at' in ctx && ctx.expires_at) chips.push(`${c.threshold}: ${fmt(String(ctx.expires_at))}`)
  if (chips.length === 0) return null
  return (
    <div className="flex flex-wrap gap-1.5">
      {chips.map((ch) => (
        <span key={ch} className="rounded-md bg-surface-hover px-1.5 py-0.5 text-[11px] tnum text-text-secondary">{ch}</span>
      ))}
    </div>
  )
}

function SnoozeMenu({ c, onPick }: { c: Copy; onPick: (m: number) => void }) {
  const [open, setOpen] = useState(false)
  const opts = [
    { m: 60, label: c.minutes_60 }, { m: 240, label: c.minutes_240 }, { m: 1440, label: c.minutes_1440 },
  ]
  return (
    <div className="relative">
      <button
        onClick={() => setOpen((v) => !v)}
        className="flex items-center gap-1 rounded-lg border border-border px-2.5 py-1 text-xs font-semibold text-text-secondary hover:border-warning hover:text-warning"
      >
        <Clock size={13} /> {c.snooze}
      </button>
      {open && (
        <div className="absolute z-10 mt-1 flex flex-col overflow-hidden rounded-lg border border-border bg-surface shadow-lg">
          {opts.map((o) => (
            <button
              key={o.m}
              onClick={() => { setOpen(false); onPick(o.m) }}
              className="px-3 py-1.5 text-start text-xs font-semibold text-text-secondary hover:bg-surface-hover hover:text-text-primary"
            >
              {o.label}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}

function RulesTab({ c, locale }: { c: Copy; locale: 'ar' | 'en' }) {
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['alert-rules'], queryFn: listAlertRules })
  const [form, setForm] = useState<NewAlertRule>({ type: 'budget_risk', name: '', severity: 'warning', cooldown_minutes: 720, channels: ['in_app', 'email'], create_task: false, active: true })
  const [thresholdRaw, setThresholdRaw] = useState('')
  // Type / severity / channels are fed by the taxonomy engine (alert.type / alert.severity / alert.channel).
  // These are system definitions, so their option keys are exactly the AlertController Rule::in values.
  const typeTax = useTaxonomyOptions('alert.type')
  const severityTax = useTaxonomyOptions('alert.severity')
  const channelTax = useTaxonomyOptions('alert.channel')
  const createM = useMutation({
    mutationFn: () => {
      const threshold = parseThreshold(thresholdRaw, form.type)
      return createAlertRule({ ...form, threshold })
    },
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['alert-rules'] }); setForm((f) => ({ ...f, name: '' })); setThresholdRaw('') },
  })
  const rules = q.data?.rules ?? []
  /*
   * The server bounds this list (newest first). Saying so is not a footnote: a truncated list
   * presented as the whole set sends somebody looking for a rule that exists and concluding it was
   * deleted.
   */
  const total = q.data?.total ?? rules.length
  const capped = total > rules.length
  const summaryErrors: FieldError[] = createM.isError
    ? (() => { const api = toApiError(createM.error); return api.errors ? Object.entries(api.errors).flatMap(([f, m]) => (m?.length ? [{ field: f === 'name' ? 'rule-name' : f, message: m[0] }] : [])) : [] })()
    : []

  return (
    <div className="grid gap-4 md:grid-cols-[1fr_320px]">
      <div className="flex flex-col gap-2">
        {capped && (
          <p data-testid="alert-rules-capped" className="rounded-xl border border-border bg-surface-secondary px-3.5 py-2.5 text-[12.5px] text-text-secondary">
            {locale === 'ar'
              ? `تُعرض أحدث ${rules.length} قاعدة من ${total}.`
              : `Showing the most recent ${rules.length} of ${total} rules.`}
          </p>
        )}

        {rules.length === 0 ? (
          <p className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-text-secondary">{c.none}</p>
        ) : (
          rules.map((r: AlertRule) => (
            <div key={r.id} className="flex items-center justify-between gap-3 rounded-2xl border border-border bg-surface p-3.5">
              <div className="flex flex-col gap-0.5">
                <span className="font-semibold text-text-primary">{r.name}</span>
                <span className="text-xs text-text-secondary">
                  {TYPE_LABEL[r.type as AlertType]?.[locale] ?? r.type} · {c.severity}: {sevLabel(r.severity, c)} · {c.cooldown}: {r.cooldown_minutes}
                  {r.threshold ? ` · ${c.threshold}: ${Object.entries(r.threshold).map(([k, v]) => `${k}=${v}`).join(', ')}` : ''}
                </span>
                <span className="text-[11px] text-text-tertiary">{(r.channels ?? []).join(' · ')}{r.create_task ? ` · ${c.create_task_toggle}` : ''}</span>
              </div>
              <span className={`h-2 w-2 rounded-full ${r.active ? 'bg-success' : 'bg-border'}`} aria-hidden />
            </div>
          ))
        )}
      </div>

      <form
        onSubmit={(ev) => { ev.preventDefault(); createM.mutate() }}
        className="flex h-fit flex-col gap-3 rounded-2xl border border-border bg-surface p-4"
      >
        <h3 className="flex items-center gap-2 text-sm font-bold text-text-primary"><Plus size={15} /> {c.new_rule}</h3>
        {summaryErrors.length > 0 && <ErrorSummary errors={summaryErrors} title={c.err_title} />}
        <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary" htmlFor="rule-name">
          {c.rule_name}
          <input id="rule-name" required minLength={2} value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
            className="rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm text-text-primary" />
        </label>
        <SelectField
          label={c.rule_type}
          value={form.type}
          onChange={(v) => v && setForm((f) => ({ ...f, type: v as AlertType }))}
          options={typeTax.options}
          loading={typeTax.isPending}
          optionsError={typeTax.isError ? c.load_error : null}
          onRetry={() => typeTax.refetch()}
          clearable={false}
        />
        <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
          {c.threshold}
          <input value={thresholdRaw} onChange={(e) => setThresholdRaw(e.target.value)} placeholder="0.9"
            className="rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm text-text-primary" />
          <span className="text-[11px] font-normal text-text-tertiary">{c.threshold_hint}</span>
        </label>
        <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
          {c.cooldown}
          <input type="number" min={5} value={form.cooldown_minutes}
            onChange={(e) => setForm((f) => ({ ...f, cooldown_minutes: Number(e.target.value) }))}
            className="rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm text-text-primary" />
        </label>
        <SelectField
          label={c.severity}
          value={form.severity ?? 'warning'}
          onChange={(v) => v && setForm((f) => ({ ...f, severity: v as 'info' | 'warning' | 'critical' }))}
          options={severityTax.options}
          loading={severityTax.isPending}
          optionsError={severityTax.isError ? c.load_error : null}
          onRetry={() => severityTax.refetch()}
          clearable={false}
        />
        <MultiSelectField
          label={c.channels}
          value={form.channels ?? []}
          onChange={(v) => setForm((f) => ({ ...f, channels: v }))}
          options={channelTax.options}
          loading={channelTax.isPending}
          optionsError={channelTax.isError ? c.load_error : null}
          onRetry={() => channelTax.refetch()}
        />
        <label className="flex items-center gap-2 text-xs font-semibold text-text-secondary">
          <input type="checkbox" checked={form.create_task} onChange={(e) => setForm((f) => ({ ...f, create_task: e.target.checked }))} />
          {c.create_task_toggle}
        </label>
        <button type="submit" disabled={createM.isPending}
          className="rounded-lg bg-brand-600 px-3 py-2 text-sm font-bold text-white hover:bg-brand-700 disabled:opacity-50">
          {c.save}
        </button>
      </form>
    </div>
  )
}

interface NotifPrefs {
  channels: { in_app: boolean; email: boolean }
  quiet_hours: { enabled: boolean; start: string; end: string }
  categories: Record<string, { in_app: boolean; email: boolean }>
  available_categories?: string[]
}

function PrefsTab({ c }: { c: Copy }) {
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['settings', 'notifications'], queryFn: () => getData<NotifPrefs>('/settings/notifications') })
  const saveM = useMutation({
    mutationFn: (p: NotifPrefs) => putData('/settings/notifications', p),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['settings', 'notifications'] }),
  })
  const p = q.data
  if (!p) return <p className="p-8 text-center text-sm text-text-secondary">…</p>
  const set = (patch: Partial<NotifPrefs>) => saveM.mutate({ ...p, ...patch })

  return (
    <div className="flex max-w-lg flex-col gap-5 rounded-2xl border border-border bg-surface p-5">
      <h3 className="text-sm font-bold text-text-primary">{c.prefs_title}</h3>
      <div className="flex flex-col gap-2">
        <label className="flex items-center gap-2 text-sm text-text-secondary">
          <input type="checkbox" checked={p.channels.in_app} onChange={(e) => set({ channels: { ...p.channels, in_app: e.target.checked } })} /> {c.ch_in_app}
        </label>
        <label className="flex items-center gap-2 text-sm text-text-secondary">
          <input type="checkbox" checked={p.channels.email} onChange={(e) => set({ channels: { ...p.channels, email: e.target.checked } })} /> {c.ch_email}
        </label>
      </div>
      <div className="flex flex-col gap-2 border-t border-border pt-4">
        <label className="flex items-center gap-2 text-sm font-semibold text-text-primary">
          <input type="checkbox" checked={p.quiet_hours.enabled} onChange={(e) => set({ quiet_hours: { ...p.quiet_hours, enabled: e.target.checked } })} /> {c.quiet_enabled}
        </label>
        <div className="flex items-center gap-3">
          <label className="flex items-center gap-1.5 text-xs text-text-secondary">{c.from}
            <input type="time" lang="en-CA" dir="ltr" value={p.quiet_hours.start} onChange={(e) => set({ quiet_hours: { ...p.quiet_hours, start: e.target.value } })}
              className="rounded-lg border border-border bg-background px-2 py-1 text-sm text-text-primary" />
          </label>
          <label className="flex items-center gap-1.5 text-xs text-text-secondary">{c.to}
            <input type="time" lang="en-CA" dir="ltr" value={p.quiet_hours.end} onChange={(e) => set({ quiet_hours: { ...p.quiet_hours, end: e.target.value } })}
              className="rounded-lg border border-border bg-background px-2 py-1 text-sm text-text-primary" />
          </label>
        </div>
      </div>
      {saveM.isSuccess && <span className="text-xs font-semibold text-success">{c.saved}</span>}
    </div>
  )
}

function DeliveriesTab({ c }: { c: Copy }) {
  const q = useQuery({ queryKey: ['notification-deliveries'], queryFn: listDeliveries, refetchInterval: 30_000 })
  const rows = q.data ?? []
  return (
    <div className="flex flex-col gap-3">
      <p className="flex items-center gap-2 rounded-lg bg-surface-hover px-3 py-2 text-xs text-text-secondary">
        <AlertTriangle size={14} className="text-warning" /> {c.delivery_note}
      </p>
      <div className="overflow-x-auto rounded-2xl border border-border">
        <table className="w-full min-w-[520px] text-sm">
          <thead className="bg-surface-hover text-xs text-text-secondary">
            <tr>
              <th className="p-2.5 text-start font-semibold">{c.dl_type}</th>
              <th className="p-2.5 text-start font-semibold">{c.dl_channel}</th>
              <th className="p-2.5 text-start font-semibold">{c.dl_status}</th>
              <th className="p-2.5 text-start font-semibold">{c.dl_when}</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r: NotificationDeliveryRow) => (
              <tr key={r.id} className="border-t border-border">
                <td className="p-2.5 text-text-primary">{r.title || r.type}</td>
                <td className="p-2.5 text-text-secondary">{r.channel === 'email' ? c.ch_email : c.ch_in_app}</td>
                <td className="p-2.5"><DeliveryStatus status={r.status} c={c} /></td>
                <td className="p-2.5 text-xs text-text-tertiary">{fmt(r.created_at)}</td>
              </tr>
            ))}
            {rows.length === 0 && <tr><td colSpan={4} className="p-8 text-center text-text-secondary">{c.none}</td></tr>}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function DeliveryStatus({ status, c }: { status: string; c: Copy }) {
  const map: Record<string, { label: string; cls: string }> = {
    sent: { label: c.st_sent, cls: 'bg-success/15 text-success' },
    awaiting_credentials: { label: c.st_awaiting, cls: 'bg-warning/15 text-warning' },
    suppressed: { label: c.st_suppressed, cls: 'bg-surface-hover text-text-secondary' },
    suppressed_by_quiet_hours: { label: c.st_quiet, cls: 'bg-surface-hover text-text-secondary' },
    suppressed_by_preference: { label: c.st_pref, cls: 'bg-surface-hover text-text-secondary' },
    failed: { label: c.st_failed, cls: 'bg-danger/15 text-danger' },
  }
  const s = map[status] ?? { label: status, cls: 'bg-surface-hover text-text-secondary' }
  return <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${s.cls}`}>{s.label}</span>
}

// ---- helpers ----------------------------------------------------------------
function sevLabel(s: AlertEvent['severity'], c: Copy) {
  return s === 'info' ? c.sev_info : s === 'warning' ? c.sev_warning : c.sev_critical
}
function labelFor(e: AlertEvent, locale: 'ar' | 'en'): string {
  const t = TYPE_LABEL[e.type as AlertType]?.[locale] ?? e.type
  const title = (e.context && typeof e.context.title === 'string') ? e.context.title : null
  return title ?? t
}
function messageFor(e: AlertEvent, locale: 'ar' | 'en'): string {
  if (e.context && typeof e.context.message === 'string') return e.context.message
  return TYPE_LABEL[e.type as AlertType]?.[locale] ?? e.type
}
function parseThreshold(raw: string, type: AlertType): Record<string, number> | undefined {
  const n = Number(raw)
  if (!raw.trim() || Number.isNaN(n)) return undefined
  if (type === 'token_expiry' || type === 'no_results') return { days: n }
  if (type === 'roas_drop' || type === 'cpa_increase' || type === 'cpl_increase') return { pct: n }
  return { ratio: n }
}
const fmt = (iso: string | null): string => fmtDateTime(iso)
