import { Link } from 'react-router-dom'
import { StatCard, type StatTone } from '@/components/ui/StatCard'
import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, Building2, FolderKanban, Inbox, Megaphone, ShieldCheck } from 'lucide-react'
import { fetchAgencyDashboard, type AgencyDashboard } from './api'
import { Skeleton } from '@/components/ui/States'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { useUi } from '@/stores/ui'
import { clients as countedClients } from '@/lib/counted'
import { CreativePulseSection } from '@/features/content/CreativePulseSection'
import type { LibraryQuery } from '@/features/content/api'

/**
 * `/agency/dashboard` — the agency's own overview (ADR 0002, AGENCY-002).
 *
 * Every figure here is computed on the server over the SAME set of clients this operator's client
 * list returns. When their membership names specific clients, the page says so above the numbers:
 * a partial view presented as the whole agency is worse than no dashboard, because it makes every
 * figure unexplainable to the person reading it.
 *
 * Zero means zero. An agency with no campaigns sees 0, never a sample figure.
 */

const OBJECTIVE_LABELS: Record<string, { ar: string; en: string }> = {
  sales: { ar: 'مبيعات', en: 'Sales' },
  awareness: { ar: 'وعي بالعلامة', en: 'Awareness' },
  traffic: { ar: 'زيارات', en: 'Traffic' },
  leads: { ar: 'عملاء محتملون', en: 'Leads' },
  engagement: { ar: 'تفاعل', en: 'Engagement' },
  app_installs: { ar: 'تثبيت التطبيق', en: 'App installs' },
  video_views: { ar: 'مشاهدات الفيديو', en: 'Video views' },
}

/** Latin digits everywhere, per the product's standing rule — never locale-native numerals. */
const num = (n: number) => n.toLocaleString('en-US')

/**
 * UX-KPI-PRESENTATION-001 — the agency dashboard's figures, on the product's own card.
 *
 * This drew its own: a rounded card, a tinted icon square, a 3xl tabular figure, a label under it.
 * The composition was fine and that was never the problem — the problem is that it was a SECOND
 * opinion about the label size, the value size, the padding and the height, on a page a reader
 * reaches from the same rail as every surface that uses the shared one.
 *
 * What stays local is what is genuinely this page's: which icon names the figure, which tone it
 * carries, and where pressing it goes. The card owns the type.
 */
function Metric({
  to,
  label,
  value,
  hint,
  icon: Icon,
  tone,
}: {
  to: string
  label: string
  value: number
  hint?: string
  icon: typeof Building2
  tone: StatTone
}) {
  const tones: Record<string, string> = {
    brand: 'bg-brand-primary-soft text-brand-700',
    success: 'bg-success/15 text-success',
    warning: 'bg-warning/15 text-warning',
    info: 'bg-info/15 text-info',
  }

  return (
    <Link to={to} className="block transition-colors [&>*]:hover:border-brand-400">
      <StatCard
        tone={tone}
        label={
          <span className="flex items-center gap-2">
            <span className={`flex h-7 w-7 items-center justify-center rounded-lg ${tones[tone] ?? ''}`}>
              <Icon size={15} aria-hidden />
            </span>
            {label}
          </span>
        }
        value={<span dir="ltr">{num(value)}</span>}
        hint={hint}
      />
    </Link>
  )
}

function ObjectiveBreakdown({ data, ar }: { data: AgencyDashboard['campaigns']; ar: boolean }) {
  const entries = Object.entries(data.by_objective).sort((a, b) => b[1] - a[1])
  const max = entries.length > 0 ? Math.max(...entries.map(([, c]) => c)) : 0

  return (
    <section className="rounded-2xl border border-border bg-surface p-5">
      <h2 className="font-heading text-lg font-extrabold text-text-primary">
        {ar ? 'الحملات حسب الهدف' : 'Campaigns by objective'}
      </h2>
      <p className="mt-1 text-sm text-text-secondary">
        {ar
          ? 'رقم واحد يخلط الأهداف لا يعني شيئًا — التوزيع هنا حسب هدف كل حملة.'
          : 'One blended number across objectives means nothing — this is the split by each campaign’s objective.'}
      </p>

      {entries.length === 0 ? (
        <p className="mt-4 rounded-xl border border-dashed border-border px-4 py-6 text-center text-sm text-text-muted">
          {ar ? 'لا توجد حملات ضمن نطاقك بعد.' : 'No campaigns within your scope yet.'}
        </p>
      ) : (
        <ul className="mt-4 space-y-3">
          {entries.map(([objective, count]) => {
            const label = OBJECTIVE_LABELS[objective]
            return (
              <li key={objective}>
                <div className="flex items-center justify-between gap-3 text-sm">
                  <span className="font-semibold text-text-primary">{label ? (ar ? label.ar : label.en) : objective}</span>
                  <span className="tnum font-bold text-text-secondary" dir="ltr">{num(count)}</span>
                </div>
                <div className="mt-1.5 h-2 overflow-hidden rounded-full bg-surface-secondary">
                  <div
                    className="h-full rounded-full bg-brand-500"
                    style={{ width: `${max === 0 ? 0 : Math.round((count / max) * 100)}%` }}
                  />
                </div>
              </li>
            )
          })}
        </ul>
      )}
    </section>
  )
}

export function AgencyDashboardPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const query = useQuery({ queryKey: ['agency', 'dashboard'], queryFn: fetchAgencyDashboard })

  if (query.isLoading) {
    return (
      <div className="grid gap-4">
        <Skeleton className="h-10 w-64" />
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          {[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-32" />)}
        </div>
        <Skeleton className="h-56" />
      </div>
    )
  }

  if (query.isError || !query.data) {
    // AGENCY-PERMS — signed in without an agency membership (the platform admin holds no tenant),
    // this endpoint answers 403. Offering «أعد المحاولة» there sends somebody to press a button
    // that will refuse them again.
    return (
      <QueryFailure
        error={query.error}
        ar={ar}
        testId="agency-dashboard-failure"
        onRetry={() => void query.refetch()}
        fallbackTitle={ar ? 'تعذّر تحميل لوحة الوكالة.' : 'The agency overview could not be loaded.'}
      />
    )
  }

  const d = query.data

  return (
    <div className="w-full">
      <header className="mb-5">
        <h1 className="font-heading text-3xl font-extrabold tracking-tight text-text-primary">
          {ar ? 'لوحة الوكالة' : 'Agency overview'}
        </h1>
        <p className="mt-1 text-sm text-text-secondary">
          {ar
            ? 'كل رقم هنا محسوب على العملاء الذين تصل إليهم فعليًا — لا أكثر.'
            : 'Every figure here covers the clients you can actually reach — and no others.'}
        </p>
      </header>

      {/* States the boundary before the numbers, so a subset is never read as the whole agency. */}
      <div
        data-testid="agency-scope-banner"
        className={`mb-4 flex items-start gap-2.5 rounded-xl border px-4 py-3 text-sm ${
          d.scope.is_restricted
            ? 'border-info/30 bg-info/10 text-text-primary'
            : 'border-border bg-surface-secondary text-text-secondary'
        }`}
      >
        <ShieldCheck size={17} className="mt-0.5 shrink-0 text-info" aria-hidden />
        <span>
          {d.scope.is_restricted
            ? ar
              ? `عضويتك محدّدة بعملاء بعينهم. الأرقام أدناه تغطي ${countedClients(d.scope.client_count, 'ar')} فقط.`
              : `Your membership names specific clients. The figures below cover ${countedClients(d.scope.client_count, 'en')} only.`
            : ar
              ? `الأرقام أدناه تغطي كامل عملاء الوكالة (${num(d.scope.client_count)}).`
              : `The figures below cover the whole agency (${countedClients(d.scope.client_count, 'en')}).`}
        </span>
      </div>

      <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Metric
          to="/agency/clients"
          label={ar ? 'العملاء' : 'Clients'}
          value={d.clients.total}
          hint={ar ? `${num(d.clients.active)} نشط` : `${num(d.clients.active)} active`}
          icon={Building2}
          tone="brand"
        />
        <Metric
          to="/agency/projects"
          label={ar ? 'المشاريع' : 'Projects'}
          value={d.projects.total}
          hint={ar ? `${num(d.projects.active)} نشط` : `${num(d.projects.active)} active`}
          icon={FolderKanban}
          tone="info"
        />
        <Metric
          to="/agency/campaigns"
          label={ar ? 'الحملات' : 'Campaigns'}
          value={d.campaigns.total}
          hint={ar
            ? `${num(d.campaigns.active)} نشطة · ${num(d.campaigns.paused)} موقوفة`
            : `${num(d.campaigns.active)} active · ${num(d.campaigns.paused)} paused`}
          icon={Megaphone}
          tone="success"
        />
        <Metric
          to="/agency/requests"
          label={ar ? 'طلبات مفتوحة' : 'Open requests'}
          value={d.requests.open}
          hint={ar
            ? `${num(d.requests.awaiting_client)} بانتظار العميل`
            : `${num(d.requests.awaiting_client)} awaiting the client`}
          icon={Inbox}
          tone="warning"
        />
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <ObjectiveBreakdown data={d.campaigns} ar={ar} />

        <section className="rounded-2xl border border-border bg-surface p-5">
          <h2 className="font-heading text-lg font-extrabold text-text-primary">
            {ar ? 'ما يحتاج انتباهك' : 'Needs your attention'}
          </h2>
          <ul className="mt-4 space-y-2.5 text-sm">
            <AttentionRow
              to="/agency/clients"
              label={ar ? 'عملاء يحتاجون متابعة' : 'Clients needing attention'}
              value={d.clients.needs_attention}
              ar={ar}
            />
            <AttentionRow
              to="/agency/clients"
              label={ar ? 'عملاء قيد التهيئة' : 'Clients onboarding'}
              value={d.clients.onboarding}
              ar={ar}
            />
            <AttentionRow
              to="/agency/requests"
              label={ar ? 'طلبات بانتظار رد العميل' : 'Requests awaiting the client'}
              value={d.requests.awaiting_client}
              ar={ar}
            />
            <AttentionRow
              to="/agency/campaigns"
              label={ar ? 'حملات موقوفة' : 'Paused campaigns'}
              value={d.campaigns.paused}
              ar={ar}
            />
          </ul>
        </section>
      </div>

      {/*
        §15.11 — the creative section, over the clients this operator actually reaches.

        This page carries no filters of its own, so the section renders its own: period, client,
        project, platform, objective and creative type. Every one of them narrows inside the
        membership ceiling the banner above describes — a control here cannot widen what the scope
        already decided, and the same options populate it that populate the library's filter bar.
      */}
      <div className="mt-4">
        <CreativePulseSection
          libraryPath="/agency/content"
          axes={['period', 'clients', 'projects', 'providers', 'objectives', 'kinds']}
          filters={AGENCY_WINDOW}
        />
      </div>
    </div>
  )
}

/**
 * The section's starting window, defined once outside the component.
 *
 * A fresh object literal on every render is a new query key on every render, which turns a cached
 * section into one that refetches whenever anything else on the page changes.
 */
const AGENCY_WINDOW: LibraryQuery = {}

function AttentionRow({ to, label, value, ar }: { to: string; label: string; value: number; ar: boolean }) {
  return (
    <li>
      <Link
        to={to}
        className="flex items-center justify-between gap-3 rounded-xl border border-border px-3.5 py-3 transition-colors hover:border-brand-400"
      >
        <span className="flex items-center gap-2 font-semibold text-text-primary">
          {value > 0 && <AlertTriangle size={15} className="shrink-0 text-warning" aria-hidden />}
          {label}
        </span>
        <span className="tnum shrink-0 font-bold text-text-secondary" dir="ltr">
          {value === 0 ? (ar ? 'لا شيء' : 'None') : num(value)}
        </span>
      </Link>
    </li>
  )
}
