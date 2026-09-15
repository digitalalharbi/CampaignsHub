import { Link, useLocation } from 'react-router-dom'
import { useUi } from '@/stores/ui'

/**
 * CAMPAIGN-DRILL-001 — one campaign name, one route to its whole truth.
 *
 * The product already has a deep Campaign Detail and `/campaigns/:projectId/:campaignId` is the
 * canonical way in. Only the Campaigns page ever used it. Everywhere else an operator meets a
 * campaign — the ranking on the dashboard, the movers, the budget table — the name was printed as
 * text, so the route to the answer existed and nothing pointed at it. `campaign_id` was already on
 * every one of those rows: the aggregator selects `unified_campaign_id as campaign_id`, which is
 * exactly the id the detail route takes.
 *
 * ## The portal prefix is written, not left to the redirect
 *
 * An unprefixed `/campaigns/...` does reach the right place: `PrefixWithApp` sends it to the
 * caller's own portal. It arrives having lost something, though — a redirect is
 * `<Navigate to={…} replace />`, and `state` does not survive it, so the «back to where you were»
 * this link sets was silently dropped and Campaign Detail fell back to the campaigns list. Observed
 * in the browser, not deduced: the control read «العودة إلى الحملات» after arriving from Analytics.
 *
 * Writing the prefix keeps the state and removes a history hop nobody needs. It is read from the
 * current path rather than from the account, because a person reading `/agency` belongs in
 * `/agency` for the length of that journey whatever else their account can reach.
 *
 * A portal with no campaign route — the client portal — gets no link at all. That is the same
 * boundary the client-facing reports keep, enforced here by construction rather than by remembering.
 *
 * ## Context
 *
 * The current query string travels, because a reader who narrowed to a provider and a fortnight
 * chose that scope and should not have it discarded by following a link inside it. `state.from`
 * carries where they came from, so Campaign Detail's back control returns THERE rather than to a
 * campaigns list they may never have opened.
 */
/**
 * The operator portal this path belongs to, or null where campaigns are not reachable.
 *
 * `/app` and `/agency` both mount the campaign routes. The client portal deliberately does not, and
 * a campaign identity is not a client's to follow — so it is not given a control that pretends
 * otherwise.
 */
export function portalBaseOf(pathname: string): string | null {
  if (pathname.startsWith('/agency')) return '/agency'
  if (pathname.startsWith('/app')) return '/app'

  return null
}

export function CampaignLink({
  projectId,
  campaignId,
  name,
  className = '',
  testid,
}: {
  /*
   * Nullable on purpose. Several analytical surfaces run without a project pinned — a cross-project
   * scope has no single `projectId` — and the detail route needs one. Without it the name is still
   * printed and simply is not a link: a control that navigates nowhere is worse than plain text.
   */
  projectId: string | null | undefined
  campaignId: string | null | undefined
  name: string | null | undefined
  className?: string
  testid?: string
}) {
  const location = useLocation()
  const ar = useUi((s) => s.locale) === 'ar'

  /*
   * A campaign whose name is no longer held keeps its route and loses its label — never a uuid.
   *
   * The row still identifies a real campaign, so the way in stays open; what changes is that the
   * reader is told the name is gone rather than shown a key they cannot act on. This is the wording
   * the analytics ranking already used for the same state.
   */
  const label = name ?? (ar ? 'حملة لم يعد اسمها محفوظًا' : 'A campaign whose name is no longer held')

  const base = portalBaseOf(location.pathname)

  if (!projectId || !campaignId || base === null) {
    return <span className={className} data-testid={testid}>{label}</span>
  }

  return (
    <Link
      to={{ pathname: `${base}/campaigns/${projectId}/${campaignId}`, search: location.search }}
      state={{ from: `${location.pathname}${location.search}` }}
      className={`rounded-sm underline-offset-2 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent ${className}`}
      data-testid={testid ?? 'campaign-link'}
      title={ar ? 'فتح تحليل الحملة' : 'Open campaign analysis'}
    >
      {label}
    </Link>
  )
}
