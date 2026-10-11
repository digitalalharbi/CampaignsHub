import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { fetchExecutiveOperations, type ExecutiveMoney } from './api'
import { money } from '@/features/analytics/format'
import { PageIntro } from '@/components/ui/PageIntro'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { Select } from '@/components/ui/Select'
import { StatCard } from '@/components/ui/StatCard'
import { Skeleton } from '@/components/ui/States'
import { days as countedDays, minutes as countedMinutes } from '@/lib/counted'
import { Num } from '@/components/ui/Num'
import { usePortalBase } from '@/app/portalPath'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'

/**
 * EXECUTIVE-OPS-DASHBOARD-001 — one project view that joins paid media, lead generation and team
 * follow-up.
 *
 * The questions, in the order a manager asks them: what was spent, what it brought in, what each
 * lead cost, what happened after the lead arrived, and what needs a person now. Every figure is the
 * server's — `GET /leads/executive` joins the dashboard's spend to the follow-up workspace and
 * derives only the cost per lead. A money figure the contract will not state is shown as the reason
 * it is missing, never as a number and never as zero.
 *
 * Each block drills into the screen that owns its detail: spend → analytics by platform, leads →
 * the lead list, team → the follow-up workspace by owner.
 */
const RANGES = [7, 30, 90] as const

const REASONS: Record<string, { ar: string; en: string }> = {
  absent: { ar: 'لا إنفاق مسجّل لهذا المشروع في الفترة', en: 'No spend recorded for this project in the period' },
  no_project: { ar: 'اختر مشروعًا لعرض الإنفاق', en: 'Choose a project to see its spend' },
  withheld: { ar: 'لا سعر صرف موثوق لتحويل الإنفاق', en: 'No trustworthy rate to convert the spend' },
  partial: { ar: 'جزء من الإنفاق بلا سعر صرف؛ لا يُعرض رقم ناقص', en: 'Part of the spend has no rate; a short figure is not shown' },
  mixed_currency: { ar: 'الإنفاق بعملات مختلفة لا تُجمع', en: 'Spend in currencies that do not add up' },
  no_leads: { ar: 'لم يصل عميل محتمل', en: 'No lead arrived' },
  no_spend: { ar: 'لم يُنفق شيء؛ لا تكلفة لعميل', en: 'Nothing was spent, so no cost per lead' },
}

export function ExecutiveOpsPage() {
  const locale = useUi((s) => s.locale)
  const ar = locale === 'ar'
  const base = usePortalBase()
  const projectId = useProject((s) => s.currentProjectId)
  const [days, setDays] = useState<number>(30)

  const range = useMemo(() => {
    const to = new Date()
    const from = new Date(to)
    from.setDate(from.getDate() - (days - 1))

    return { from: from.toISOString().slice(0, 10), to: to.toISOString().slice(0, 10) }
  }, [days])

  const query = useQuery({
    queryKey: ['leads', 'executive', projectId, range.from, range.to],
    queryFn: () => fetchExecutiveOperations({ ...range, project_id: projectId ?? undefined }),
  })

  const d = query.data
  const count = (n: number): string => n.toLocaleString('en-US')
  const rate = (n: number | null | undefined): string => (n === null || n === undefined ? '—' : `${Math.round(n * 100)}%`)
  const reason = (m: ExecutiveMoney): string => {
    const key = m.reason === 'absent' && !projectId ? 'no_project' : (m.reason ?? 'absent')
    return REASONS[key]?.[ar ? 'ar' : 'en'] ?? key
  }
  const moneyValue = (m: ExecutiveMoney): string => (m.amount === null ? '—' : money(m.amount, m.currency))

  const attentionText = (kind: string, n: number): string => {
    const t: Record<string, { ar: string; en: string }> = {
      unassigned_leads: { ar: `عملاء محتملون بلا مسؤول: ${count(n)}`, en: `Leads with no owner: ${count(n)}` },
      overdue_follow_up: { ar: `متابعات تجاوزت موعدها: ${count(n)}`, en: `Follow-ups past their date: ${count(n)}` },
      never_contacted: { ar: `عملاء محتملون لم يُتواصل معهم: ${count(n)}`, en: `Leads never contacted: ${count(n)}` },
      spend_not_comparable: { ar: 'الإنفاق لا يمكن ذكره، فلا تُحسب تكلفة العميل', en: 'The spend cannot be stated, so no cost per lead is computed' },
    }
    return t[kind]?.[ar ? 'ar' : 'en'] ?? kind
  }
  const attentionLink = (kind: string): string =>
    kind === 'spend_not_comparable' ? `${base}/analytics?tab=quality` : kind === 'unassigned_leads' ? `${base}/leads` : `${base}/leads/workspace`

  const funnel = d
    ? [
        { key: 'received', label: ar ? 'وصل' : 'Received', value: d.leads.received },
        { key: 'contacted', label: ar ? 'تم التواصل' : 'Contacted', value: d.leads.contacted },
        { key: 'qualified', label: ar ? 'مؤهَّل' : 'Qualified', value: d.leads.qualified },
        { key: 'appointments', label: ar ? 'مواعيد' : 'Appointments', value: d.leads.appointments },
        { key: 'won', label: ar ? 'مكسوب' : 'Won', value: d.leads.won },
      ]
    : []
  const top = Math.max(1, ...funnel.map((f) => f.value))

  return (
    <section className="space-y-4" data-testid="executive-ops">
      <PageIntro
        testid="executive-intro"
        title={ar ? 'لوحة التشغيل التنفيذية' : 'Executive operations'}
        purpose={
          ar
            ? 'ما أُنفق، وما جلبه، وكم كلّف كل عميل، وما فعله الفريق بعد وصوله — في مكان واحد.'
            : 'What was spent, what it brought in, what each lead cost, and what the team did next — in one place.'
        }
      />

      <div className="flex flex-wrap items-end justify-between gap-3">
        <label className="flex flex-col gap-1">
          <span className="text-text-secondary text-xs">{ar ? 'الفترة' : 'Period'}</span>
          <Select
            value={String(days)}
            data-testid="executive-range"
            options={RANGES.map((n) => ({ value: String(n), label: ar ? `آخر ${countedDays(n, 'ar')}` : `Last ${countedDays(n, 'en')}` }))}
            onChange={(e) => setDays(Number(e.target.value))}
          />
        </label>
        {d && (
          <span className="text-text-muted text-xs" data-testid="executive-window" dir="ltr">
            {d.window.from} → {d.window.to}
          </span>
        )}
      </div>

      {query.isError ? (
        <QueryFailure error={query.error} ar={ar} fallbackTitle={ar ? "تعذّر تحميل اللوحة التنفيذية" : "The executive view could not load"} onRetry={() => void query.refetch()} />
      ) : !d ? (
        <Skeleton className="h-64" />
      ) : (
        <>
          {/* What needs a person — first, as sentences, each opening the screen that fixes it. */}
          <div className="rounded-2xl border border-border bg-surface p-4" data-testid="executive-attention">
            <h2 className="text-sm font-bold text-text-primary">{ar ? 'ما يحتاج انتباهك' : 'Needs attention'}</h2>
            {d.attention.length === 0 ? (
              <p className="mt-2 text-sm text-text-secondary" data-testid="executive-attention-clear">
                {ar ? 'لا شيء متأخر أو بلا مسؤول في هذه الفترة.' : 'Nothing is late or unowned in this period.'}
              </p>
            ) : (
              <ul className="mt-2 grid grid-cols-1 gap-2">
                {d.attention.map((a) => (
                  <li key={a.kind} data-testid={`executive-attention-${a.kind}`} className="flex min-w-0 flex-wrap items-center justify-between gap-2 rounded-xl bg-[var(--warning-background)] px-3 py-2 text-sm">
                    <span className="min-w-0 text-text-primary">{attentionText(a.kind, a.count)}</span>
                    <Link to={attentionLink(a.kind)} className="text-accent shrink-0 text-xs hover:underline">
                      {ar ? 'افتح' : 'Open'}
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </div>

          {/* Money and leads, joined: the one thing no other screen can answer. */}
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4" data-testid="executive-kpis">
            <StatCard
              testid="executive-spend"
              label={ar ? 'الإنفاق' : 'Spend'}
              value={moneyValue(d.spend)}
              hint={d.spend.amount === null ? reason(d.spend) : undefined}
              trailing={<Link to={`${base}/analytics?tab=platforms`} className="text-accent text-xs hover:underline">{ar ? 'حسب المنصة' : 'By platform'}</Link>}
            />
            <StatCard
              testid="executive-leads"
              label={ar ? 'عملاء محتملون' : 'Leads received'}
              value={count(d.leads.received)}
              hint={d.leads.invalid > 0 ? (ar ? `${count(d.leads.invalid)} غير صالح` : `${count(d.leads.invalid)} invalid`) : undefined}
              trailing={<Link to={`${base}/leads`} className="text-accent text-xs hover:underline">{ar ? 'القائمة' : 'List'}</Link>}
            />
            <StatCard
              testid="executive-cpl"
              label={ar ? 'تكلفة العميل المحتمل' : 'Cost per lead'}
              value={moneyValue(d.cost_per_lead)}
              hint={d.cost_per_lead.amount === null ? reason(d.cost_per_lead) : (ar ? 'بلا العملاء غير الصالحين' : 'Invalid leads excluded')}
            />
            <StatCard
              testid="executive-cpql"
              label={ar ? 'تكلفة العميل المؤهَّل' : 'Cost per qualified lead'}
              value={moneyValue(d.cost_per_qualified_lead)}
              hint={d.cost_per_qualified_lead.amount === null ? reason(d.cost_per_qualified_lead) : undefined}
            />
          </div>

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            {/* What happened after the lead arrived. Bars are counts of the same window, not a cohort. */}
            <div className="min-w-0 rounded-2xl border border-border bg-surface p-4" data-testid="executive-funnel">
              <h2 className="text-sm font-bold text-text-primary">{ar ? 'ما بعد وصول العميل' : 'After the lead arrived'}</h2>
              <ul className="mt-3 space-y-2">
                {funnel.map((f) => (
                  <li key={f.key} data-testid={`executive-funnel-${f.key}`} className="min-w-0">
                    <div className="flex items-baseline justify-between gap-2 text-xs">
                      <span className="text-text-secondary">{f.label}</span>
                      <span className="tnum font-semibold text-text-primary" dir="ltr">{count(f.value)}</span>
                    </div>
                    <div className="mt-1 h-2 rounded-full bg-surface-secondary">
                      <div className="h-2 rounded-full bg-brand-500" style={{ width: `${Math.round((f.value / top) * 100)}%` }} />
                    </div>
                  </li>
                ))}
              </ul>
            </div>

            {/* How the team performed — rates absent where their denominator was zero. */}
            <div className="min-w-0 rounded-2xl border border-border bg-surface p-4" data-testid="executive-team">
              <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="text-sm font-bold text-text-primary">{ar ? 'أداء الفريق' : 'Team follow-up'}</h2>
                <Link to={`${base}/leads/workspace`} className="text-accent text-xs hover:underline" data-testid="executive-team-link">
                  {ar ? 'حسب المسؤول' : 'By owner'}
                </Link>
              </div>
              <dl className="mt-3 grid grid-cols-2 gap-3 text-sm">
                {[
                  { k: 'contact_rate', l: ar ? 'نسبة التواصل' : 'Contact rate', v: rate(d.team.contact_rate) },
                  { k: 'qualification_rate', l: ar ? 'نسبة التأهيل' : 'Qualification rate', v: rate(d.team.qualification_rate) },
                  { k: 'win_rate', l: ar ? 'نسبة الكسب' : 'Win rate', v: rate(d.team.win_rate) },
                  {
                    k: 'first_response',
                    l: ar ? 'وسيط أول رد' : 'Median first response',
                    v: d.team.first_response.median_minutes === null ? '—' : countedMinutes(d.team.first_response.median_minutes, ar ? 'ar' : 'en'),
                  },
                  { k: 'overdue', l: ar ? 'متابعات متأخرة' : 'Overdue follow-ups', v: count(d.team.overdue) },
                ].map((x) => (
                  <div key={x.k} data-testid={`executive-team-${x.k}`} className="min-w-0">
                    <dt className="text-text-muted text-xs">{x.l}</dt>
                    <dd className="tnum font-semibold text-text-primary"><Num>{x.v}</Num></dd>
                  </div>
                ))}
              </dl>
              <p className="mt-3 text-text-muted text-xs" data-testid="executive-overdue-scope">
                {d.team.overdue_scope === 'all_open'
                  ? ar ? 'المتأخر يُحسب على كل العملاء المفتوحين، لا على الفترة وحدها.' : 'Overdue counts every open lead, not only the period.'
                  : null}
              </p>
            </div>
          </div>
        </>
      )}
    </section>
  )
}
