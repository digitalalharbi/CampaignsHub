import { useMemo } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Image as ImageIcon, Megaphone, PlayCircle } from 'lucide-react'
import { usePortalPath } from '@/app/portalPath'
import { ProviderMark } from '@/components/ProviderMark'
import { Num } from '@/components/ui/Num'
import { Skeleton } from '@/components/ui/States'
import { compact, moneyFromTotals } from '@/features/analytics/format'
import type { AccountPulse, MetricTotals } from '@/features/analytics/api'
import { listCreatives, type CreativeCard } from '@/features/content/api'
import { PosterImage } from '@/features/content/PosterImage'
import { mediaFitClass, readPreview } from '@/features/content/adPreview'

/**
 * ADS-MANAGER-003 (Owner 2026-11-24, «الحسابات خلها تفاعلية كذا ادمج فيها الجانب الابداعي») — the accounts level as a board
 * of live cards rather than a table: each account's state, spend and the shape of its days, its campaigns by state, and the
 * content that spent most under it in the period, as covers a reader can open.
 *
 * The covers come from the content library's own endpoint, ranked by spend over the same period, and are placed under an
 * account by the account the library itself names on each creative (`ad_account`) — never guessed from a platform alone.
 * An account with no creative named under it says so; nothing is borrowed from a sibling account.
 */
export interface AccountCardRow {
  id: string
  name: string
  provider: string | null
  accountId: string | null
  delivery: 'serving' | 'idle' | 'stopped' | 'error'
  totals: Record<string, unknown>
}

const STATE: Record<AccountCardRow['delivery'], { ar: string; en: string; dot: string; ring: string }> = {
  serving: { ar: 'يعمل', en: 'Delivering', dot: 'bg-success', ring: 'hover:border-success/50' },
  idle: { ar: 'بلا صرف', en: 'No spend', dot: 'bg-warning', ring: 'hover:border-warning/50' },
  stopped: { ar: 'متوقف', en: 'Stopped', dot: 'bg-border-strong', ring: 'hover:border-border-strong' },
  error: { ar: 'يحتاج إعادة ربط', en: 'Needs reconnecting', dot: 'bg-danger', ring: 'hover:border-danger/50' },
}

function Spark({ points }: { points: number[] }) {
  if (points.length < 2) return <div className="h-10" aria-hidden />
  const max = Math.max(...points, 0) || 1
  const step = 100 / (points.length - 1)
  const d = points.map((v, i) => `${(i * step).toFixed(2)},${(38 - (v / max) * 34).toFixed(2)}`).join(' ')
  return (
    <svg viewBox="0 0 100 40" preserveAspectRatio="none" className="h-10 w-full text-brand-500" aria-hidden>
      <polyline points={`0,40 ${d} 100,40`} fill="currentColor" opacity="0.12" stroke="none" />
      <polyline points={d} fill="none" stroke="currentColor" strokeWidth="1.6" vectorEffect="non-scaling-stroke" strokeLinejoin="round" />
    </svg>
  )
}

function Cover({ c, ar }: { c: CreativeCard; ar: boolean }) {
  const portal = usePortalPath()
  const read = readPreview(c.preview, ar)
  const src = read.kind === 'image' ? read.src : read.kind === 'video' ? read.poster : read.kind === 'collection' ? read.src : null
  const video = read.kind === 'video'
  const fallback = (
    <span className="flex size-full items-center justify-center bg-surface-secondary text-text-muted">
      {video ? <PlayCircle size={18} aria-hidden /> : <ImageIcon size={18} aria-hidden />}
    </span>
  )
  return (
    <Link to={portal(`/content/${c.id}`)} title={c.name} onClick={(e) => e.stopPropagation()} data-testid="ads-manager-account-creative"
      className="group/cover relative block aspect-[9/16] overflow-hidden rounded-lg border border-border bg-surface-secondary focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500">
      {src ? <PosterImage src={src} alt={c.name} loading="lazy" className={`size-full ${mediaFitClass(null, 'vertical', 'cover')} transition-transform duration-300 group-hover/cover:scale-105`} fallback={fallback} /> : fallback}
      {video && src && <PlayCircle size={16} className="absolute end-1 top-1 text-white drop-shadow" aria-label={ar ? 'فيديو' : 'Video'} />}
    </Link>
  )
}

export function AdsManagerAccounts({
  rows, pulse, projectId, range, currency, ar, loading, onCampaigns, onDetails,
}: {
  rows: AccountCardRow[]
  pulse: AccountPulse[]
  projectId: string | null
  range: { from: string; to: string }
  currency: string | null
  ar: boolean
  loading: boolean
  onCampaigns: (provider: string | null) => void
  onDetails: (id: string) => void
}) {
  const portal = usePortalPath()
  const library = useQuery({
    queryKey: ['ads-manager', 'account-creatives', projectId, range.from, range.to],
    queryFn: () => listCreatives({ from: range.from, to: range.to, sort: 'spend', per_page: 60 }, projectId),
    enabled: !!projectId,
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
  const pulseOf = useMemo(() => new Map(pulse.map((p) => [`${p.provider}:${p.account_id}`, p])), [pulse])

  if (loading) {
    return <div className="grid gap-3 p-3 sm:grid-cols-2 xl:grid-cols-3">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-64 rounded-2xl" />)}</div>
  }
  if (rows.length === 0) {
    return <p className="px-4 py-10 text-center text-sm text-text-secondary" data-testid="ads-manager-empty">{ar ? 'لا توجد حسابات تطابق التصفية.' : 'No accounts match these filters.'}</p>
  }

  return (
    <div className="grid gap-3 p-3 sm:grid-cols-2 xl:grid-cols-3" data-testid="ads-manager-accounts">
      {rows.map((r) => {
        const p = pulseOf.get(r.id)
        const s = STATE[r.delivery]
        const covers = r.accountId ? byAccount.get(r.accountId) ?? [] : []
        const spend = moneyFromTotals(r.totals as unknown as MetricTotals, 'spend', ar, currency).text
        const impressions = typeof r.totals.impressions === 'number' ? compact(r.totals.impressions) : '—'
        return (
          <article key={r.id} role="button" tabIndex={0} onClick={() => onDetails(r.id)}
            onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onDetails(r.id) } }}
            data-testid="ads-manager-account-card"
            className={`group flex cursor-pointer flex-col gap-3 rounded-2xl border border-border bg-surface p-4 transition-all hover:-translate-y-0.5 hover:shadow-md ${s.ring}`}>
            <header className="flex items-start gap-3">
              {r.provider && <ProviderMark provider={r.provider} size={32} className="shrink-0" />}
              <div className="min-w-0 flex-1">
                <h3 className="truncate text-sm font-bold text-text-primary">{r.name}</h3>
                <span className="mt-0.5 inline-flex items-center gap-1.5 text-xs text-text-secondary">
                  <span className={`size-2 rounded-full ${s.dot} ${r.delivery === 'serving' ? 'animate-pulse' : ''}`} aria-hidden />{ar ? s.ar : s.en}
                </span>
              </div>
              <div className="text-end">
                <span className="block text-lg font-bold text-text-primary"><Num>{spend}</Num></span>
                <span className="text-xs text-text-muted">{ar ? 'الإنفاق' : 'Spend'} · <Num>{impressions}</Num> {ar ? 'ظهور' : 'impr.'}</span>
              </div>
            </header>

            <Spark points={(p?.daily ?? []).map((d) => d.spend)} />

            {p && (
              <div className="flex flex-wrap gap-1.5 text-xs">
                <span className="rounded-full bg-success/10 px-2 py-0.5 font-semibold text-success">{ar ? 'نشطة' : 'Active'} <Num>{p.active_campaigns}</Num></span>
                <span className="rounded-full bg-warning/10 px-2 py-0.5 font-semibold text-warning">{ar ? 'موقوفة' : 'Paused'} <Num>{p.paused_campaigns}</Num></span>
                <span className="rounded-full bg-surface-secondary px-2 py-0.5 font-semibold text-text-secondary">{ar ? 'منتهية' : 'Ended'} <Num>{p.ended_campaigns}</Num></span>
              </div>
            )}

            <div>
              <span className="mb-1.5 block text-xs font-semibold text-text-secondary">{ar ? 'أعلى المحتوى إنفاقًا' : 'Top content by spend'}</span>
              {library.isPending ? (
                <div className="grid grid-cols-4 gap-1.5">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="aspect-[9/16] rounded-lg" />)}</div>
              ) : covers.length > 0 ? (
                <div className="grid grid-cols-4 gap-1.5">{covers.map((c) => <Cover key={c.id} c={c} ar={ar} />)}</div>
              ) : (
                <p className="rounded-lg bg-surface-secondary px-3 py-4 text-center text-xs text-text-muted">{ar ? 'لا يوجد محتوى منسوب لهذا الحساب في الفترة' : 'No content credited to this account in the period'}</p>
              )}
            </div>

            <footer className="mt-auto flex gap-2 border-t border-border pt-3">
              <button type="button" onClick={(e) => { e.stopPropagation(); onCampaigns(r.provider) }} data-testid="ads-manager-account-campaigns"
                className="inline-flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-surface-secondary px-3 py-2 text-xs font-bold text-text-primary hover:bg-border">
                <Megaphone size={14} aria-hidden /> {ar ? 'الحملات' : 'Campaigns'}
              </button>
              <Link to={portal('/content')} onClick={(e) => e.stopPropagation()} data-testid="ads-manager-account-content"
                className="inline-flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-surface-secondary px-3 py-2 text-xs font-bold text-text-primary hover:bg-border">
                <ImageIcon size={14} aria-hidden /> {ar ? 'المحتوى' : 'Content'}
              </Link>
            </footer>
          </article>
        )
      })}
    </div>
  )
}
