import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { BarChart3, Layers, Megaphone, Store, type LucideIcon } from 'lucide-react'
import { fetchPortfolioOverview } from '@/features/projects/api'
import { listStoreProviders } from '@/features/commerce/api'
import { listMeasurementProviders } from '@/features/measurement/api'
import { Badge } from '@/components/ui/Badge'
import { DataFreshness, STALE_AFTER_HOURS } from '@/components/ui/PageIntro'
import { platformColor } from '@/features/analytics/components'
import { usePortalPath } from '@/app/portalPath'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'

/**
 * ADV-HOME-001 — the advertiser's dashboard says which project it is, what else they run, and where its data comes from.
 *
 * The Owner saw the advertiser portal unchanged while the agency's home grew a portfolio, attention lists and source
 * health. Two things an advertiser could not see from their dashboard at all: their OTHER projects (the switcher
 * auto-picks one and the page never mentions the rest), and whether their store and Google Analytics feed this
 * project — the store block simply did not render when there was no store, which reads the same as «no sales».
 *
 * Every state here is read from an endpoint that already serves it (the portfolio overview, the store and GA4
 * connection lists); nothing is computed in the browser that the server did not say. A source the reader may not
 * inspect (`integrations.view`) is not drawn rather than drawn as «not connected».
 */

const ATTENTION_AR: Record<string, string> = { no_accounts: 'بلا حسابات مربوطة', never_synced: 'لم تصل بيانات', stale: 'البيانات متأخرة' }
const ATTENTION_EN: Record<string, string> = { no_accounts: 'No linked accounts', never_synced: 'No data yet', stale: 'Data is behind' }

export function AdvertiserProjectsStrip({ currentProjectId, onSwitch }: { currentProjectId: string; onSwitch: (id: string) => void }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const overview = useQuery({ queryKey: ['portfolio-overview'], queryFn: () => fetchPortfolioOverview(), retry: false })
  const items = overview.data?.projects?.items ?? []
  if (items.length < 2) return null
  const campaigns = overview.data?.campaigns?.by_project ?? {}

  return (
    <nav aria-label={ar ? 'مشاريعك' : 'Your projects'} data-testid="adv-projects" className="flex flex-col gap-2">
      <h2 className="flex items-center gap-1.5 text-xs font-bold text-text-secondary"><Layers size={13} aria-hidden /> {ar ? 'مشاريعك' : 'Your projects'}</h2>
      <div className="flex gap-2 overflow-x-auto pb-1">
        {items.map((p) => {
          const current = p.id === currentProjectId
          const attention = p.attention === null ? null : (ar ? ATTENTION_AR : ATTENTION_EN)[p.attention] ?? p.attention
          return (
            <button
              key={p.id}
              type="button"
              aria-current={current ? 'true' : undefined}
              onClick={() => { if (!current) onSwitch(p.id) }}
              data-testid={`adv-project-${p.id}`}
              className={`flex min-w-[11rem] max-w-[16rem] shrink-0 flex-col items-start gap-1 rounded-xl border px-3 py-2 text-start transition-colors focus-visible:outline-2 focus-visible:outline-brand-500 ${current ? 'border-brand-500 bg-brand-500/10' : 'border-border bg-surface hover:border-brand-400'}`}
            >
              <span className="w-full truncate text-sm font-semibold text-text-primary">{p.name}</span>
              <span className="flex flex-wrap items-center gap-1.5 text-[11px] text-text-secondary">
                {p.providers.map((provider) => (
                  <span key={provider} className="h-1.5 w-1.5 rounded-full" style={{ background: platformColor(provider) }} aria-hidden />
                ))}
                <span className="tnum">{ar ? `الحملات: ${campaigns[p.id] ?? 0}` : `Campaigns: ${campaigns[p.id] ?? 0}`}</span>
              </span>
              {attention !== null
                ? <Badge tone="warning" data-testid={`adv-project-attention-${p.id}`}>{attention}</Badge>
                : <span className="text-[11px] text-text-muted"><DataFreshness lastSyncAt={p.data_last_synced_at} ar={ar} staleAfterHours={STALE_AFTER_HOURS} /></span>}
            </button>
          )
        })}
      </div>
    </nav>
  )
}

type SourceState = 'connected' | 'unassigned' | 'other_project' | 'none' | 'error'

type Source = { key: string; icon: LucideIcon; label: string; state: SourceState; detail: string | null; to: string }

const STATE_LABEL: Record<SourceState, { ar: string; en: string; tone: 'success' | 'warning' | 'neutral' | 'danger' }> = {
  connected: { ar: 'متصل', en: 'Connected', tone: 'success' },
  unassigned: { ar: 'متصل — لم يُربط بمشروع', en: 'Connected — not linked to a project', tone: 'warning' },
  other_project: { ar: 'مربوط بمشروع آخر', en: 'Linked to another project', tone: 'neutral' },
  none: { ar: 'غير متصل', en: 'Not connected', tone: 'neutral' },
  error: { ar: 'يحتاج إعادة ربط', en: 'Needs reconnecting', tone: 'danger' },
}

export function AdvertiserSourcesStrip({ projectId }: { projectId: string }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const portal = usePortalPath()
  const canSee = useAuth((s) => s.hasPermission('integrations.view'))
  const overview = useQuery({ queryKey: ['portfolio-overview'], queryFn: () => fetchPortfolioOverview(), retry: false })
  const stores = useQuery({ queryKey: ['commerce-stores'], queryFn: listStoreProviders, enabled: canSee, retry: false })
  const ga4 = useQuery({ queryKey: ['measurement-properties'], queryFn: listMeasurementProviders, enabled: canSee, retry: false })
  if (!canSee) return null

  const sources: Source[] = []
  const project = overview.data?.projects?.items.find((p) => p.id === projectId)
  if (project) {
    sources.push({
      key: 'ads', icon: Megaphone, label: ar ? 'الإعلانات' : 'Ads',
      state: project.accounts > 0 ? 'connected' : 'none',
      detail: project.accounts > 0 ? project.providers.join(' · ') : null,
      to: `/projects/${projectId}/integrations`,
    })
  }
  if (stores.isSuccess) {
    const all = stores.data.flatMap((p) => p.stores.map((s) => ({ ...s, provider: p })))
    const mine = all.find((s) => s.project_id === projectId)
    const broken = stores.data.find((p) => p.state === 'error')
    sources.push({
      key: 'store', icon: Store, label: ar ? 'المتجر' : 'Store',
      state: mine ? 'connected' : broken ? 'error' : all.some((s) => s.project_id === null) ? 'unassigned' : all.length > 0 ? 'other_project' : 'none',
      detail: mine ? `${mine.provider.label} · ${mine.name}` : null,
      to: '/integrations',
    })
  }
  if (ga4.isSuccess) {
    const all = ga4.data.flatMap((p) => p.properties)
    const mine = all.find((pr) => pr.project_id === projectId)
    sources.push({
      key: 'ga4', icon: BarChart3, label: 'GA4',
      state: mine ? 'connected' : ga4.data.some((p) => p.state === 'error') ? 'error' : all.some((pr) => pr.is_selected && pr.project_id === null) ? 'unassigned' : all.length > 0 ? 'other_project' : 'none',
      detail: mine ? mine.name : null,
      to: '/integrations',
    })
  }
  if (sources.length === 0) return null

  return (
    <section aria-label={ar ? 'مصادر بيانات المشروع' : 'Project data sources'} data-testid="adv-sources" className="grid grid-cols-3 gap-2 [&>*]:min-w-0">
      {sources.map((s) => {
        const st = STATE_LABEL[s.state]
        return (
          <Link
            key={s.key}
            to={portal(s.to)}
            data-testid={`adv-source-${s.key}`}
            data-state={s.state}
            className="flex min-w-0 items-center gap-2 rounded-xl border border-border bg-surface px-2.5 py-2 hover:border-brand-400 sm:gap-3 sm:px-3 focus-visible:outline-2 focus-visible:outline-brand-500"
          >
            <s.icon size={18} aria-hidden className="hidden shrink-0 text-text-secondary sm:block" />
            <span className="flex min-w-0 flex-col gap-0.5">
              <span className="flex flex-col items-start gap-1 sm:flex-row sm:flex-wrap sm:items-center sm:gap-2">
                <span className="text-sm font-semibold text-text-primary">{s.label}</span>
                <Badge tone={st.tone}>{ar ? st.ar : st.en}</Badge>
              </span>
              {s.detail && <span className="truncate text-xs text-text-secondary" dir="auto">{s.detail}</span>}
            </span>
          </Link>
        )
      })}
    </section>
  )
}
