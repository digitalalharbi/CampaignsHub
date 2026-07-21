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
  beforeEach(() => useAuth.getState().clear())

  it('has no permissions when signed out', () => {
    expect(useAuth.getState().hasPermission('clients.view')).toBe(false)
  })

  it('respects explicit tenant permissions', () => {
    useAuth.getState().setSession(baseUser, 'token')
    expect(useAuth.getState().hasPermission('clients.view')).toBe(true)
    expect(useAuth.getState().hasPermission('campaigns.launch')).toBe(false)
  })

  it('grants platform admins every permission', () => {
    useAuth.getState().setSession({ ...baseUser, is_platform_admin: true, permissions: [] }, 'token')
    expect(useAuth.getState().hasPermission('anything.at.all')).toBe(true)
  })

  it('clears the session', () => {
    useAuth.getState().setSession(baseUser, 'token')
    useAuth.getState().clear()
    expect(useAuth.getState().user).toBeNull()
    expect(useAuth.getState().token).toBeNull()
  })
})
