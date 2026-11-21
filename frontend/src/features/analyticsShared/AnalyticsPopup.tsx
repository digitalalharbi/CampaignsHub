import { useMemo } from 'react'
import { Link } from 'react-router-dom'
import { ArrowLeftRight } from 'lucide-react'
import { Modal } from '@/components/ui/Modal'
import { ProjectChooser } from '@/features/projects/ProjectChooser'
import { ANALYTICS_SECTIONS, AnalyticsSectionBody } from '@/features/analytics/AnalyticsPage'
import { resolveSelection } from '@/lib/period/periodState'
import { riyadhToday, type PresetKey } from '@/lib/period/periodMath'
import { ScopedSearchParams } from '@/lib/url/latestSearchParams'
import { usePortalBase } from '@/app/portalPath'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'
import type { AnalyticsSectionId } from './homeSections'

/**
 * ANALYTICS-POPUP-001 — a section of «التحليلات», opened over the page that asked for it.
 *
 * Same panels, same queries, same period (`AnalyticsSectionBody`), inside a private query string so the page underneath
 * keeps its address. View-only: nothing here links to a campaign's controls — the one link is «افتح التحليلات», to the
 * same section of the board.
 */
const PRESET: Record<number, PresetKey> = { 7: 'last_7', 14: 'last_14', 28: 'last_28', 30: 'last_30', 90: 'last_90' }

export function AnalyticsPopup({ section, days, onClose }: { section: AnalyticsSectionId | null; days: number; onClose: () => void }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const projectId = useProject((s) => s.currentProjectId)
  const base = usePortalBase()
  const meta = ANALYTICS_SECTIONS.find((s) => s.id === section)
  const range = useMemo(() => {
    const r = resolveSelection({ key: PRESET[days] ?? 'last_30', compare: 'none' }, { today: riyadhToday(), earliest: null })

    return { from: r.period.from, to: r.period.to }
  }, [days])

  return (
    <Modal open={section !== null} onClose={onClose} size="xl" title={meta ? (ar ? meta.ar : meta.en) : undefined}>
      {meta && (
        <div data-testid="analytics-popup" data-section={meta.id} className="flex flex-col gap-3">
          {projectId === null ? (
            <ProjectChooser purpose="analytics" />
          ) : (
            <ScopedSearchParams>
              <AnalyticsSectionBody members={meta.members} projectId={projectId} range={range} filters={{}} objective="all" />
            </ScopedSearchParams>
          )}
          <Link to={`${base}/analytics?tab=s_${meta.id}`} onClick={onClose} data-testid="analytics-popup-open"
            className="inline-flex w-fit items-center gap-1.5 text-sm font-semibold text-brand-600 hover:text-brand-700">
            {ar ? 'افتح التحليلات' : 'Open Analytics'}
            <ArrowLeftRight size={14} aria-hidden />
          </Link>
        </div>
      )}
    </Modal>
  )
}
