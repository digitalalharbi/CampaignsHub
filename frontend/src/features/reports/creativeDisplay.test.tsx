import { describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { useState } from 'react'
import { ShareCreativeControls, creativeSharingBody, emptyCreativeSharing, type CreativeSharing } from './ShareCreativeControls'
import { renderWithProviders } from '@/test/utils'

vi.mock('@/features/content/api', () => ({ listCreatives: vi.fn(() => Promise.resolve({ creatives: [] })) }))

function Harness({ onValue }: { onValue: (v: CreativeSharing) => void }) {
  const [value, setValue] = useState<CreativeSharing>({ ...emptyCreativeSharing(), permissions: { creatives: true } })
  onValue(value)

  return <ShareCreativeControls projectId="p1" value={value} onChange={setValue} />
}

/** CREATIVE-DISPLAY-001 — the ad section's policy is chosen on the panel and sent with the link. */
describe('creative display policy', () => {
  it('defaults to period activity, auto-refresh on, zero-activity ads left out', () => {
    expect(creativeSharingBody({ ...emptyCreativeSharing(), permissions: { creatives: true } })).toMatchObject({
      creatives: { creatives: true },
      creative_display: { policy: 'activity', include_zero_activity: false, auto_refresh: true },
    })
  })

  it('sends no policy while the ad section is off', () => {
    expect(creativeSharingBody(emptyCreativeSharing())).not.toHaveProperty('creative_display')
  })

  it('lets the operator pick another policy and include zero-activity ads', () => {
    let latest: CreativeSharing = emptyCreativeSharing()
    renderWithProviders(<Harness onValue={(v) => { latest = v }} />, { locale: 'en' })

    fireEvent.click(screen.getByRole('button', { name: /Ad ads/i }))
    fireEvent.click(screen.getByTestId('creative-include-zero'))
    expect(creativeSharingBody(latest)).toMatchObject({ creative_display: { policy: 'activity', include_zero_activity: true } })

    fireEvent.click(screen.getByTestId('creative-policy-all'))
    expect(creativeSharingBody(latest)).toMatchObject({ creative_display: { policy: 'all' } })
  })
})
