import { useState } from 'react'
import { AgencyFit } from '@/components/fit/AgencyFit'
import { AdsManager } from './AdsManager'
import { CampaignsPage } from './CampaignsPage'

/**
 * ADS-MANAGER-001 — «الحملات» opens on the simple Ads Manager; the full campaigns workspace is «عرض متقدم».
 *
 * The choice is remembered per browser, so a person who works in the advanced view is not sent back every visit.
 */
export const LAYOUT_KEY = 'ch-campaigns-layout'

function readLayout(): 'simple' | 'advanced' {
  try { return localStorage.getItem(LAYOUT_KEY) === 'advanced' ? 'advanced' : 'simple' } catch { return 'simple' }
}

export function CampaignsHome() {
  const [layout, setLayout] = useState(readLayout)
  const choose = (next: 'simple' | 'advanced') => {
    setLayout(next)
    try { localStorage.setItem(LAYOUT_KEY, next) } catch { /* a private window keeps the choice for this visit */ }
  }

  if (layout === 'advanced') return <CampaignsPage onSimpleView={() => choose('simple')} />

  return <AgencyFit active={false} testid="ads-manager-fit"><AdsManager onAdvanced={() => choose('advanced')} /></AgencyFit>
}
