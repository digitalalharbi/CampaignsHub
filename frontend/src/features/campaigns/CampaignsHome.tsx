import { AgencyFit } from '@/components/fit/AgencyFit'
import { AdsManager } from './AdsManager'

/**
 * ADS-MANAGER-007 (Owner 2026-11-25, «احتاج تحويل العرض المتقدم الى تفاعلي … او الغية خله على المبسط فقط» — chose the
 * simple view only, with the advanced view's tools brought into it) — «الحملات» is the Ads Manager and nothing else.
 *
 * The old Accounts, Ad groups and Ads pages redirect here with their level (`LevelRedirect`). Bulk pause/resume, the
 * comparison, the change history and the CSV export live inside the Ads Manager. A layout choice remembered by an
 * earlier version (`ch-campaigns-layout`) is retired on first visit, so nobody is left on a view that no longer exists.
 */
export const LAYOUT_KEY = 'ch-campaigns-layout'

export function CampaignsHome() {
  try { localStorage.removeItem(LAYOUT_KEY) } catch { /* private window */ }
  return <AgencyFit active={false} testid="ads-manager-fit"><AdsManager /></AgencyFit>
}
