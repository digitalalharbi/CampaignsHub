/**
 * PORTAL-FULL-REPORTS-001 — one address for a shared report's data, whoever reads it.
 *
 * A public link reads `/reports/shared/{token}`. A signed-in client reading the same report inside their portal reads
 * `/client/reports/{id}/view` — the same answers from the same server controller, behind the portal session instead of the
 * link (the link is never handed to the page). The report page is given `portal:{reportId}` in place of a token.
 */
export const PORTAL_REPORT_PREFIX = 'portal:'

export const portalReportToken = (reportId: string) => `${PORTAL_REPORT_PREFIX}${reportId}`

/** Path under `/api/v1` (for `getData`). */
export function sharedPath(token: string): string {
  return token.startsWith(PORTAL_REPORT_PREFIX)
    ? `/client/reports/${encodeURIComponent(token.slice(PORTAL_REPORT_PREFIX.length))}/view`
    : `/reports/shared/${encodeURIComponent(token)}`
}

/** Full URL (for `fetch`). */
export const sharedBase = (token: string): string => `/api/v1${sharedPath(token)}`
