import type { PlatformPathRow } from './api'

/**
 * PLATFORM-DECISION-ANALYTICS-001 — «which platform is becoming cheaper at what this path buys».
 *
 * ## Why the metric is chosen by the PATH
 *
 * A platform buying awareness has no cost per order, and a platform buying sales has a CPM that
 * nobody is managing to. Showing a fixed column of four efficiencies fills two of them with «—» on
 * every row and invites the reader to compare the two that happen to be populated — which is the
 * cross-objective comparison this whole requirement exists to refuse, printed one column at a time.
 *
 * So each path gets the one cost that names what it was buying: awareness pays for a thousand
 * impressions, traffic pays for a click, and a conversion path pays for an order — with return
 * beside it, because a sales path is the only one where return is a fact rather than a hope.
 *
 * ## Nothing is derived from a denominator the platform never reported
 *
 * `impressions = 0` is what the aggregator writes for a platform that publishes no impressions AND
 * for one that genuinely got none. Dividing by it produces «∞ SAR per thousand» or a silent zero,
 * both of which read as measurements. A missing denominator returns null, and the surface prints the
 * dash that means «not measured here» rather than a number that means nothing.
 */
export type PathEfficiency = {
  key: 'cpm' | 'cpc' | 'cpv' | 'cpa'
  /** Money per unit, in the reading currency — null when the platform reported no denominator. */
  value: number | null
  labelAr: string
  labelEn: string
}

export type PathReturn = { value: number | null }

/** The cost that names what this path was buying. */
export function efficiencyFor(path: string, row: PlatformPathRow): PathEfficiency {
  const per = (denominator: number, scale = 1): number | null =>
    denominator > 0 && row.spend > 0 ? (row.spend / denominator) * scale : null

  switch (path) {
    case 'awareness':
      return { key: 'cpm', value: per(row.impressions, 1000), labelAr: 'تكلفة الألف ظهور', labelEn: 'Cost per 1,000' }
    case 'traffic':
      return { key: 'cpc', value: per(row.clicks), labelAr: 'تكلفة النقرة', labelEn: 'Cost per click' }
    case 'consideration':
      /*
       * A consideration path is paid for by ARRIVALS, not by clicks: the click is the platform's
       * claim and the landing-page view is the site's. Where the site never reported one this falls
       * back to the click rather than showing nothing — and says which it used, through `key`.
       */
      return row.landing_page_views > 0
        ? { key: 'cpv', value: per(row.landing_page_views), labelAr: 'تكلفة الزيارة', labelEn: 'Cost per visit' }
        : { key: 'cpc', value: per(row.clicks), labelAr: 'تكلفة النقرة', labelEn: 'Cost per click' }
    default:
      return { key: 'cpa', value: per(row.orders), labelAr: 'تكلفة النتيجة', labelEn: 'Cost per result' }
  }
}

/**
 * Return, and ONLY where returning is what the path was for.
 *
 * A brand path with a revenue figure attached to it is an accident of attribution — a sale that
 * happened to be credited to an impression somebody was not asked to buy — and printing it as ROAS
 * on an awareness row is exactly the claim the hard constraint forbids.
 */
export function returnFor(path: string, row: PlatformPathRow): PathReturn {
  const eligible = path !== 'awareness' && path !== 'traffic'

  return { value: eligible && row.spend > 0 && row.revenue > 0 ? row.revenue / row.spend : null }
}
