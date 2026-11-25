import { Suspense, type ComponentType } from 'react'
import { lazyWithReload } from '@/lib/chunkReload'
import { Skeleton } from '@/components/ui/States'

/**
 * The report's sections on the board load when the board is opened, not with the report.
 *
 * The /r/ page and the board share `features/reportLogic`; importing the board's components statically from `AnalyticsPage` would
 * pull them into the chunk graph the public report is served from, and that route has a byte budget (`bundleBudget.ts`). A dynamic
 * import keeps the shared logic where it was and puts only the board-only code behind the board.
 */
type ProjectRange = { projectId: string; range: { from: string; to: string } }

function later(load: () => Promise<{ default: ComponentType<ProjectRange> }>) {
  const Component = lazyWithReload(load)

  return function Later(props: ProjectRange) {
    return (
      <Suspense fallback={<Skeleton className="h-24" />}>
        <Component {...props} />
      </Suspense>
    )
  }
}

export const BoardPlatformSummary = later(() => import('./ReportBoard').then((m) => ({ default: m.BoardPlatformSummary })))
export const BoardPlatformRanking = later(() => import('./ReportBoard').then((m) => ({ default: m.BoardPlatformRanking })))
export const BoardContentReport = later(() => import('./ReportBoard').then((m) => ({ default: m.BoardContentReport })))
export const BoardCampaignRanking = later(() => import('./ReportBoard').then((m) => ({ default: m.BoardCampaignRanking })))
export const CompareBoard = later(() => import('./CompareBoard').then((m) => ({ default: m.CompareBoard })))
export const ReportFromViewButton = later(() => import('./ReportFromView').then((m) => ({ default: m.ReportFromViewButton })))
