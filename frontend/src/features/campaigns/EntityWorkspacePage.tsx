import { useMemo, useState } from 'react'
import { useLocation } from 'react-router-dom'
import { AgencyFit, FitPanel, useBoxHeight } from '@/components/fit/AgencyFit'
import { TabStrip } from '@/components/fit/TabStrip'
import { CampaignViewsBar } from './CampaignViewsBar'
import { LevelTabs } from './LevelTabs'
import type { SavedView } from '@/features/dashboard/savedViews'
import { useQuery } from '@tanstack/react-query'
import { Columns3 } from 'lucide-react'
import { EntityTab, type EntityWorkspaceControls } from '@/features/analytics/AnalyticsPage'
import { useEntities } from '@/features/analytics/api'
import { usePeriod } from '@/lib/period/usePeriod'
import { DateRangePicker } from '@/components/ui/DateRangePicker'
import { entityColumnLabel, entityColumnPlan } from '@/features/analytics/entityObjectiveColumns'
import { CampaignControlPanel } from './CampaignControlPanel'
import { ManagementOverview } from './ManagementOverview'
import { getCampaign } from './api'
import type { CampaignRelevance } from './campaignRelevance'
import { providerLabel } from './labels'
import { FilterBar, FilterSearch, FilterSelect } from '@/components/ui/FilterBar'
import { Modal } from '@/components/ui/Modal'
import { PageIntro } from '@/components/ui/PageIntro'
import { Skeleton } from '@/components/ui/States'
import { ProjectChooser } from '@/features/projects/ProjectChooser'
import type { EntityRow } from '@/features/analytics/api'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'

/**
 * ADV-CAMPAIGN-TABLES-001 — «المجموعات» and «الإعلانات» as operational tables of their own.
 *
 * The rail opened them as tabs of the analysis page: a reader who came to stop an ad landed among twelve
 * analysis tabs with the period, objective and campaign controls of a different job. These pages carry
 * one table each — the SAME `EntityTab` the analysis uses, so previews, drilldown, objective-aware
 * columns and relevance order are shared rather than re-implemented — with the controls an operator
 * reaches for: platform, state, a search, which columns to show, and «إدارة» on each row.
 *
 * «إدارة» opens the campaign's own provider control panel focused on that row. Every action in it is
 * the existing provider write path, authorised per campaign by `WriteCapabilityRegistry` on the server;
 * this page offers nothing the panel would not, and a row not linked to a unified campaign is offered
 * nothing at all.
 */
type Level = 'ad_set' | 'ad'

const HIDDEN_KEY = (level: Level) => `ch-entity-columns-hidden:${level}`

function readHidden(level: Level): string[] {
  try {
    const raw = localStorage.getItem(HIDDEN_KEY(level))
    const parsed: unknown = raw ? JSON.parse(raw) : []
    return Array.isArray(parsed) ? parsed.filter((k): k is string => typeof k === 'string') : []
  } catch {
    return []
  }
}

export function EntityWorkspacePage({ level }: { level: Level }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const { currentProjectId } = useProject()
  /* AF2 — under /agency the page fits the screen: the table, the overview and the drivers are three tabs. */
  const fit = useLocation().pathname.startsWith('/agency')
  const [tab, setTab] = useState<'list' | 'overview' | 'drivers'>('list')
  const box = useBoxHeight(fit)
  // DATE-RANGE-001 — the system's one period, shared with every page; rolling presets end yesterday.
  const { days, setDays, range } = usePeriod()
  const [platform, setPlatform] = useState('all')
  const [status, setStatus] = useState<'all' | CampaignRelevance>('all')
  const [search, setSearch] = useState('')
  const [hidden, setHidden] = useState<string[]>(() => readHidden(level))
  const [choosing, setChoosing] = useState(false)
  const [managing, setManaging] = useState<EntityRow | null>(null)

  const title = level === 'ad_set' ? (ar ? 'المجموعات' : 'Ad groups') : (ar ? 'الإعلانات' : 'Ads')
  const purpose = level === 'ad_set'
    ? (ar ? 'كل مجموعة في المشروع: أداؤها، حالتها، وإدارتها على المنصة حيث تسمح الصلاحيات.' : 'Every ad group in the project: how it performs, whether it is serving, and managing it on the platform where permissions allow.')
    : (ar ? 'كل إعلان في المشروع مع معاينته: أداؤه، حالته، وإدارته على المنصة حيث تسمح الصلاحيات.' : 'Every ad in the project with its preview: how it performs, whether it is serving, and managing it on the platform where permissions allow.')

  /* The unnarrowed list, for the platforms on offer and the columns the chooser lists — the same query key EntityTab reads. */
  const all = useEntities(currentProjectId, range, level, null, {})
  const platforms = useMemo(
    () => [...new Set((all.data?.entities ?? []).map((r) => r.provider).filter((p): p is string => Boolean(p)))].sort(),
    [all.data?.entities],
  )
  const columnKeys = useMemo(() => entityColumnPlan(all.data?.entities ?? []).keys, [all.data?.entities])

  const toggleColumn = (key: string) => {
    const next = hidden.includes(key) ? hidden.filter((k) => k !== key) : [...hidden, key]
    setHidden(next)
    try { localStorage.setItem(HIDDEN_KEY(level), JSON.stringify(next)) } catch { /* a private window keeps the choice for this visit only */ }
  }

  const applySavedView = (v: SavedView) => {
    const f = v.filters ?? {}
    setPlatform(Array.isArray(f.provider) && typeof f.provider[0] === 'string' ? f.provider[0] : 'all')
    setStatus(f.status === 'all' || typeof f.status !== 'string' ? 'all' : (f.status as CampaignRelevance))
    setSearch(typeof f.search === 'string' ? f.search : '')
    const next = Array.isArray(f.hidden) ? f.hidden.filter((k): k is string => typeof k === 'string') : []
    setHidden(next)
    try { localStorage.setItem(HIDDEN_KEY(level), JSON.stringify(next)) } catch { /* a private window keeps the choice for this visit only */ }
    if (typeof v.date_range?.days === 'number') setDays(v.date_range.days)
  }

  const workspace: EntityWorkspaceControls = { search, status, hidden, onManage: setManaging }
  const filters = platform === 'all' ? {} : { provider: [platform] }

  if (!currentProjectId) {
    return (
      <div className="space-y-6">
        <LevelTabs />
        <PageIntro title={title} purpose={purpose} />
        <ProjectChooser purpose="analytics" />
      </div>
    )
  }

  const statusOptions: Array<{ value: 'all' | CampaignRelevance; label: string }> = [
    { value: 'all', label: ar ? 'الكل' : 'All' },
    { value: 'serving', label: ar ? 'تعمل' : 'Serving' },
    { value: 'idle', label: ar ? 'متوقفة مؤقتًا' : 'Idle' },
    { value: 'stopped', label: ar ? 'متوقفة' : 'Stopped' },
  ]

  return (
    <AgencyFit className="space-y-4" testid={`entity-workspace-${level}`} active={tab !== 'drivers'}>
      {/* CAMPAIGN-LEVELS-001 — الحملات · المجموعات · الإعلانات, one strip, the query carried along. */}
      <LevelTabs />
      <PageIntro title={title} purpose={purpose} />

      <FilterBar
        id={`entity-${level}`}
        ar={ar}
        applied={[
          ...(platform === 'all' ? [] : [{ key: `platform:${platform}`, axis: ar ? 'المنصة' : 'Platform', label: providerLabel(platform, ar ? 'ar' : 'en'), onRemove: () => setPlatform('all') }]),
          ...(status === 'all' ? [] : [{ key: `status:${status}`, axis: ar ? 'الحالة' : 'State', label: statusOptions.find((o) => o.value === status)?.label ?? status, onRemove: () => setStatus('all') }]),
        ]}
        onReset={() => { setPlatform('all'); setStatus('all'); setSearch('') }}
        density={fit ? 'dense' : undefined}
        trailing={(
          <button
            type="button"
            onClick={() => setChoosing((v) => !v)}
            aria-expanded={choosing}
            data-testid={`entity-columns-toggle-${level}`}
            className="inline-flex items-center gap-1.5 rounded-lg border border-border px-2.5 py-1.5 text-xs font-bold text-text-secondary hover:border-brand-400 focus-visible:outline-2 focus-visible:outline-brand-500"
          >
            <Columns3 size={14} aria-hidden /> {ar ? 'الأعمدة' : 'Columns'}
          </button>
        )}
      >
        <DateRangePicker projectId={currentProjectId} testId={`entity-period-${level}`} />
        <FilterSelect
          label={ar ? 'المنصة' : 'Platform'}
          value={platform}
          options={[{ value: 'all', label: ar ? 'كل المنصات' : 'All platforms' }, ...platforms.map((p) => ({ value: p, label: providerLabel(p, ar ? 'ar' : 'en') }))]}
          onChange={setPlatform}
          testid={`entity-platform-${level}`}
        />
        <FilterSelect
          label={ar ? 'الحالة' : 'State'}
          value={status}
          options={statusOptions}
          onChange={(v) => setStatus(v as 'all' | CampaignRelevance)}
          testid={`entity-status-${level}`}
        />
        <FilterSearch value={search} onChange={setSearch} placeholder={ar ? 'ابحث بالاسم أو المعرّف…' : 'Search by name or id…'} testid={`entity-search-${level}`} />
      </FilterBar>

      {choosing && (
        <fieldset className="flex flex-wrap gap-2 rounded-xl border border-border bg-surface p-3" data-testid={`entity-columns-${level}`}>
          <legend className="px-1 text-xs font-bold text-text-secondary">{ar ? 'الأعمدة الظاهرة' : 'Visible columns'}</legend>
          {columnKeys.map((key) => (
            <label key={key} className="inline-flex items-center gap-1.5 rounded-lg border border-border px-2 py-1 text-xs text-text-primary">
              <input
                type="checkbox"
                checked={!hidden.includes(key)}
                onChange={() => toggleColumn(key)}
                data-testid={`entity-column-${level}-${key}`}
              />
              {entityColumnLabel(key, ar)}
            </label>
          ))}
        </fieldset>
      )}


      <div className={fit ? 'flex flex-wrap items-center justify-between gap-2' : 'contents'}>
      {fit && (
        <TabStrip
          label={ar ? 'أقسام الصفحة' : 'Page sections'}
          testid={`entity-tabs-${level}`}
          active={tab}
          onChange={(k) => setTab(k as 'list' | 'overview' | 'drivers')}
          items={[
            { key: 'list', label: ar ? 'القائمة' : 'List' },
            { key: 'overview', label: ar ? 'النظرة العامة' : 'Overview' },
            ...(level === 'ad_set' ? [{ key: 'drivers', label: ar ? 'ما الذي حرّكها' : 'What moved' }] : []),
          ]}
        />
      )}
      <CampaignViewsBar
        module={`entities-${level}`}
        testPrefix={`entity-${level}`}
        current={{ provider: platform === 'all' ? undefined : [platform], status, search: search || undefined, hidden }}
        days={days}
        onApply={applySavedView}
        ar={ar}
      />
      </div>
      {/* MGMT-PAGES-001 — the report's own blocks above the table: KPI strip, trend, where results came from. */}
      {(!fit || tab === 'overview') && (
        <div ref={fit ? box.ref : undefined} className={fit ? 'fit-panel' : undefined}>
          <ManagementOverview projectId={currentProjectId} range={range} filters={filters} testid={`entity-overview-${level}`} chartHeight={fit && box.height !== null ? Math.max(110, box.height - 315) : undefined} />
        </div>
      )}
      {(!fit || tab === 'list' || tab === 'drivers') && (
        fit
          ? <FitPanel><EntityTab projectId={currentProjectId} range={range} filters={filters} level={level} workspace={workspace} part={tab === 'drivers' ? 'diagnosis' : 'table'} /></FitPanel>
          : <EntityTab projectId={currentProjectId} range={range} filters={filters} level={level} workspace={workspace} />
      )}

      {managing && managing.unified_campaign_id && (
        <ManageDialog
          projectId={currentProjectId}
          campaignId={managing.unified_campaign_id}
          externalId={managing.external_id}
          name={managing.name ?? managing.external_id}
          ar={ar}
          onClose={() => setManaging(null)}
        />
      )}
    </AgencyFit>
  )
}

function ManageDialog({ projectId, campaignId, externalId, name, ar, onClose }: {
  projectId: string
  campaignId: string
  externalId: string
  name: string
  ar: boolean
  onClose: () => void
}) {
  const campaign = useQuery({ queryKey: ['projects', projectId, 'campaigns', campaignId], queryFn: () => getCampaign(projectId, campaignId), retry: false })

  return (
    <Modal open onClose={onClose} title={ar ? `إدارة: ${name}` : `Manage: ${name}`} size="lg">
      <div data-testid="entity-manage-dialog">
        {campaign.isPending && <Skeleton className="h-40" />}
        {campaign.isError && (
          <p role="alert" className="text-sm text-danger">
            {ar ? 'تعذّرت قراءة الحملة التي يتبعها هذا الصف.' : 'The campaign this row belongs to could not be read.'}
          </p>
        )}
        {campaign.data && <CampaignControlPanel campaign={campaign.data} projectId={projectId} focusExternalId={externalId} />}
      </div>
    </Modal>
  )
}
