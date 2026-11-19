import { FxAmount } from '@/components/money/FxAmount'
import { moneyExact } from '@/features/analytics/format'
import { useRef, useState } from 'react'
import { useLocation, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, ArrowRight, Archive, Pause, Pencil, Play } from 'lucide-react'
import {
  activateCampaign,
  archiveCampaign,
  campaignAction,
  getCampaign,
  listLinkedExternal,
  unlinkExternal,
  updateCampaign,
} from './api'
import { CampaignFormModal } from './CampaignFormModal'
import { CampaignLaunchSuccess } from './CampaignLaunchSuccess'
import { readLaunchOutcome, type LaunchOutcome } from './launch'
import { listUsers } from '@/features/projects/api'
import { LinkExternalModal } from './LinkExternalModal'
import {
  PERFORMANCE_KEYS,
  PRIORITY_KEYS,
  STAGE_KEYS,
  campaignStatusLabel,
  campaignStatusTone,
  objectiveLabel,
  performanceLabel,
  performanceTone,
  priorityLabel,
  priorityTone,
  stageLabel,
  stageTone,
} from './labels'
import { isDemoProvider } from './types'
import {
  CampaignActivityTab,
  CampaignAlertsTab,
  CampaignBudgetTab,
  CampaignCreativesTab,
  CampaignExecutiveSummary,
  CampaignFunnelTab,
  CampaignKpis,
  CampaignNotesTab,
  CampaignPerformanceTab,
  CampaignPlatformsTab,
  CampaignReportsTab,
} from './CampaignCommandCenter'
import { usePeriod } from '@/lib/period/usePeriod'
import { DateRangePicker } from '@/components/ui/DateRangePicker'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardTitle } from '@/components/ui/Card'
import { ErrorState, NoPermission, Skeleton } from '@/components/ui/States'
import { Tabs, TabPanel, type TabItem } from '@/components/ui/Tabs'
import { useT } from '@/lib/i18n'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { CampaignAudienceTab, CampaignEventsTab, CampaignSyncLogTab } from './CampaignDepthTabs'
import { CampaignStructureTab } from './CampaignStructureTab'
import { RelatedEntitiesPanel } from './RelatedEntitiesPanel'
import { CampaignWriteControl } from './CampaignWriteControl'
import { CampaignControlPanel } from './CampaignControlPanel'
import { useProjectFromPath } from '@/features/projects/useProjectFromUrl'

/*
 * CAMPAIGN-PAGE-LEAN-001 — Owner 2026-11-19: sixteen tabs were too many, and the last ones were only reached by scrolling
 * the strip sideways. Eight remain, each a reading of the campaign; the operating and internal views live together under
 * «الإعدادات», and «المتابعة» gathers alerts, notes, reports and activity. Old `?tab=` links land on the tab that now
 * holds their content.
 */
const TAB_KEYS = ['overview', 'platforms', 'creatives', 'audience', 'funnel', 'budget', 'structure', 'follow', 'settings'] as const
type TabKey = (typeof TAB_KEYS)[number]
const TAB_MOVED: Record<string, TabKey> = {
  performance: 'overview', alerts: 'follow', reports: 'follow', activity: 'follow', notes: 'follow',
  events: 'settings', control: 'settings', sync: 'settings',
}

export function CampaignDetailPage() {
  const t = useT()
  const locale = useUi((s) => s.locale)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { projectId = '', campaignId = '' } = useParams()
  /*
   * Only an in-app path is honoured. `state` is attacker-controllable in principle — it survives a
   * history entry — so a value that is not a plain absolute path on this origin is discarded rather
   * than navigated to.
   */
  const location = useLocation()
  const fromState = (location.state as { from?: unknown } | null)?.from
  const cameFrom = typeof fromState === 'string' && /^\/[^/\\]/.test(fromState) ? fromState : null
  const [sp, setSp] = useSearchParams()
  // Resolve the owner id to a real member name (same source as the edit modal's owner select).
  const usersQ = useQuery({ queryKey: ['users'], queryFn: () => listUsers() })
  // DATE-RANGE-001 — the system's one period (shared with every page; rolling presets end yesterday).
  const { range } = usePeriod()


  const canView = useAuth((s) => s.hasPermission('campaigns.view'))
  const canUpdate = useAuth((s) => s.hasPermission('campaigns.update'))
  const canPause = useAuth((s) => s.hasPermission('campaigns.pause'))
  const canApprove = useAuth((s) => s.hasPermission('reports.approve'))

  const tabParam = sp.get('tab')
  const tab: TabKey = tabParam && (TAB_KEYS as readonly string[]).includes(tabParam)
    ? (tabParam as TabKey)
    : (tabParam && TAB_MOVED[tabParam]) || 'overview'
  const setTab = (key: string) => {
    const next = new URLSearchParams(sp)
    next.set('tab', key)
    setSp(next, { replace: true })
  }

  const [editOpen, setEditOpen] = [sp.get('edit') === '1', (v: boolean) => {
    const next = new URLSearchParams(sp)
    if (v) next.set('edit', '1')
    else next.delete('edit')
    setSp(next, { replace: true })
  }] as const
  const linkOpen = sp.get('link') === '1'
  const setLinkOpen = (v: boolean) => {
    const next = new URLSearchParams(sp)
    if (v) next.set('link', '1')
    else next.delete('link')
    setSp(next, { replace: true })
  }

  // Keep the global project switcher (and the agency client that owns it) aligned with the URL's project.
  useProjectFromPath(projectId)

  // Project- AND campaign-scoped query keys (isolation): ['projects', projectId, 'campaigns', campaignId, ...].
  const campaignQuery = useQuery({
    queryKey: ['projects', projectId, 'campaigns', campaignId, 'detail'],
    queryFn: () => getCampaign(projectId, campaignId),
    enabled: canView && Boolean(projectId && campaignId),
  })
  const linkedQuery = useQuery({
    queryKey: ['projects', projectId, 'campaigns', campaignId, 'platforms'],
    queryFn: () => listLinkedExternal(projectId, campaignId),
    enabled: canView && Boolean(projectId && campaignId),
  })

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['projects', projectId, 'campaigns', campaignId] })
    queryClient.invalidateQueries({ queryKey: ['projects', projectId, 'campaigns'] })
  }

  /*
   * LAUNCH-SUCCESS-001 — the launch moment lives in state that only a server response can fill.
   *
   * `launch` is set from `readLaunchOutcome`, which returns null unless the activate response both
   * carried `meta.launch` and came back with the campaign `active`. So a failed activation lands in
   * `onError` with `launch` still null and nothing to celebrate, and a pause — which returns no
   * launch payload at all — cannot set it either.
   *
   * It is component state and nothing else: no URL flag, no storage. A reload re-mounts this page
   * with `launch` back to null, so the celebration cannot replay for a launch that already happened
   * one navigation ago. And because `mutate` is disabled while the mutation is pending and the
   * activate button only renders while the campaign is NOT active, a second click has nothing to
   * fire at — the moment is bound to the transition, not to the button.
   */
  const [launch, setLaunch] = useState<LaunchOutcome | null>(null)

  /*
   * A second click inside the same tick must not post twice.
   *
   * `statusMutation.isPending` is the obvious guard and it is not enough: it only becomes true after
   * React re-renders, so two clicks landing before that both see `false` and both fire. A ref flips
   * synchronously, which is the only thing fast enough to stand between a pointer and a POST.
   */
  const transitionInFlight = useRef(false)
  const transition = (action: 'pause' | 'activate') => {
    if (transitionInFlight.current) return
    transitionInFlight.current = true
    statusMutation.mutate(action)
  }

  const statusMutation = useMutation({
    mutationFn: async (action: 'pause' | 'activate') => {
      if (action === 'pause') return campaignAction(projectId, campaignId, 'pause')

      const envelope = await activateCampaign(projectId, campaignId)
      setLaunch(readLaunchOutcome(envelope))

      return envelope.data
    },
    onSuccess: invalidate,
    onSettled: () => { transitionInFlight.current = false },
  })
  const archiveMutation = useMutation({
    mutationFn: () => archiveCampaign(projectId, campaignId),
    onSuccess: () => { invalidate(); navigate('/campaigns') },
  })
  const unlinkMutation = useMutation({
    mutationFn: (externalId: string) => unlinkExternal(projectId, campaignId, externalId),
    onSuccess: invalidate,
  })
  // Internal classification — a real PATCH to the campaign (audited server-side), never a UI-only badge.
  const classifyMutation = useMutation({
    mutationFn: (patch: { stage?: string; performance_label?: string; priority?: string }) =>
      updateCampaign(projectId, campaignId, patch),
    onSuccess: invalidate,
  })

  if (!canView) return <NoPermission />
  if (campaignQuery.isLoading) {
    return (
      <div className="space-y-3">
        <Skeleton className="h-8 w-56" />
        <Skeleton className="h-24 w-full" />
        <Skeleton className="h-40 w-full" />
      </div>
    )
  }
  if (campaignQuery.isError || !campaignQuery.data) {
    return <ErrorState error={campaignQuery.error} title={t('error')} onRetry={() => campaignQuery.refetch()} />
  }

  const c = campaignQuery.data
  const linked = linkedQuery.data ?? []
  const isDemo = linked.some((e) => isDemoProvider(e.provider))
  const platforms = [...new Set(linked.map((e) => e.provider))]
  const actionError = statusMutation.isError ? String(statusMutation.error) : null

  const tabs: TabItem[] = TAB_KEYS.map((k) => ({
    key: k,
    label: k === 'follow' ? (locale === 'ar' ? 'المتابعة' : 'Follow-up') : t(`tab_${k}` as never),
  }))

  const classSelect = (
    field: 'stage' | 'performance_label' | 'priority',
    value: string | null | undefined,
    keys: string[],
    toLabel: (k: string) => string,
  ) => (
    <select
      aria-label={field}
      disabled={!canUpdate || classifyMutation.isPending}
      value={value ?? ''}
      onChange={(e) => classifyMutation.mutate({ [field]: e.target.value } as never)}
      className="max-w-full rounded-lg border border-border bg-surface px-2 py-1 text-xs font-semibold text-text-primary disabled:opacity-60"
    >
      <option value="">{t('cmc_not_set')}</option>
      {keys.map((k) => <option key={k} value={k}>{toLabel(k)}</option>)}
    </select>
  )

  return (
    <section className="space-y-4">
      {/*
        * CAMPAIGN-DRILL-001 — back to where the reader actually came from.
        *
        * This always returned to the campaigns list, which was right while the list was the only way
        * in. Now a campaign is reachable from the dashboard ranking, the movers and the budget table,
        * and sending a reader who arrived from one of those to a list they never opened loses the
        * scope they were working in — the filters, the window, the place on the page.
        *
        * `state.from` is set by `CampaignLink`. Absent — a pasted URL, a refresh, an older link —
        * the campaigns list is still the right answer, so the fallback is the previous behaviour
        * rather than a dead control.
        */}
      <button
        onClick={() => navigate(cameFrom ?? '/campaigns')}
        className="inline-flex items-center gap-1 text-xs text-text-secondary hover:text-text-primary"
        data-testid="campaign-detail-back"
      >
        <ArrowRight size={14} className="rtl:rotate-180" /> {cameFrom === null ? t('back_to_campaigns') : t('back_to_where_you_were')}
      </button>

      {/* ===== Command-center header ===== */}
      <Card className="space-y-3">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <h1 className="font-[var(--font-heading)] text-xl font-extrabold">{c.client_display_name || c.name}</h1>
              <Badge tone={campaignStatusTone(c.status)}>{campaignStatusLabel(c.status, locale)}</Badge>
              {c.stage && <Badge tone={stageTone(c.stage)}>{stageLabel(c.stage, locale)}</Badge>}
              {c.performance_label && <Badge tone={performanceTone(c.performance_label)}>{performanceLabel(c.performance_label, locale)}</Badge>}
              {c.priority && <Badge tone={priorityTone(c.priority)}>{priorityLabel(c.priority, locale)}</Badge>}
              {isDemo && <Badge tone="warning">{t('demo_label')}</Badge>}
              {c.needs_attention && (
                <Badge tone="danger"><AlertTriangle size={12} className="me-1 inline" />{t('cmc_needs_attention')}</Badge>
              )}
            </div>
            <p className="mt-1 text-sm text-text-secondary">
              {objectiveLabel(c.objective, locale)}
              {c.client_display_name && c.client_display_name !== c.name && (
                <> · <span className="text-text-muted">{t('cmc_internal_name')}: {c.name}</span></>
              )}
            </p>
          </div>

          <div className="flex flex-wrap gap-2">
            {canUpdate && (
              <Button variant="secondary" onClick={() => setEditOpen(true)}><Pencil size={14} /> {t('edit')}</Button>
            )}
            {canPause && c.status === 'active' && (
              <Button variant="secondary" loading={statusMutation.isPending && statusMutation.variables === 'pause'}
                onClick={() => transition('pause')}><Pause size={14} /> {t('pause')}</Button>
            )}
            {canUpdate && c.status !== 'active' && c.status !== 'archived' && (
              <Button variant="secondary" loading={statusMutation.isPending && statusMutation.variables === 'activate'}
                onClick={() => transition('activate')}><Play size={14} /> {t('activate')}</Button>
            )}
            {canUpdate && (
              <Button variant="ghost" loading={archiveMutation.isPending} onClick={() => archiveMutation.mutate()}>
                <Archive size={14} /> {t('archive')}
              </Button>
            )}
          </div>
        </div>

        <CampaignLaunchSuccess outcome={launch} onDismiss={() => setLaunch(null)} />

      {/*
        * CAMPAIGN-PAGE-LEAN-001 — a header fact is drawn only when it has a value: «الفترة —», «المسؤول —», «نافذة الإسناد —»
        * said nothing. The stage, performance and priority choices are settings, not facts, and moved to «الإعدادات».
        */}
        {(() => {
          const owner = c.owner_id != null ? ((usersQ.data ?? []).find((u) => String(u.id) === String(c.owner_id))?.name ?? null) : null
          const facts = [
            c.starts_on || c.ends_on ? { key: 'period', label: t('period_label'), value: <span className="tnum text-xs">{`${c.starts_on ?? '…'} → ${c.ends_on ?? '…'}`}</span> } : null,
            c.total_budget !== null ? {
              key: 'budget', label: t('budget_label'),
              // FX-APPLY-001 — BOTH currencies on the campaign page: converted (display) and the platform's own.
              value: c.budget_fx ? <FxAmount c={c.budget_fx} ar={locale === 'ar'} showOriginal testId="campaign-detail-budget" /> : <span className="tnum">{moneyExact(c.total_budget, c.budget_currency)}</span>,
            } : null,
            owner ? { key: 'owner', label: t('owner_label'), value: owner } : null,
            c.attribution_window ? { key: 'attribution', label: t('cmc_attribution'), value: c.attribution_window } : null,
            c.primary_conversion_purpose ? { key: 'truth', label: t('cmc_source_of_truth'), value: c.primary_conversion_purpose } : null,
            platforms.length > 1 ? { key: 'linked', label: t('linked_count'), value: <span className="tnum">{platforms.length}</span> } : null,
          ].filter((f): f is NonNullable<typeof f> => f !== null)

          return facts.length === 0 ? null : (
            <div className="grid grid-cols-2 gap-x-6 gap-y-1.5 border-t border-border pt-3 text-sm sm:grid-cols-3 lg:grid-cols-5" data-testid="campaign-header-facts">
              {facts.map((f) => <HeaderFact key={f.key} label={f.label}>{f.value}</HeaderFact>)}
            </div>
          )
        })()}
      </Card>

      {actionError && <Alert severity="danger" title={actionError} />}

      <Tabs items={tabs} active={tab} onChange={setTab} />

      {tab === 'overview' && (
        <TabPanel>
          {/* CMC-2 — KPIs + executive summary (this campaign only). */}
          {/*
            `flex-wrap` + `min-w-0`: the period picker (DATE-RANGE-001) is ~300px with its label, and beside the title at 390px it
            pushed the page 25px sideways (first-viewport-detail, CI PR #1071). It now drops under the title when both do not fit.
          */}
          <div className="mb-4 flex min-w-0 flex-wrap items-center justify-between gap-2">
            <CardTitle>{t('cmc_performance')}</CardTitle>
            <DateRangePicker projectId={projectId} budgetHint testId="campaign-period-picker" />
          </div>
          <CampaignKpis campaign={c} projectId={projectId} range={range} />
          <div className="my-4">
            <CardTitle>الملخص التنفيذي</CardTitle>
            <div className="mt-2"><CampaignExecutiveSummary campaign={c} projectId={projectId} range={range} locale={locale} /></div>
          </div>
          {/* The same charts the Performance tab draws — daily line, platform donut, best and worst day — read here first. */}
          <div className="my-4" data-testid="campaign-overview-charts">
            <CampaignPerformanceTab campaign={c} projectId={projectId} range={range} locale={locale} />
          </div>
        </TabPanel>
      )}

      {tab === 'platforms' && (
        <TabPanel>
          <div className="mb-4 flex items-center justify-end"><DateRangePicker projectId={projectId} budgetHint testId="campaign-period-picker" /></div>
          {linkedQuery.isLoading ? (
            <Skeleton className="h-40 w-full" />
          ) : (
            <CampaignPlatformsTab
              campaign={c} projectId={projectId} range={range} locale={locale} linked={linked}
              onLink={() => setLinkOpen(true)} onUnlink={(id) => unlinkMutation.mutate(id)}
              unlinkingId={unlinkMutation.isPending ? (unlinkMutation.variables as string) : undefined}
              canUpdate={canUpdate}
            />
          )}
        </TabPanel>
      )}

      {tab === 'budget' && (
        <TabPanel>
          <div className="mb-4 flex items-center justify-end"><DateRangePicker projectId={projectId} budgetHint testId="campaign-period-picker" /></div>
          <CampaignBudgetTab campaign={c} projectId={projectId} range={range} locale={locale} />
        </TabPanel>
      )}
      {tab === 'funnel' && (
        <TabPanel>
          <div className="mb-4 flex items-center justify-end"><DateRangePicker projectId={projectId} budgetHint testId="campaign-period-picker" /></div>
          <CampaignFunnelTab campaign={c} projectId={projectId} range={range} />
        </TabPanel>
      )}
      {/* CAMPAIGN-PAGE-LEAN-001 — «المتابعة»: what is open on the campaign and what was done, in one place. */}
      {tab === 'follow' && (
        <TabPanel>
          <div className="space-y-4">
            <Card><CardTitle>{t('tab_alerts')}</CardTitle><div className="mt-3"><CampaignAlertsTab campaign={c} projectId={projectId} /></div></Card>
            <Card><CardTitle>{t('tab_notes')}</CardTitle><div className="mt-3"><CampaignNotesTab campaign={c} projectId={projectId} canUpdate={canUpdate} canApprove={canApprove} /></div></Card>
            <Card><CardTitle>{t('tab_reports')}</CardTitle><div className="mt-3"><CampaignReportsTab campaign={c} projectId={projectId} /></div></Card>
            <Card><CardTitle>{t('tab_activity')}</CardTitle><div className="mt-3"><CampaignActivityTab campaign={c} projectId={projectId} /></div></Card>
          </div>
        </TabPanel>
      )}
      {tab === 'creatives' && (
        <TabPanel>
          <div className="mb-4 flex items-center justify-end"><DateRangePicker projectId={projectId} budgetHint testId="campaign-period-picker" /></div>
          <CampaignCreativesTab campaign={c} projectId={projectId} range={range} locale={locale} />
        </TabPanel>
      )}
      {tab === 'structure' && (
        <TabPanel>
          <CampaignStructureTab campaign={c} projectId={projectId} />
        </TabPanel>
      )}
      {tab === 'audience' && (
        <TabPanel>
          <CampaignAudienceTab campaign={c} locale={locale} />
        </TabPanel>
      )}
      {/*
        * CAMPAIGN-PAGE-LEAN-001 — «الإعدادات»: the campaign's classification, its writes, and — folded, for whoever operates it —
        * the platform controls, the conversion events and the sync log that used to be three tabs of their own.
        */}
      {tab === 'settings' && (
        <TabPanel>
          <div className="space-y-4">
            <Card>
              <CardTitle>{locale === 'ar' ? 'التصنيف' : 'Classification'}</CardTitle>
              <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3" data-testid="campaign-classification">
                <HeaderFact label={t('cmc_stage')}>{classSelect('stage', c.stage, STAGE_KEYS, (k) => stageLabel(k, locale))}</HeaderFact>
                <HeaderFact label={t('cmc_performance')}>{classSelect('performance_label', c.performance_label, PERFORMANCE_KEYS, (k) => performanceLabel(k, locale))}</HeaderFact>
                <HeaderFact label={t('cmc_priority')}>{classSelect('priority', c.priority, PRIORITY_KEYS, (k) => priorityLabel(k, locale))}</HeaderFact>
              </div>
            </Card>
            <CampaignWriteControl projectId={projectId} />
            {/* CAMPAIGN-MGMT-WRITE-001 — the platform entities, operated from here. */}
            <details className="rounded-2xl border border-border bg-surface p-4">
              <summary className="cursor-pointer select-none text-sm font-semibold text-text-secondary">{t('tab_control')}</summary>
              <div className="mt-3"><CampaignControlPanel campaign={c} projectId={projectId} onOpenHistory={() => setTab('follow')} /></div>
            </details>
            <details className="rounded-2xl border border-border bg-surface p-4">
              <summary className="cursor-pointer select-none text-sm font-semibold text-text-secondary">{t('tab_events')}</summary>
              <div className="mt-3"><CampaignEventsTab campaign={c} projectId={projectId} range={range} /></div>
            </details>
            <details className="rounded-2xl border border-border bg-surface p-4">
              <summary className="cursor-pointer select-none text-sm font-semibold text-text-secondary">{t('tab_sync')}</summary>
              <div className="mt-3"><CampaignSyncLogTab campaign={c} projectId={projectId} /></div>
            </details>
          </div>
        </TabPanel>
      )}

      {/*
        Owner directive 2026-10-09 §7, §14 — the figures before the index. This panel is the campaign's
        drill-down map (platforms, ad accounts, ad sets, ads, creatives, alerts, reports); it used to
        sit between the header and the tabs and put the performance tab at 768 px on a 1366×768 screen.
      */}
      <RelatedEntitiesPanel projectId={projectId} campaignId={campaignId} />

      <CampaignFormModal open={editOpen} onClose={() => setEditOpen(false)} projectId={projectId} campaign={c} />
      <LinkExternalModal open={linkOpen} onClose={() => setLinkOpen(false)} projectId={projectId} campaignId={campaignId} />
    </section>
  )
}

function HeaderFact({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    /* `min-w-0` on the cell and `max-w-full` on what it holds: a native select is sized by its
       longest option, and a grid cell that may not shrink below it widened the whole document by
       364 px at 390 — the page scrolled sideways while the tab strip, which scrolls inside its own
       box, took the blame in the sweep's report. */
    <div className="flex min-w-0 flex-col gap-0.5">
      <span className="text-[11px] uppercase tracking-wide text-text-muted">{label}</span>
      <span className="min-w-0 max-w-full text-sm font-semibold text-text-primary">{children}</span>
    </div>
  )
}
