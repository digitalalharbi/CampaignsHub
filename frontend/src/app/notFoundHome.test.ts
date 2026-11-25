import { describe, expect, it } from 'vitest'
import { homeFor } from './portalPath'

/** ROUTE-HOME-001 — «العودة إلى الصفحة الرئيسية» on an error inside a portal stays in that portal. */
describe('the error page\'s way home', () => {
  it('returns to the portal the reader is in', () => {
    expect(homeFor('/agency/analytics', true)).toBe('/agency/dashboard')
    expect(homeFor('/app/campaigns/p/c', true)).toBe('/app/dashboard')
    expect(homeFor('/admin/tenants', true)).toBe('/admin')
    expect(homeFor('/portal/acme/reports', true)).toBe('/portal')
    expect(homeFor('/influencers/roster', true)).toBe('/influencers')
  })

  it('does not mistake a lookalike prefix for a portal', () => {
    expect(homeFor('/agencyx', false)).toBe('/')
    expect(homeFor('/application', true)).toBe('/dashboard')
  })

  it('sends a signed-in reader outside a portal to their own dashboard, and only a guest to the public page', () => {
    expect(homeFor('/no-such-page', true)).toBe('/dashboard')
    expect(homeFor('/no-such-page', false)).toBe('/')
  })
})
