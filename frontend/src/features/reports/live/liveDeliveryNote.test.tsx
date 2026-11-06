import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'

import { renderWithProviders } from '@/test/utils'
import type { LiveDelivery } from '../api'
import { LiveDeliveryNote } from './LiveDeliveryNote'

/** PERF-RECOVERY-001 — a served copy says it is one; a fresh build, or a server that keeps no builds, says nothing. */
const delivery = (over: Partial<LiveDelivery>): LiveDelivery => ({ source: 'last_confirmed', built_at: '2026-10-21T09:00:00Z', refreshing: false, max_age_seconds: 900, ...over })
const text = () => screen.getByTestId('live-delivery-note').textContent ?? ''

describe('LiveDeliveryNote', () => {
  it('renders nothing when the server sent no delivery block (the store is off)', () => {
    renderWithProviders(<LiveDeliveryNote delivery={undefined} ar />)
    expect(screen.queryByTestId('live-delivery-note')).toBeNull()
  })

  it('renders nothing for a build made for this read', () => {
    renderWithProviders(<LiveDeliveryNote delivery={delivery({ source: 'fresh' })} ar />)
    expect(screen.queryByTestId('live-delivery-note')).toBeNull()
  })

  it('says a served copy is the last completed build, in Arabic', () => {
    renderWithProviders(<LiveDeliveryNote delivery={delivery({})} ar />)
    expect(text()).toBe('تعرض الصفحة آخر نسخة مكتملة من التقرير.')
  })

  it('says a newer build is being prepared only when one is', () => {
    renderWithProviders(<LiveDeliveryNote delivery={delivery({ refreshing: true })} ar={false} />)
    expect(text()).toBe('This page shows the report’s last completed build; a newer one is being prepared in the background.')
  })

  it('never claims the figures are new', () => {
    renderWithProviders(<LiveDeliveryNote delivery={delivery({ refreshing: true })} ar />)
    expect(text()).not.toMatch(/محدّث|الآن|لحظي/)
  })
})
