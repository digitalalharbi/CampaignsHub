import { Skeleton } from '@/components/ui/States'
import { StatCard, StatGrid } from '@/components/ui/StatCard'

/**
 * PERF-INSTANT-DATA-001 §12.2.3 — a cold open is the page's own parts, empty, at their final size.
 *
 * The block these replace was a single `h-96` slab: it was neither the height of the real page nor
 * the shape of it, so the moment data landed everything below moved. A skeleton is only worth
 * drawing if the real thing replaces it WITHOUT moving anything, so the KPI slots are the real
 * `StatCard` (same label scale, same reserved rows) with the figure itself left as a bar.
 */
export function KpiSkeleton({ label, testid, tone = 'neutral' }: { label: string; testid: string; tone?: 'neutral' | 'brand' | 'info' | 'success' }) {
  return <StatCard testid={testid} tone={tone} label={label} value={<Skeleton className="h-7 w-24" />} />
}

export function KpiRowSkeleton({ labels, idPrefix, columns = 'grid-cols-2 lg:grid-cols-4' }: { labels: string[]; idPrefix: string; columns?: string }) {
  return (
    <StatGrid columns={columns}>
      {labels.map((label, i) => <KpiSkeleton key={label} label={label} testid={`${idPrefix}-${i}-skeleton`} />)}
    </StatGrid>
  )
}

/** A chart's body at its final height; the card around it is the real one, so the title is already there. */
export function ChartBodySkeleton({ height, testid }: { height: number; testid: string }) {
  return <div data-testid={testid} style={{ height }}><Skeleton className="h-full w-full" /></div>
}
