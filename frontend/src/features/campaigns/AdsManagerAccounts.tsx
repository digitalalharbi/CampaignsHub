import { useMemo } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Image as ImageIcon, PlayCircle } from 'lucide-react'
import { usePortalPath } from '@/app/portalPath'
import { Num } from '@/components/ui/Num'
import { Skeleton } from '@/components/ui/States'
import type { AccountPulse } from '@/features/analytics/api'
import { listCreatives, type CreativeCard } from '@/features/content/api'
import { PosterImage } from '@/features/content/PosterImage'
import { mediaFitClass, readPreview } from '@/features/content/adPreview'

/**
 * ADS-MANAGER-003 (Owner 2026-11-24) — the accounts level is a table again («خلها مثل قبل جدول وتفاعلي»), and these are the
 * interactive cells it carries: the shape of the account's spend over the period, its campaigns by state (a click opens
 * them), and the content that spent most under it, as covers a reader can open.
 *
 * The covers come from the content library's own endpoint, ranked by spend over the same period, and are placed under an
 * account by the account the library itself names on each creative (`ad_account`) — never guessed from a platform alone.
 */

/** The period's top content by spend, grouped by the ad account the library names on each creative. */
export function useAccountCovers(projectId: string | null, range: { from: string; to: string }, enabled: boolean) {
  const library = useQuery({
    queryKey: ['ads-manager', 'account-creatives', projectId, range.from, range.to],
    queryFn: () => listCreatives({ from: range.from, to: range.to, sort: 'spend', per_page: 60 }, projectId),
    enabled: enabled && !!projectId,
    staleTime: 60_000,
  })
  const byAccount = useMemo(() => {
    const map = new Map<string, CreativeCard[]>()
    for (const c of library.data?.creatives ?? []) {
      if (!c.ad_account) continue
      const list = map.get(c.ad_account.id) ?? []
      if (list.length < 4) list.push(c)
      map.set(c.ad_account.id, list)
    }
    return map
  }, [library.data])
  return { byAccount, pending: library.isPending && enabled && !!projectId }
}

export function AccountSpark({ points }: { points: number[] }) {
  if (points.length < 2) return <span className="text-xs text-text-muted">—</span>
  const max = Math.max(...points, 0) || 1
  const step = 100 / (points.length - 1)
  const d = points.map((v, i) => `${(i * step).toFixed(2)},${(26 - (v / max) * 22).toFixed(2)}`).join(' ')
  return (
    <svg viewBox="0 0 100 28" preserveAspectRatio="none" className="h-7 w-24 text-brand-500" aria-hidden data-testid="ads-manager-account-spark">
      <polyline points={`0,28 ${d} 100,28`} fill="currentColor" opacity="0.12" stroke="none" />
      <polyline points={d} fill="none" stroke="currentColor" strokeWidth="1.6" vectorEffect="non-scaling-stroke" strokeLinejoin="round" />
    </svg>
  )
}

export function AccountCampaigns({ pulse, ar, onOpen }: { pulse: AccountPulse | undefined; ar: boolean; onOpen: () => void }) {
  if (!pulse) return <span className="text-xs text-text-muted">—</span>
  return (
    <button type="button" onClick={onOpen} data-testid="ads-manager-account-campaigns" title={ar ? 'افتح حملات المنصة' : 'Open the platform\'s campaigns'}
      className="inline-flex items-center gap-1 whitespace-nowrap rounded-lg px-1 py-0.5 text-xs hover:bg-surface-secondary">
      <span className="rounded-full bg-success/10 px-1.5 font-semibold text-success">{ar ? 'نشطة' : 'Active'} <Num>{pulse.active_campaigns}</Num></span>
      <span className="rounded-full bg-warning/10 px-1.5 font-semibold text-warning">{ar ? 'موقوفة' : 'Paused'} <Num>{pulse.paused_campaigns}</Num></span>
      <span className="rounded-full bg-surface-secondary px-1.5 font-semibold text-text-secondary">{ar ? 'منتهية' : 'Ended'} <Num>{pulse.ended_campaigns}</Num></span>
    </button>
  )
}

function Cover({ c, ar }: { c: CreativeCard; ar: boolean }) {
  const portal = usePortalPath()
  const read = readPreview(c.preview, ar)
  const src = read.kind === 'image' ? read.src : read.kind === 'video' ? read.poster : read.kind === 'collection' ? read.src : null
  const video = read.kind === 'video'
  const fallback = (
    <span className="flex size-full items-center justify-center bg-surface-secondary text-text-muted">
      {video ? <PlayCircle size={12} aria-hidden /> : <ImageIcon size={12} aria-hidden />}
    </span>
  )
  return (
    <Link to={portal(`/content/${c.id}`)} title={c.name} data-testid="ads-manager-account-creative"
      className="group/cover relative block h-11 w-[25px] shrink-0 overflow-hidden rounded-md border border-border bg-surface-secondary focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500">
      {src ? <PosterImage src={src} alt={c.name} loading="lazy" className={`size-full ${mediaFitClass(null, 'vertical', 'thumb')} transition-transform duration-300 group-hover/cover:scale-110`} fallback={fallback} /> : fallback}
    </Link>
  )
}

export function AccountCovers({ covers, pending, ar }: { covers: CreativeCard[]; pending: boolean; ar: boolean }) {
  if (pending) return <Skeleton className="h-11 w-28 rounded-md" />
  if (covers.length === 0) return <span className="text-xs text-text-muted">{ar ? 'لا محتوى منسوب' : 'None credited'}</span>
  return <span className="flex gap-1">{covers.map((c) => <Cover key={c.id} c={c} ar={ar} />)}</span>
}
