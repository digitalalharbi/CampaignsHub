import { lazy, Suspense } from 'react'
import { useSearchParams } from 'react-router-dom'
import { AlertTriangle, Bell, CheckCircle2, Clock, EyeOff, ListChecks, MailX, Send, ShieldAlert, UserMinus, Users } from 'lucide-react'
import { FitPage } from '@/components/fit/FitPage'
import { KpiMini, KpiStrip } from '@/components/fit/KpiMini'
import { TabStrip } from '@/components/fit/TabStrip'
import { Skeleton } from '@/components/ui/States'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { useAuth } from '@/stores/auth'
import { useExternalKpis, useKpis, useUsage, useWorkspaceSettings, useEntitlements } from './api'
import { useTypeWords } from './typeWords'
import { UsagePanel } from './UsagePanel'

const InternalSection = lazy(() => import('./InternalSection').then((m) => ({ default: m.InternalSection })))
const ExternalSection = lazy(() => import('./ExternalSection').then((m) => ({ default: m.ExternalSection })))
const FirstRunWizard = lazy(() => import('./FirstRunWizard').then((m) => ({ default: m.FirstRunWizard })))

type SectionKey = 'internal' | 'external' | 'usage'

/**
 * `/settings/workspace?tab=notifications` — ONE page for every workspace type (Owner spec §0, §2).
 *
 * Nothing here knows whether the workspace is an agency, a direct business or a client portal: roles, terms and default
 * routing arrive from the server as data. The per-type routes only mount this same component. The section is part of the
 * address (`&section=internal|external`), so a link opens where it says.
 */
export function WorkspaceNotificationsPage() {
  const settings = useWorkspaceSettings()
  const { t, ar } = useTypeWords(settings.data?.workspace.type)
  const [params, setParams] = useSearchParams()
  const asked = params.get('section')
  const section: SectionKey = asked === 'external' || asked === 'usage' ? asked : 'internal'
  const user = useAuth((s) => s.user)
  const can = useAuth((s) => s.hasPermission)
  const setSection = (k: string) => { const p = new URLSearchParams(params); p.set('section', k); p.delete('tab2'); setParams(p, { replace: true }) }

  return (
    <FitPage
      testid="workspace-notifications"
      title={t('title')}
      info={t('info')}
      actions={<UsageIndicator onOpen={() => setSection('usage')} data={settings.data} />}
      kpis={settings.data ? <Kpis section={section} data={settings.data} /> : undefined}
      toolbar={
        <TabStrip label={t('sectionLabel')} testid="section-toggle" active={section} onChange={setSection}
          items={[{ key: 'internal', label: t('internal'), icon: Bell }, { key: 'external', label: t('external'), icon: Send }]} />
      }
    >
      {settings.isLoading && <Skeleton className="h-48" />}
      {settings.isError && <QueryFailure error={settings.error} ar={ar} fallbackTitle={t('failed')} onRetry={() => void settings.refetch()} />}
      {settings.data && (
        <Suspense fallback={<Skeleton className="h-48" />}>
          {section === 'internal' && <InternalSection data={settings.data} />}
          {section === 'external' && <ExternalSection data={settings.data} userEmail={user?.email} isOwner={can('settings.manage')} />}
          {section === 'usage' && <UsagePanel data={settings.data} />}
          {settings.data.first_run && can('settings.manage') && <FirstRunWizard data={settings.data} userEmail={user?.email} />}
        </Suspense>
      )}
    </FitPage>
  )
}

function Kpis({ section, data }: { section: SectionKey; data: NonNullable<ReturnType<typeof useWorkspaceSettings>['data']> }) {
  const { t } = useTypeWords(data.workspace.type)
  const mine = useKpis().data
  const q = useExternalKpis().data
  // The external pipeline's own figures win (its «held for review» counts the review queue); ours are the fallback.
  const k = mine ? { ...mine, external: q ?? mine.external } : undefined
  const n = (v: number | null | undefined) => (v === null || v === undefined ? '-' : v)
  if (section === 'usage') return null
  if (section === 'internal') {
    return (
      <KpiStrip testid="kpis-internal" cols={3}>
        <KpiMini testid="kpi-team-unread" icon={Users} tone="info" label={t('kTeamUnread')} value={n(k?.internal.team_unread)} />
        <KpiMini testid="kpi-open-tasks" icon={ListChecks} tone="brand" label={t('kOpenTasks')} value={n(k?.internal.open_followup_tasks)} />
        <KpiMini testid="kpi-push-failures" icon={AlertTriangle} tone={k && k.internal.push_failures > 0 ? 'warning' : 'neutral'} label={t('kPushFail')} value={n(k?.internal.push_failures)} />
      </KpiStrip>
    )
  }

  return (
    <KpiStrip testid="kpis-external" cols={5}>
      <KpiMini testid="kpi-held-review" icon={Clock} tone="warning" label={t('kHeldReview')} value={n(k?.external.held_for_review)} />
      <KpiMini testid="kpi-held-privacy" icon={ShieldAlert} tone="warning" label={t('kHeldPrivacy')} value={n(k?.external.held_for_privacy)} />
      <KpiMini testid="kpi-sent-week" icon={CheckCircle2} tone="success" label={t('kSentWeek')} value={n(k?.external.sent_this_week)} />
      <KpiMini testid="kpi-failed" icon={MailX} tone={k && k.external.failed > 0 ? 'danger' : 'neutral'} label={t('kFailed')} value={n(k?.external.failed)} />
      <KpiMini testid="kpi-awaiting" icon={EyeOff} tone="info" label={t('kAwaiting')} value={n(k?.external.awaiting_confirmation)} />
      <KpiMini testid="kpi-unsub" icon={UserMinus} tone="neutral" label={t('kUnsub')} value={n(k?.external.unsubscribed_this_month)} />
    </KpiStrip>
  )
}

/** Recipients and monthly messages against the plan's limits — a button that opens the usage view. */
function UsageIndicator({ data, onOpen }: { data: ReturnType<typeof useWorkspaceSettings>['data']; onOpen: () => void }) {
  const { t } = useTypeWords(data?.workspace.type)
  const u = useUsage().data
  useEntitlements()
  const lim = (k: string) => (u?.limits[k] === null || u?.limits[k] === undefined ? '∞' : u.limits[k])

  return (
    <button type="button" data-testid="usage-indicator" onClick={onOpen} title={t('usageDetails')}
      className="flex items-center gap-3 rounded-xl border border-border bg-surface px-3 py-1.5 text-xs font-semibold text-text-secondary hover:bg-surface-hover">
      <span>{t('usageRecipients')}: <span dir="ltr" className="tnum">{u?.counts['notif.external_recipients'] ?? 0} / {lim('external_recipients')}</span></span>
      <span>{t('usageMessages')}: <span dir="ltr" className="tnum">{u?.counts['notif.external_messages'] ?? 0} / {lim('monthly_messages')}</span></span>
    </button>
  )
}
