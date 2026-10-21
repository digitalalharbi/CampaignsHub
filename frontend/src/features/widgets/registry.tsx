import type { ComponentType } from 'react'
import { AttributionCreditWidget } from './AttributionCreditWidget'
import { CommerceOutcomesWidget } from './CommerceOutcomesWidget'
import { LinkActivityWidget } from './LinkActivityWidget'
import { ReportViewsWidget } from './ReportViewsWidget'

export interface WidgetProps {
  projectId: string
  range: { from: string; to: string }
}

/**
 * WIDGET-REGISTRY-001 — the drawing for each widget the server's registry lists.
 *
 * The server decides which widgets EXIST, whether their module is on, and what is visible and in what order; this
 * only maps a key to a component. A key the server lists and this does not know is skipped, so a newer server never
 * breaks an older page, and a widget cannot appear here that the server does not offer.
 */
export const WIDGET_COMPONENTS: Record<string, ComponentType<WidgetProps>> = {
  link_opens: LinkActivityWidget,
  attribution_credit: AttributionCreditWidget,
  report_views: ReportViewsWidget,
  commerce_outcomes: CommerceOutcomesWidget,
}
