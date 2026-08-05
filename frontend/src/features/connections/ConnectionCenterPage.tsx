import { useMemo, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  AlertTriangle, BarChart3, CheckCircle2, CreditCard, ExternalLink, FolderOpen, History,
  KeyRound, Megaphone, MessageSquare, Plug, RefreshCw, Search, ShieldAlert, Store, Wrench, X,
} from 'lucide-react'
import { useUi } from '@/stores/ui'
import { useAuth } from '@/stores/auth'
import { useProject } from '@/stores/project'
import { toApiError } from '@/lib/api/client'
import { fmtDateTime } from '@/lib/datetime'
import { AdPlatformsPanel } from '@/features/integrations/IntegrationsPage'
import { SearchableSelect } from '@/components/forms/SearchableSelect'
import type { Option } from '@/components/forms/types'
import {
  getConnectionHistory, listConnectors, syncConnector,
  type Connector, type ConnectionState, type SyncResult, type SyncRun,
} from './api'
import { sortPlatforms } from '@/lib/platforms'

/** Bilingual copy — self-contained to this feature (Arabic-first). */
const COPY = {
  ar: {
    title: 'التكاملات', subtitle: 'اربط منصاتك ومصادر بياناتك من مكان واحد — ملخّص وفئات وبحث وشبكة موصّلات بحالة صادقة لكل مزوّد ضمن المشروع الحالي.',
    pick_project: 'اختر مشروعًا', pick_project_hint: 'الاتصالات مرتبطة بالمشروع — اختر مشروعًا من المبدّل لعرض موصّلاته.',
    no_permission: 'لا تملك صلاحية عرض التكاملات.', loading: 'جارٍ التحميل…',
    capabilities: 'القدرات', sync_now: 'مزامنة الآن', syncing: 'جارٍ المزامنة…', history: 'سجل المزامنة',
    last_sync: 'آخر مزامنة', last_error: 'آخر خطأ', token_expires: 'انتهاء التوكن', never: 'لا يوجد', freshness: 'حداثة البيانات',
    honest_note: 'الحالات صادقة: لا يظهر «متصل/إنتاجي» إلا بعد مزامنة حقيقية ناجحة. المزوّدات بلا اعتماد تبقى «بانتظار اعتماد».',
    hist_runs: 'عمليات المزامنة', hist_errors: 'الأخطاء', hist_freshness: 'حداثة البيانات',
    run_status: 'الحالة', run_window: 'النطاق', run_metrics: 'المقاييس', run_started: 'البدء', run_finished: 'الانتهاء',
    no_runs: 'لا توجد عمليات مزامنة بعد.', no_errors: 'لا أخطاء.', close: 'إغلاق',
    fresh_last_run: 'آخر تشغيل', fresh_status: 'الحالة', fresh_metrics: 'المقاييس المحدّثة',
    sync_ok: 'اكتملت المزامنة', sync_awaiting: 'لم تُنفّذ: بانتظار اعتماد خارجي', records: 'سجلات', upserted: 'محدّث',
    // Summary tiles
    sum_total: 'إجمالي الموصّلات', sum_connected: 'متصل / موثّق إنتاجي', sum_action: 'يتطلّب إجراءً', sum_awaiting: 'بانتظار اعتماد',
    // Controls
    search_ph: 'ابحث عن موصّل…', status_ph: 'كل الحالات', all_tab: 'الكل', no_results: 'لا توجد موصّلات مطابقة.',
    // Card actions
    connect: 'ربط', fix: 'إصلاح', account: 'الحساب',
    // Drawer sections
    dr_account: 'الحساب', dr_permissions: 'الصلاحيات والقدرات', dr_bindings: 'ارتباطات المشروع',
    dr_history: 'سجل المزامنة', dr_token: 'التوكن', dr_availability: 'توفّر البيانات', dr_disconnect: 'قطع الاتصال',
    binding_project: 'المشروع الحالي', has_creds: 'الاعتماد', creds_yes: 'مُوفّر', creds_no: 'غير مُوفّر',
    awaiting_dep: 'بانتظار تبعية خارجية', yes: 'نعم', no: 'لا', conn_status: 'حالة الاتصال', conn_id: 'معرّف الاتصال',
    disconnect_note: 'قطع الاتصال يُدار بإلغاء الاعتماد لدى المزوّد — لا يُختلق إجراء قطع محلّي حتى تُوفَّر واجهة حقيقية.',
    drive_browse: 'تصفّح مجلدات Drive', drive_note: 'تصفّح الملفات والمجلدات يفتح ضمن Drive تحت فئة الملفات.',
    no_account: 'لا يوجد حساب متصل بعد.',
  },
  en: {
    title: 'Integrations', subtitle: 'Connect your platforms and data sources in one place — summary, categories, search, and a grid of connectors with an honest state per provider in the current project.',
    pick_project: 'Select a project', pick_project_hint: 'Connections are project-scoped — pick a project from the switcher to see its connectors.',
    no_permission: 'You do not have permission to view Integrations.', loading: 'Loading…',
    capabilities: 'Capabilities', sync_now: 'Sync now', syncing: 'Syncing…', history: 'Sync history',
    last_sync: 'Last sync', last_error: 'Last error', token_expires: 'Token expires', never: 'None', freshness: 'Data freshness',
    honest_note: 'States are honest: nothing shows "Connected/Production" without a real successful sync. Providers without credentials stay "Awaiting Credentials".',
    hist_runs: 'Runs', hist_errors: 'Errors', hist_freshness: 'Data freshness',
    run_status: 'Status', run_window: 'Window', run_metrics: 'Metrics', run_started: 'Started', run_finished: 'Finished',
    no_runs: 'No sync runs yet.', no_errors: 'No errors.', close: 'Close',
    fresh_last_run: 'Last run', fresh_status: 'Status', fresh_metrics: 'Metrics upserted',
    sync_ok: 'Sync complete', sync_awaiting: 'Did not run: awaiting external dependency', records: 'records', upserted: 'upserted',
    sum_total: 'Total connectors', sum_connected: 'Connected / Production', sum_action: 'Needs action', sum_awaiting: 'Awaiting credentials',
    search_ph: 'Search a connector…', status_ph: 'All statuses', all_tab: 'All', no_results: 'No matching connectors.',
    connect: 'Connect', fix: 'Fix', account: 'Account',
    dr_account: 'Account', dr_permissions: 'Permissions & capabilities', dr_bindings: 'Project bindings',
    dr_history: 'Sync history', dr_token: 'Token', dr_availability: 'Data availability', dr_disconnect: 'Disconnect',
    binding_project: 'Current project', has_creds: 'Credentials', creds_yes: 'Provisioned', creds_no: 'Not provisioned',
    awaiting_dep: 'Awaiting external dependency', yes: 'Yes', no: 'No', conn_status: 'Connection status', conn_id: 'Connection id',
    disconnect_note: 'Disconnection is managed by revoking credentials with the provider — no local disconnect action is fabricated until a real endpoint exists.',
    drive_browse: 'Browse Drive folders', drive_note: 'File and folder browsing opens within Drive under the Files category.',
    no_account: 'No account connected yet.',
  },
}
type Copy = (typeof COPY)['ar']
type Locale = 'ar' | 'en'

/** Distinct color per honest state — never reuse a hue so the badge reads unambiguously. */
const STATE_STYLE: Record<ConnectionState, string> = {
  available: 'bg-info/15 text-info',
  awaiting_credentials: 'bg-warning/15 text-warning',
  sandbox_verified: 'bg-purple/15 text-purple',
  production_verified: 'bg-success/15 text-success',
  permission_missing: 'bg-brand-500/15 text-brand-600',
  token_expired: 'bg-teal/15 text-teal',
  sync_failed: 'bg-danger/15 text-danger',
}

const STATE_LABEL: Record<ConnectionState, { ar: string; en: string }> = {
  available: { ar: 'متاح', en: 'Available' },
  awaiting_credentials: { ar: 'بانتظار اعتماد', en: 'Awaiting Credentials' },
  sandbox_verified: { ar: 'موثّق (تجريبي)', en: 'Sandbox Verified' },
  production_verified: { ar: 'موثّق (إنتاجي)', en: 'Production Verified' },
  permission_missing: { ar: 'صلاحية ناقصة', en: 'Permission Missing' },
  token_expired: { ar: 'انتهى التوكن', en: 'Token Expired' },
  sync_failed: { ar: 'فشلت المزامنة', en: 'Sync Failed' },
}

/** Category taxonomy (Ads/Analytics/Stores/Files/Messaging/Payment/Other). */
type Category = 'ads' | 'analytics' | 'stores' | 'files' | 'messaging' | 'payment' | 'other'
const CATEGORY_ORDER: Category[] = ['ads', 'analytics', 'stores', 'files', 'messaging', 'payment', 'other']
const CATEGORY_LABEL: Record<Category, { ar: string; en: string }> = {
  ads: { ar: 'الإعلانات', en: 'Ads' },
  analytics: { ar: 'التحليلات', en: 'Analytics' },
  stores: { ar: 'المتاجر', en: 'Stores' },
  files: { ar: 'الملفات', en: 'Files' },
  messaging: { ar: 'التواصل', en: 'Messaging' },
  payment: { ar: 'الدفع', en: 'Payment' },
  other: { ar: 'أخرى', en: 'Other' },
}
const CATEGORY_ICON: Record<Category, typeof Plug> = {
  ads: Megaphone, analytics: BarChart3, stores: Store, files: FolderOpen,
  messaging: MessageSquare, payment: CreditCard, other: Plug,
}

/** Provider → category map for the 16 backend providers (+ sandbox). Unknown providers fall to "other". */
const PROVIDER_CATEGORY: Record<string, Category> = {
  meta_ads: 'ads', google_ads: 'ads', tiktok_ads: 'ads', snapchat_ads: 'ads',
  linkedin_ads: 'ads', x_ads: 'ads', microsoft_ads: 'ads', pinterest_ads: 'ads',
  ga4: 'analytics', google_tag_manager: 'analytics',
  salla: 'stores', zid: 'stores', shopify: 'stores', woocommerce: 'stores',
  google_drive: 'files',
  crm: 'other', sandbox: 'other',
}
export function providerCategory(provider: string): Category {
  return PROVIDER_CATEGORY[provider] ?? 'other'
}

const NEEDS_ACTION: ConnectionState[] = ['permission_missing', 'token_expired', 'sync_failed']

export function ConnectionCenterPage() {
  const locale = useUi((s) => s.locale)
  const c = COPY[locale]
  const canView = useAuth((s) => s.hasPermission('integrations.view'))
  const { currentProjectId: projectId } = useProject()
  const [category, setCategory] = useState<Category | 'all'>('all')
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState<string | null>(null)
  const [openProvider, setOpenProvider] = useState<string | null>(null)

  const q = useQuery({
    queryKey: ['project', projectId, 'connections'],
    queryFn: () => listConnectors(projectId!),
    enabled: Boolean(projectId) && canView,
  })

  const connectors = useMemo(() => q.data ?? [], [q.data])

  const summary = useMemo(() => ({
    total: connectors.length,
    connected: connectors.filter((x) => x.state === 'production_verified').length,
    needsAction: connectors.filter((x) => NEEDS_ACTION.includes(x.state)).length,
    awaiting: connectors.filter((x) => x.state === 'awaiting_credentials').length,
  }), [connectors])

  // Only surface category tabs that actually have connectors (dense, no dead tabs).
  const activeCategories = useMemo(() => {
    const present = new Set(connectors.map((x) => providerCategory(x.provider)))
    return CATEGORY_ORDER.filter((cat) => present.has(cat))
  }, [connectors])

  const filtered = useMemo(() => {
    const term = search.trim().toLowerCase()
    const matching = connectors.filter((x) => {
      if (category !== 'all' && providerCategory(x.provider) !== category) return false
      if (status && x.state !== status) return false
      if (term && !x.label.toLowerCase().includes(term) && !x.provider.toLowerCase().includes(term)) return false
      return true
    })

    /*
     * The six ad platforms come FIRST (INTEG-UI-001).
     *
     * The grid was ordered by whatever the API returned, which put `sandbox` — a local fake provider
     * that exists so the product can be demonstrated without credentials — at the head of the list,
     * above Meta and Google, with a green "connected" chip. Somebody opening this page to connect
     * their advertising saw a connected generic connector first and the platforms they came for
     * eleventh.
     *
     * Sorted rather than filtered: the other eleven connectors are real and stay reachable. What
     * changes is which ones the page leads with, and that `sandbox` is last of all — it is the one
     * entry that is not a customer's integration at all.
     */
    // The six ad platforms, in the product's order (PLATFORM-ORDER-001). Spelled with the `_ads`
    // suffix because that is how connectors register; `platformRank` canonicalises before comparing.
    const AD_PLATFORM_ORDER = sortPlatforms(['meta_ads', 'google_ads', 'tiktok_ads', 'snapchat_ads', 'x_ads', 'linkedin_ads'])
    const isSandbox = (provider: string) => connectors.find((c) => c.provider === provider)?.is_sandbox === true
    const rank = (provider: string): number => {
      const ad = AD_PLATFORM_ORDER.indexOf(provider)
      if (ad !== -1) return ad
      if (isSandbox(provider)) return 999

      return 100
    }

    return [...matching].sort((a, b) => rank(a.provider) - rank(b.provider) || a.label.localeCompare(b.label))
  }, [connectors, category, status, search])

  const statusOptions: Option[] = CONNECTION_STATES_OPTIONS(locale)
  const openConnector = connectors.find((x) => x.provider === openProvider) ?? null

  if (!canView) {
    return (
      <div className="mx-auto w-full max-w-5xl p-4 md:p-6">
        <p className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-text-secondary">{c.no_permission}</p>
      </div>
    )
  }

  if (!projectId) {
    return (
      <div className="mx-auto flex w-full max-w-5xl flex-col gap-4 p-4 md:p-6">
        <h1 className="text-2xl font-extrabold tracking-tight text-text-primary">{c.title}</h1>

        {/*
          * The six ad platforms live above the project-scoped centre (INTEG-UI-001).
          *
          * Connecting Snapchat or Meta is a TENANT-level act — one authorisation serves every project —
          * so it must be reachable before a project has been chosen, which is why it sits outside the
          * project gate below rather than inside it.
          */}
        <AdPlatformsPanel />

        <p className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-text-secondary">
          <span className="block font-semibold text-text-primary">{c.pick_project}</span>
          {c.pick_project_hint}
        </p>
      </div>
    )
  }

  return (
    <div className="mx-auto flex w-full max-w-[1600px] flex-col gap-3 p-4 md:gap-4 md:p-6">
      <header className="flex flex-col gap-0.5">
        <h1 className="text-2xl font-extrabold tracking-tight text-text-primary">{c.title}</h1>
        <p className="text-sm text-text-secondary">{c.subtitle}</p>
      </header>

      {/*
        * The six ad platforms live above the project-scoped centre (INTEG-UI-001).
        *
        * Connecting Snapchat or Meta is a TENANT-level act — one authorisation serves every project —
        * so it must be reachable before a project has been chosen, which is why it sits outside the
        * project gate below rather than inside it.
        */}
      <AdPlatformsPanel />

      {/* Summary tiles */}
      <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-4">
        <StatTile icon={Plug} label={c.sum_total} value={summary.total} tone="neutral" />
        <StatTile icon={CheckCircle2} label={c.sum_connected} value={summary.connected} tone="success" />
        <StatTile icon={AlertTriangle} label={c.sum_action} value={summary.needsAction} tone="danger" />
        <StatTile icon={KeyRound} label={c.sum_awaiting} value={summary.awaiting} tone="warning" />
      </div>

      <p className="flex items-start gap-2 rounded-lg bg-surface-hover px-3 py-1.5 text-[11px] text-text-secondary">
        <AlertTriangle size={13} className="mt-0.5 shrink-0 text-warning" /> {c.honest_note}
      </p>

      {/* Tabs + Search + Status on one row */}
      <div className="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
        <div className="flex flex-wrap items-center gap-1 border-b border-border lg:border-b-0" role="tablist">
          <TabButton active={category === 'all'} onClick={() => setCategory('all')} label={c.all_tab} icon={Plug} />
          {activeCategories.map((cat) => {
            const Icon = CATEGORY_ICON[cat]
            return (
              <TabButton
                key={cat} active={category === cat} onClick={() => setCategory(cat)}
                label={CATEGORY_LABEL[cat][locale]} icon={Icon}
              />
            )
          })}
        </div>

        <div className="flex items-center gap-2">
          <div className="relative">
            <Search size={15} className="pointer-events-none absolute inset-y-0 my-auto ms-2.5 text-text-muted" />
            <input
              value={search} onChange={(e) => setSearch(e.target.value)} placeholder={c.search_ph}
              aria-label={c.search_ph}
              className="w-full min-w-[180px] rounded-lg border border-border bg-background py-1.5 ps-8 pe-2.5 text-sm text-text-primary placeholder:text-text-muted focus:border-brand-500 focus:outline-none sm:w-56"
            />
          </div>
          <div className="w-44">
            <SearchableSelect
              value={status} onChange={setStatus} options={statusOptions}
              placeholder={c.status_ph}
            />
          </div>
        </div>
      </div>

      {/* Grid */}
      {q.isLoading ? (
        <p className="p-8 text-center text-sm text-text-secondary">{c.loading}</p>
      ) : filtered.length === 0 ? (
        <p className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-text-secondary">{c.no_results}</p>
      ) : (
        <ul
          className="grid gap-3"
          style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(min(100%, 15.5rem), 1fr))' }}
        >
          {filtered.map((conn) => (
            <ConnectorCard
              key={conn.provider} c={c} locale={locale} projectId={projectId} conn={conn}
              onOpen={() => setOpenProvider(conn.provider)}
            />
          ))}
        </ul>
      )}

      {openConnector && (
        <ConnectorDrawer
          c={c} locale={locale} projectId={projectId} conn={openConnector}
          onClose={() => setOpenProvider(null)}
        />
      )}
    </div>
  )
}

/** Status filter options, localized (value = honest state; null = all). */
function CONNECTION_STATES_OPTIONS(locale: Locale): Option[] {
  return (Object.keys(STATE_LABEL) as ConnectionState[]).map((s) => ({
    value: s, label: STATE_LABEL[s][locale],
  }))
}

function StatTile({ icon: Icon, label, value, tone }: {
  icon: typeof Plug; label: string; value: number; tone: 'neutral' | 'success' | 'danger' | 'warning'
}) {
  const toneCls = tone === 'success' ? 'text-success bg-success/12'
    : tone === 'danger' ? 'text-danger bg-danger/12'
    : tone === 'warning' ? 'text-warning bg-warning/12'
    : 'text-text-secondary bg-surface-hover'
  return (
    <div className="flex items-center gap-2.5 rounded-xl border border-border bg-surface px-3 py-2.5">
      <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${toneCls}`}><Icon size={17} /></span>
      <div className="flex min-w-0 flex-col">
        <span className="text-lg font-extrabold leading-tight text-text-primary tnum">{value}</span>
        <span className="truncate text-[11px] text-text-secondary">{label}</span>
      </div>
    </div>
  )
}

function TabButton({ active, onClick, label, icon: Icon }: {
  active: boolean; onClick: () => void; label: string; icon: typeof Plug
}) {
  return (
    <button
      type="button" role="tab" aria-selected={active} onClick={onClick}
      className={`flex items-center gap-1.5 rounded-t-lg px-3 py-2 text-sm font-semibold transition-colors ${
        active ? 'border-b-2 border-brand-600 text-brand-600' : 'text-text-secondary hover:text-text-primary'
      }`}
    >
      <Icon size={15} /> {label}
    </button>
  )
}

function StateBadge({ state, label }: { state: ConnectionState; label: string }) {
  return <span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${STATE_STYLE[state]}`}>{label}</span>
}

type ActionKind = 'sync' | 'connect' | 'fix'
function primaryActionKind(state: ConnectionState): ActionKind {
  if (state === 'available' || state === 'awaiting_credentials') return 'connect'
  if (NEEDS_ACTION.includes(state)) return 'fix'
  return 'sync'
}

function ConnectorCard({ c, locale, projectId, conn, onOpen }: {
  c: Copy; locale: Locale; projectId: string; conn: Connector; onOpen: () => void
}) {
  const qc = useQueryClient()
  const [result, setResult] = useState<SyncResult | null>(null)
  const syncM = useMutation({
    mutationFn: () => syncConnector(projectId, conn.provider),
    onSuccess: (r) => {
      setResult(r)
      qc.invalidateQueries({ queryKey: ['project', projectId, 'connections'] })
      qc.invalidateQueries({ queryKey: ['connection-history', projectId, conn.provider] })
    },
  })
  const stateLabel = STATE_LABEL[conn.state]?.[locale] ?? conn.state_label
  const Icon = CATEGORY_ICON[providerCategory(conn.provider)]
  const kind = primaryActionKind(conn.state)
  return (
    <li>
      <button
        type="button" onClick={onOpen}
        className="flex h-full w-full flex-col gap-2.5 rounded-2xl border border-border bg-surface p-3 text-start transition-colors hover:border-brand-500 focus:border-brand-500 focus:outline-none"
      >
        <div className="flex items-start justify-between gap-2">
          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-surface-hover text-text-secondary"><Icon size={17} /></span>
          <StateBadge state={conn.state} label={stateLabel} />
        </div>

        <div className="flex min-w-0 flex-col gap-0.5">
          <span className="truncate font-semibold text-text-primary" title={conn.label}>{conn.label}</span>
          <span className="truncate text-[10px] text-text-tertiary tnum">{conn.provider}</span>
        </div>

        <div className="flex flex-col gap-0.5 text-[10px] text-text-tertiary">
          {/*
              The «الحساب» line used to print `connection.status` — the raw state enum, under a label
              that promised an account, next to a chip already showing that same state. There is no
              account identifier on this payload to put here, so nothing is claimed.
          */}
          <span className="truncate">{c.last_sync}: <span className="tnum">{fmt(conn.connection?.last_successful_sync_at ?? null) || c.never}</span></span>
        </div>

        <div className="mt-auto flex items-center gap-2 pt-0.5">
          {kind === 'sync' ? (
            <span
              role="button" tabIndex={0}
              onClick={(e) => { e.stopPropagation(); syncM.mutate() }}
              onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); e.stopPropagation(); syncM.mutate() } }}
              aria-disabled={syncM.isPending}
              className={`flex items-center gap-1.5 rounded-lg bg-brand-600 px-2.5 py-1 text-xs font-bold text-white hover:bg-brand-700 ${syncM.isPending ? 'opacity-50' : ''}`}
            >
              <RefreshCw size={12} className={syncM.isPending ? 'animate-spin' : ''} /> {syncM.isPending ? c.syncing : c.sync_now}
            </span>
          ) : (
            <span className={`flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-bold ${
              kind === 'fix' ? 'bg-danger/15 text-danger' : 'bg-brand-600 text-white'
            }`}>
              {kind === 'fix' ? <Wrench size={12} /> : <Plug size={12} />} {kind === 'fix' ? c.fix : c.connect}
            </span>
          )}
          {result && (
            <span className={`truncate text-[10px] font-semibold ${result.status === 'success' ? 'text-success' : 'text-warning'}`}>
              {result.status === 'success' ? c.sync_ok : c.sync_awaiting}
            </span>
          )}
        </div>
      </button>
    </li>
  )
}

function ConnectorDrawer({ c, locale, projectId, conn, onClose }: {
  c: Copy; locale: Locale; projectId: string; conn: Connector; onClose: () => void
}) {
  const qc = useQueryClient()
  const [result, setResult] = useState<SyncResult | null>(null)
  const [error, setError] = useState<string | null>(null)
  const syncM = useMutation({
    mutationFn: () => syncConnector(projectId, conn.provider),
    onSuccess: (r) => {
      setResult(r); setError(null)
      qc.invalidateQueries({ queryKey: ['project', projectId, 'connections'] })
      qc.invalidateQueries({ queryKey: ['connection-history', projectId, conn.provider] })
    },
    onError: (e) => setError(toApiError(e).message),
  })
  const hist = useQuery({
    queryKey: ['connection-history', projectId, conn.provider],
    queryFn: () => getConnectionHistory(projectId, conn.provider),
  })
  const data = hist.data
  const stateLabel = STATE_LABEL[conn.state]?.[locale] ?? conn.state_label
  const Icon = CATEGORY_ICON[providerCategory(conn.provider)]
  const isDrive = conn.provider === 'google_drive'

  return (
    <div className="fixed inset-0 z-[60] flex bg-black/40" onMouseDown={(e) => { if (e.target === e.currentTarget) onClose() }}>
      <div
        role="dialog" aria-modal="true" aria-label={conn.label}
        className="ms-auto flex h-full w-full max-w-[440px] flex-col overflow-y-auto border-s border-border bg-surface shadow-[var(--shadow-large)]"
      >
        {/* Header */}
        <div className="sticky top-0 z-10 flex items-start justify-between gap-3 border-b border-border bg-surface px-4 py-3">
          <div className="flex items-center gap-3">
            <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-surface-hover text-text-secondary"><Icon size={19} /></span>
            <div className="flex flex-col gap-0.5">
              <span className="font-bold text-text-primary">{conn.label}</span>
              <span className="text-[11px] text-text-tertiary tnum">{conn.provider}</span>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <StateBadge state={conn.state} label={stateLabel} />
            <button type="button" onClick={onClose} aria-label={c.close} className="rounded-lg p-1.5 text-text-muted hover:bg-surface-hover"><X size={17} /></button>
          </div>
        </div>

        <div className="flex flex-col gap-4 px-4 py-4">
          {/* Sync now (kept for every connector so the capability is never lost) */}
          <div className="flex flex-wrap items-center gap-2">
            <button
              onClick={() => syncM.mutate()} disabled={syncM.isPending}
              className="flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-brand-700 disabled:opacity-50"
            >
              <RefreshCw size={13} className={syncM.isPending ? 'animate-spin' : ''} /> {syncM.isPending ? c.syncing : c.sync_now}
            </button>
            {result && (
              <span className={`text-[11px] font-semibold ${result.status === 'success' ? 'text-success' : 'text-warning'}`}>
                {result.status === 'success' ? c.sync_ok : c.sync_awaiting}
                {' · '}<span className="tnum">{result.records} {c.records} · {result.metrics_upserted} {c.upserted}</span>
              </span>
            )}
            {error && <span className="text-[11px] font-semibold text-danger">{error}</span>}
          </div>

          {/* Account */}
          <Section title={c.dr_account} icon={Plug}>
            {conn.connection ? (
              <div className="grid grid-cols-2 gap-2">
                <Kv label={c.conn_status} value={conn.connection.status} />
                <Kv label={c.conn_id} value={conn.connection.id} mono />
              </div>
            ) : (
              <p className="text-xs text-text-secondary">{c.no_account}</p>
            )}
          </Section>

          {/* Permissions & capabilities */}
          <Section title={c.dr_permissions} icon={ShieldAlert}>
            <div className="flex flex-wrap gap-1.5">
              {conn.capabilities.map((cap) => (
                <span key={cap} className="rounded-md bg-surface-hover px-1.5 py-0.5 text-[11px] text-text-secondary">{cap}</span>
              ))}
            </div>
          </Section>

          {/* Project bindings */}
          <Section title={c.dr_bindings} icon={FolderOpen}>
            <Kv label={c.binding_project} value={projectId} mono />
          </Section>

          {/* Data availability */}
          <Section title={c.dr_availability} icon={BarChart3}>
            <div className="grid grid-cols-2 gap-2">
              <Kv label={c.has_creds} value={conn.has_credentials ? c.creds_yes : c.creds_no} />
              <Kv label={c.awaiting_dep} value={conn.awaiting_external_dependency ? c.yes : c.no} />
            </div>
            {isDrive && (
              <div className="mt-2 flex flex-col gap-1.5">
                <p className="text-[11px] text-text-tertiary">{c.drive_note}</p>
                <Link
                  to="/app/integrations/drive"
                  className="flex w-fit items-center gap-1.5 rounded-lg border border-border px-2.5 py-1.5 text-xs font-semibold text-text-secondary hover:border-brand-500 hover:text-brand-600"
                >
                  <ExternalLink size={13} /> {c.drive_browse}
                </Link>
              </div>
            )}
          </Section>

          {/* Token */}
          {conn.connection?.token_expires_at && (
            <Section title={c.dr_token} icon={KeyRound}>
              <Kv label={c.token_expires} value={fmt(conn.connection.token_expires_at) || c.never} />
            </Section>
          )}

          {/* Sync history */}
          <Section title={c.dr_history} icon={History}>
            {hist.isLoading || !data ? (
              <p className="py-4 text-center text-xs text-text-secondary">{c.loading}</p>
            ) : (
              <div className="flex flex-col gap-3">
                <div className="grid grid-cols-3 gap-2">
                  <FreshCell label={c.fresh_last_run} value={fmt(data.data_freshness.last_run_at) || c.never} />
                  <FreshCell label={c.fresh_status} value={data.data_freshness.last_status ?? c.never} />
                  <FreshCell label={c.fresh_metrics} value={String(data.data_freshness.metrics_upserted ?? 0)} />
                </div>

                <div className="overflow-x-auto rounded-xl border border-border">
                  <table className="w-full min-w-[420px] text-xs">
                    <thead className="bg-surface-hover text-[11px] text-text-secondary">
                      <tr>
                        <th className="p-2 text-start font-semibold">{c.run_status}</th>
                        <th className="p-2 text-start font-semibold">{c.run_metrics}</th>
                        <th className="p-2 text-start font-semibold">{c.run_started}</th>
                        <th className="p-2 text-start font-semibold">{c.run_finished}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.runs.map((r) => (
                        <tr key={r.id} className="border-t border-border">
                          <td className="p-2"><RunStatus status={r.status} /></td>
                          <td className="p-2 text-text-secondary tnum">{r.metrics_upserted ?? 0}</td>
                          <td className="p-2 text-text-tertiary tnum">{fmt(r.started_at)}</td>
                          <td className="p-2 text-text-tertiary tnum">{fmt(r.finished_at)}</td>
                        </tr>
                      ))}
                      {data.runs.length === 0 && <tr><td colSpan={4} className="p-4 text-center text-text-secondary">{c.no_runs}</td></tr>}
                    </tbody>
                  </table>
                </div>

                {data.errors.length > 0 && (
                  <ul className="flex flex-col gap-1.5">
                    {data.errors.map((r: SyncRun) => (
                      <li key={r.id} className="rounded-lg border border-danger/30 bg-danger/5 px-3 py-2 text-[11px] text-danger">
                        <span className="tnum">{fmt(r.started_at)}</span> — {r.error}
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            )}
          </Section>

          {/* Disconnect — honest: no local endpoint is fabricated */}
          <Section title={c.dr_disconnect} icon={AlertTriangle}>
            <p className="rounded-lg bg-surface-hover px-3 py-2 text-[11px] text-text-secondary">{c.disconnect_note}</p>
          </Section>
        </div>
      </div>
    </div>
  )
}

function Section({ title, icon: Icon, children }: { title: string; icon: typeof Plug; children: ReactNode }) {
  return (
    <section className="flex flex-col gap-2">
      <h3 className="flex items-center gap-1.5 text-xs font-bold text-text-secondary"><Icon size={13} /> {title}</h3>
      {children}
    </section>
  )
}

function Kv({ label, value, mono }: { label: string; value: string; mono?: boolean }) {
  return (
    <div className="flex flex-col gap-0.5 rounded-xl border border-border bg-background p-2.5">
      <span className="text-[10px] text-text-tertiary">{label}</span>
      <span className={`truncate text-xs font-semibold text-text-primary ${mono ? 'tnum' : ''}`} title={value}>{value}</span>
    </div>
  )
}

function FreshCell({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-col gap-0.5 rounded-xl border border-border bg-background p-2.5">
      <span className="text-[10px] text-text-tertiary">{label}</span>
      <span className="truncate text-xs font-semibold text-text-primary tnum" title={value}>{value}</span>
    </div>
  )
}

function RunStatus({ status }: { status: string }) {
  const cls = status === 'success' ? 'bg-success/15 text-success'
    : status === 'failed' ? 'bg-danger/15 text-danger'
    : status === 'running' ? 'bg-info/15 text-info'
    : 'bg-surface-hover text-text-secondary'
  return <span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${cls}`}>{status}</span>
}

function fmt(iso: string | null): string {
  const s = fmtDateTime(iso)
  return s === '—' ? '' : s
}
