import { beforeEach, describe, expect, it } from 'vitest'
import { useAuth } from './auth'
import type { AuthUser } from '@/lib/api/types'

const baseUser: AuthUser = {
  id: 'u-1',
  name: 'Test',
  email: 'test@example.com',
  tenant_id: 't-1',
  is_platform_admin: false,
  permissions: ['clients.view'],
  created_at: null,
}

describe('auth store', () => {
  beforeEach(() => useAuth.getState().setUser(null))

  it('starts as a guest with no permissions', () => {
    expect(useAuth.getState().status).toBe('guest')
    expect(useAuth.getState().hasPermission('clients.view')).toBe(false)
  })

  it('respects explicit tenant permissions when authenticated', () => {
    useAuth.getState().setUser(baseUser)
    expect(useAuth.getState().status).toBe('authenticated')
    expect(useAuth.getState().hasPermission('clients.view')).toBe(true)
    expect(useAuth.getState().hasPermission('campaigns.launch')).toBe(false)
  })

  it('grants platform admins every permission', () => {
    useAuth.getState().setUser({ ...baseUser, is_platform_admin: true, permissions: [] })
    expect(useAuth.getState().hasPermission('anything.at.all')).toBe(true)
  })

  it('returns to guest when the user is cleared', () => {
    useAuth.getState().setUser(baseUser)
    useAuth.getState().setUser(null)
    expect(useAuth.getState().user).toBeNull()
    expect(useAuth.getState().status).toBe('guest')
  })
})
