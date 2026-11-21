import { useQuery } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { PageIntro } from '@/components/ui/PageIntro'
import { Tabs } from '@/components/ui/Tabs'
import { useAuth } from '@/stores/auth'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'
import { formatNumber } from '@/lib/numerals'
import { CreativesPage } from './CreativesPage'
import { listIntake } from './intake/api'
import { UI } from './intake/copy'
import { DriveSyncPanel } from './intake/DriveSyncPanel'
import { FollowUpPanel } from './intake/FollowUpPanel'
import { IntakePanel } from './intake/IntakePanel'

/*
 * CONTENT-LIBRARY-V2-001 — the Content area is one place with four views over one pipeline: the library (what ran and how it
 * did), intake and review (new content and its quality gate), follow-up (what waits on someone) and Drive. The library is the
 * default and keeps its own address; the other three live under `?tab=`.
 */
const TABS = ['library', 'review', 'followup', 'drive'] as const
type Tab = (typeof TABS)[number]

export function ContentHub() {
  const ar = useUi((s) => s.locale) === 'ar'
  const t = UI[ar ? 'ar' : 'en']
  const [params, setParams] = useSearchParams()
  const canIntake = useAuth((s) => s.hasPermission('content.view'))
  const projectId = useProject((s) => s.currentProjectId)
  const raw = params.get('tab') as Tab | null
  const tab: Tab = raw && (TABS as readonly string[]).includes(raw) && (raw === 'library' || canIntake) ? raw : 'library'

  // The review badge: how many are waiting, from the same list the review tab reads.
  const waiting = useQuery({ queryKey: ['content-intake', projectId, ''], queryFn: () => listIntake({ project: projectId, status: '' }), enabled: canIntake })
  const inReview = waiting.data?.counts.in_review ?? 0

  if (tab === 'library') {
    return (
      <div className="flex flex-col gap-4">
        {canIntake && <HubTabs tab={tab} badge={inReview} ar={ar} onChange={(k) => setParams(k === 'library' ? {} : { tab: k })} />}
        <CreativesPage />
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-4" data-testid="content-hub">
      <PageIntro testid="content-hub-intro" title={ar ? 'المحتوى' : 'Content'} purpose={t.hubPurpose[tab]} />
      <HubTabs tab={tab} badge={inReview} ar={ar} onChange={(k) => setParams(k === 'library' ? {} : { tab: k })} />
      {tab === 'review' && <IntakePanel />}
      {tab === 'followup' && <FollowUpPanel />}
      {tab === 'drive' && <DriveSyncPanel />}
    </div>
  )
}

function HubTabs({ tab, badge, ar, onChange }: { tab: Tab; badge: number; ar: boolean; onChange: (k: Tab) => void }) {
  const t = UI[ar ? 'ar' : 'en']
  const items = TABS.map((k) => ({
    key: k,
    label: k === 'review' && badge > 0 ? `${t.tabs[k]} (${formatNumber(badge)})` : t.tabs[k],
  }))
  return (
    <div data-testid="content-hub-tabs">
      <Tabs items={items} active={tab} onChange={(k) => onChange(k as Tab)} />
    </div>
  )
}
