import { beforeEach, describe, expect, it } from 'vitest'
import { forgetAccount, readKnownAccounts, rememberAccount } from './knownAccounts'
import { clearOwnedStorage } from './signOut'

describe('knownAccounts (LOGIN-KNOWN-ACCOUNTS-001)', () => {
  beforeEach(() => window.localStorage.clear())

  it('keeps the latest sign-in first, once per email, and no secret', () => {
    rememberAccount({ email: 'Admin@CampaignsHub.io', name: 'Admin', is_platform_admin: true })
    rememberAccount({ email: 'agency@campaignshub.io', name: 'Agency' })
    rememberAccount({ email: 'admin@campaignshub.io', name: 'Admin', is_platform_admin: true })

    const list = readKnownAccounts()
    expect(list.map((a) => a.email)).toEqual(['admin@campaignshub.io', 'agency@campaignshub.io'])
    expect(list[0].isAdmin).toBe(true)
    expect(Object.keys(list[0]).sort()).toEqual(['email', 'isAdmin', 'lastUsedAt', 'name'])
  })

  it('ignores a support visit, survives sign-out clean-up, and forgets on request', () => {
    rememberAccount({ email: 'visit@x.io', name: 'V', support: { session_id: 's' } })
    rememberAccount({ email: 'agency@campaignshub.io', name: 'Agency' })
    clearOwnedStorage()
    expect(readKnownAccounts().map((a) => a.email)).toEqual(['agency@campaignshub.io'])
    expect(forgetAccount('agency@campaignshub.io')).toEqual([])
  })
})
