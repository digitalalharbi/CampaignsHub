import { AnalyticsPage } from './AnalyticsPage'

/** ADGROUPS-PAGE-001 — «المجموعات»: the ad-groups page, a route of its own (permanent guard §5.5). */
export function AdGroupsPage() {
  return <AnalyticsPage entity="ad_set" />
}
