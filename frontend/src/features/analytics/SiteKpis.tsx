import { StatCard, StatGrid } from '@/components/ui/StatCard'
import { formatFixed } from '@/lib/numerals'
import { money, num, percent } from './format'
import { KpiSparkline } from './charts'

/**
 * GA4-BOUNCE-AOV-KPIS-001 — the site KPIs with the previous period, the change, and where each came from.
 *
 * GA4's own figures under GA4's attribution: never added to a store or ad-platform figure (every KPI
 * says `blended_with_other_sources: false`). A KPI the property did not report is «—» and says so,
 * never 0.
 */
export interface SiteKpi {
  key: string
  kind: 'count' | 'rate' | 'seconds' | 'money' | 'ratio'
  current: number | null
  previous: number | null
  change: number | null
  change_pct: number | null
  currency: string | null
  definition: string
  source: { kind: 'ga4'; metrics: string[] }
  blended_with_other_sources: boolean
  freshness: { last_synced_at: string | null; newest_day: string | null; days_with_data: number; days_in_window: number }
  /** Day by day; a day that cannot state the KPI is left out. Older payloads have none. */
  trend?: Array<{ date: string; value: number }>
}

// The store tiles above already state new users, bounce rate and basket size (GA4-STORE-ANALYTICS-001); these are the rest.
const SHOWN = ['active_users', 'page_views', 'product_views', 'avg_engagement_seconds', 'items_per_order', 'revenue_per_user', 'revenue_per_session'] as const

const NAME: Record<string, { ar: string; en: string }> = {
  active_users: { ar: 'المستخدمون النشطون', en: 'Active users' },
  new_users: { ar: 'المستخدمون الجدد', en: 'New users' },
  page_views: { ar: 'مشاهدات الصفحات', en: 'Page views' },
  product_views: { ar: 'مشاهدات المنتجات', en: 'Product views' },
  bounce_rate: { ar: 'معدل الارتداد', en: 'Bounce rate' },
  avg_engagement_seconds: { ar: 'متوسط مدة التفاعل', en: 'Avg. engagement time' },
  aov: { ar: 'متوسط قيمة الطلب', en: 'Average order value' },
  items_per_order: { ar: 'متوسط المنتجات في الطلب', en: 'Items per order' },
  revenue_per_user: { ar: 'الإيراد لكل مستخدم', en: 'Revenue per user' },
  revenue_per_session: { ar: 'الإيراد لكل جلسة', en: 'Revenue per session' },
}

export function kpiValue(k: SiteKpi, v: number | null): string {
  if (v === null) return '—'
  switch (k.kind) {
    case 'rate': return percent(v, 1)
    case 'money': return money(v, k.currency)
    case 'ratio': return formatFixed(v, 2)
    case 'seconds': return `${Math.floor(v / 60)}:${String(Math.round(v % 60)).padStart(2, '0')}`
    default: return num(v)
  }
}

export function SiteKpis({ kpis, previous, ar }: { kpis: SiteKpi[]; previous: { from: string; to: string }; ar: boolean }) {
  const by = new Map(kpis.map((k) => [k.key, k]))
  const rows = SHOWN.map((key) => by.get(key)).filter((k): k is SiteKpi => k !== undefined)
  // The definitions cover every named KPI, the ones the store tiles draw included: a tile is one word, its meaning is here.
  const defined = Object.keys(NAME).map((key) => by.get(key)).filter((k): k is SiteKpi => k !== undefined)
  if (defined.length === 0) return null
  return (
    <section data-testid="site-kpis" aria-label={ar ? 'مؤشرات الموقع' : 'Website KPIs'} className="space-y-2">
      <StatGrid columns="grid-cols-2 lg:grid-cols-4">
        {rows.map((k) => {
          const name = NAME[k.key]?.[ar ? 'ar' : 'en'] ?? k.key
          const prev = k.previous === null ? (ar ? 'لا بيانات للفترة السابقة' : 'No data for the previous period') : `${ar ? 'السابقة' : 'Previous'}: ${kpiValue(k, k.previous)}`
          const change = k.change_pct !== null ? ` · ${k.change_pct >= 0 ? '+' : '−'}${percent(Math.abs(k.change_pct), 1)}` : ''
          return (
            <StatCard key={k.key} testid={`site-kpi-${k.key}`} tone="neutral" label={name}
              value={<span dir="ltr">{kpiValue(k, k.current)}</span>}
              hint={k.current === null ? (ar ? 'لم تُرجع الخاصية هذا المؤشر' : 'The property did not report this') : `${prev}${change}`}
              spark={(k.trend?.length ?? 0) >= 2 ? <span data-testid={`site-kpi-trend-${k.key}`} className="block"><KpiSparkline points={(k.trend ?? []).map((p) => p.value)} /></span> : undefined} />
          )
        })}
      </StatGrid>
      <details className="text-xs text-text-secondary" data-testid="site-kpi-definitions">
        <summary className="cursor-pointer font-semibold">{ar ? `التعريفات والمصدر (مقارنة مع ${previous.from} → ${previous.to})` : `Definitions and source (compared with ${previous.from} → ${previous.to})`}</summary>
        <ul className="mt-2 space-y-1.5">
          {defined.map((k) => (
            <li key={k.key} data-testid={`site-kpi-def-${k.key}`}>
              <span className="font-semibold text-text-primary">{NAME[k.key]?.[ar ? 'ar' : 'en']}</span> — {k.definition}{' '}
              <span dir="ltr" className="font-mono">[{k.source.metrics.join(', ')}]</span>{' '}
              {k.freshness.newest_day ? <span dir="ltr">· {k.freshness.days_with_data}/{k.freshness.days_in_window} {ar ? 'يوم' : 'days'}, {ar ? 'آخر يوم' : 'newest'} {k.freshness.newest_day}</span> : null}
            </li>
          ))}
        </ul>
        <p className="mt-2">{ar ? 'أرقام الموقع لا تُجمع مع أرقام المتجر أو المنصات الإعلانية.' : 'Website figures are never added to store or ad-platform figures.'}</p>
      </details>
    </section>
  )
}
