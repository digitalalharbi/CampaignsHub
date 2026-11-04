import { getData } from '@/lib/api/client'
import { Badge } from '@/components/ui/Badge'
import { MetricTable, type SortValues } from '@/components/ui/MetricTable'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { Skeleton } from '@/components/ui/States'
import { providerLabel } from '@/features/campaigns/labels'
import { useSwrQuery } from '@/lib/query/swr'
import { ChartCard, RankingBarChart } from './charts'
import { money, num } from './format'
import type { ThreeSourcesPayload } from './storeAnalyticsApi'

/**
 * GA4-PLATFORM-COMPARE-001 — platform-reported, store-confirmed and GA4-measured, side by side and never added.
 *
 * Each column carries its source's own label. A source with nothing to say is named as absent rather than
 * printed as zero, and the explanation under a row says what the gap could be, not which figure is right.
 */
export function ThreeSourcesPanel({ projectId, range, ar }: { projectId: string; range: { from: string; to: string }; ar: boolean }) {
  const q = useSwrQuery({
    name: 'three-sources',
    scope: [projectId],
    window: [range.from, range.to],
    queryFn: () => getData<ThreeSourcesPayload>(`/projects/${projectId}/measurement/compare?from=${range.from}&to=${range.to}`),
  })
  if (q.isLoading) return <Skeleton className="h-32 w-full" />
  if (q.isError || !q.data) {
    return <QueryFailure error={q.error} ar={ar} testId="three-sources-failure" onRetry={() => void q.refetch()} fallbackTitle={ar ? 'تعذّرت قراءة المقارنة' : 'The comparison could not be read'} />
  }
  // A server older than this panel answers without these; the panel says «no data» rather than taking the tab down.
  const d = { ...q.data, rows: q.data.rows ?? [], sources: q.data.sources ?? [] }
  const locale = ar ? 'ar' : 'en'
  const cell = (n: number | null | undefined) => (n == null ? '—' : num(n))
  const moneyCell = (n: number | null | undefined, c: string | null | undefined) => (n == null ? '—' : money(n, c ?? ''))
  const label = (key: 'platform_reported' | 'store_confirmed' | 'ga4_measured') => {
    const s = d.sources.find((x) => x.key === key)
    return ar ? s?.label_ar ?? key : s?.label_en ?? key
  }
  const material = d.rows.filter((r) => r.differences.material)

  return (
    <ChartCard title={ar ? 'المنصات والمتجر وGA4 جنبًا إلى جنب' : 'Platforms, store and GA4, side by side'} subtitle={ar ? 'ثلاثة مصادر مستقلة لكل منها نموذجه. لا تُجمع ولا يُفترض تطابقها.' : 'Three separate sources, each with its own model. They are not added and not assumed to match.'}>
      <div data-testid="three-sources" className="min-w-0 space-y-3">
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
          {d.sources.map((s) => (
            <div key={s.key} data-testid={`three-sources-${s.key}`} className="min-w-0 rounded-xl border border-border p-3">
              <Badge tone={s.key === 'store_confirmed' ? 'success' : s.key === 'ga4_measured' ? 'info' : 'neutral'}>{ar ? s.label_ar : s.label_en}</Badge>
              {d.available?.[s.key]
                ? <p className="tnum mt-1 text-xl font-bold" dir="ltr">{cell(s.orders)}</p>
                : <p className="mt-1 text-sm text-text-muted">{ar ? 'لا بيانات من هذا المصدر' : 'No data from this source'}</p>}
              <p className="mt-1 text-[11px] text-text-muted">{ar ? s.note_ar : s.note_en}</p>
            </div>
          ))}
        </div>
        {d.rows.length === 0 ? (
          <p data-testid="three-sources-empty" className="text-sm text-text-muted">{ar ? 'لا بيانات منصات لهذه الفترة.' : 'No platform data for this period.'}</p>
        ) : (
          <>
            <RankingBarChart
              height={Math.max(180, d.rows.length * 60)}
              data={d.rows.map((r) => ({ label: providerLabel(r.platform, locale), platform: r.platform_reported?.orders ?? null, store: r.store_confirmed?.orders ?? null, ga4: r.ga4_measured?.orders ?? null }))}
              bars={[
                { key: 'platform', name: label('platform_reported'), kind: 'num' },
                { key: 'store', name: label('store_confirmed'), kind: 'num' },
                { key: 'ga4', name: label('ga4_measured'), kind: 'num' },
              ]}
            />
            <MetricTable
              head={[ar ? 'المنصة' : 'Platform', `${label('platform_reported')} · ${ar ? 'طلبات' : 'orders'}`, `${label('platform_reported')} · ${ar ? 'إيراد' : 'revenue'}`, `${label('store_confirmed')} · ${ar ? 'طلبات' : 'orders'}`, `${label('store_confirmed')} · ${ar ? 'إيراد' : 'revenue'}`, `${label('ga4_measured')} · ${ar ? 'جلسات' : 'sessions'}`, `${label('ga4_measured')} · ${ar ? 'مشتريات' : 'purchases'}`, `${label('ga4_measured')} · ${ar ? 'إيراد' : 'revenue'}`]}
              rows={d.rows.map((r) => [
                <span key="p" className="font-semibold">{providerLabel(r.platform, locale)}</span>,
                <span key="a" dir="ltr">{cell(r.platform_reported?.orders)}</span>,
                <span key="b" dir="ltr">{moneyCell(r.platform_reported?.revenue, r.platform_reported?.currency)}</span>,
                <span key="c" dir="ltr">{cell(r.store_confirmed?.orders)}</span>,
                <span key="d" dir="ltr">{moneyCell(r.store_confirmed?.revenue, d.store_currency)}</span>,
                <span key="e" dir="ltr">{cell(r.ga4_measured?.sessions)}</span>,
                <span key="f" dir="ltr">{cell(r.ga4_measured?.orders)}</span>,
                <span key="g" dir="ltr">{moneyCell(r.ga4_measured?.revenue, r.ga4_measured?.currency)}</span>,
              ])}
              values={d.rows.map((r): SortValues => [r.platform, r.platform_reported?.orders ?? null, r.platform_reported?.revenue ?? null, r.store_confirmed?.orders ?? null, r.store_confirmed?.revenue ?? null, r.ga4_measured?.sessions ?? null, r.ga4_measured?.orders ?? null, r.ga4_measured?.revenue ?? null])}
            />
          </>
        )}
        {material.length > 0 && (
          <ul data-testid="three-sources-gaps" className="space-y-1 text-xs text-text-secondary">
            {material.map((r) => (
              <li key={r.platform}><span className="font-semibold text-text-primary">{providerLabel(r.platform, locale)}:</span> {r.explanation[ar ? 'ar' : 'en']}</li>
            ))}
          </ul>
        )}
        {d.store_unattributed && (
          <p data-testid="three-sources-unattributed" className="text-xs text-text-secondary">
            {ar
              ? `في المتجر ${num(d.store_unattributed.orders)} طلبًا غير منسوب إلى أي منصة بقيمة ${money(d.store_unattributed.revenue, d.store_currency ?? '')}. نماذج الإسناد لا تلغي التداخل بين المنصات.`
              : `The store holds ${num(d.store_unattributed.orders)} order(s) tied to no platform, worth ${money(d.store_unattributed.revenue, d.store_currency ?? '')}. No attribution model removes overlap between platforms.`}
          </p>
        )}
        <p className="text-[11px] text-text-muted" dir="auto">
          {ar ? 'الفترة' : 'Period'}: <span dir="ltr" className="tnum">{d.period?.from} → {d.period?.to} · {d.period?.timezone}</span>
        </p>
      </div>
    </ChartCard>
  )
}
