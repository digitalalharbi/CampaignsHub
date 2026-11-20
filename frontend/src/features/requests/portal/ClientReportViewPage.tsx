import { Link, useParams } from 'react-router-dom'
import { ArrowRight } from 'lucide-react'
import { PortalShell } from './PortalShell'
import { useClientSpacePath } from './clientSpace'
import { PublicReport } from '@/features/reports/PublicReport'
import { portalReportToken } from '@/features/reports/sharedBase'
import { useUi } from '@/stores/ui'

/**
 * PORTAL-FULL-REPORTS-001 — Owner 2026-11-20: «البوابة يجب أن تحتوي على كل تفاصيل التقارير». The client's report, whole, inside
 * the portal: the same live page the shared link shows (performance, platforms, content, funnel, website, attribution, files where
 * the link allows them), read through the portal session. The link itself is never handed to the page.
 */
export function ClientReportViewPage() {
  const { reportId = '' } = useParams()
  const ar = useUi((s) => s.locale) === 'ar'
  const spaceTo = useClientSpacePath()

  return (
    <PortalShell title={ar ? 'التقرير' : 'Report'} nav showLogout>
      <Link to={spaceTo('/reports')} className="mb-3 inline-flex items-center gap-1 text-xs font-semibold text-brand-600 hover:text-brand-700" data-testid="portal-report-back">
        <ArrowRight size={13} className="ltr:rotate-180" aria-hidden /> {ar ? 'كل التقارير' : 'All reports'}
      </Link>
      <div data-testid="portal-report-view">
        <PublicReport token={portalReportToken(reportId)} embedded />
      </div>
    </PortalShell>
  )
}
