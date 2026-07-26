import { describe, expect, it } from 'vitest'
import { safeRedirect } from './redirect'

describe('safeRedirect', () => {
  it('returns the fallback for null/empty', () => {
    expect(safeRedirect(null)).toBe('/')
    expect(safeRedirect('')).toBe('/')
    expect(safeRedirect(null, '/dashboard')).toBe('/dashboard')
  })

  it('honours in-app absolute paths (incl. encoded)', () => {
    expect(safeRedirect('/campaigns/42')).toBe('/campaigns/42')
    expect(safeRedirect(encodeURIComponent('/projects/7?tab=analytics'))).toBe('/projects/7?tab=analytics')
  })

  it('rejects open-redirect and off-site targets', () => {
    expect(safeRedirect('https://evil.example')).toBe('/')
    expect(safeRedirect('//evil.example')).toBe('/')
    expect(safeRedirect('/\\evil.example')).toBe('/')
    expect(safeRedirect('javascript:alert(1)')).toBe('/')
    expect(safeRedirect('relative/path')).toBe('/')
  })

  it('never bounces back to an auth page (no login loop)', () => {
    expect(safeRedirect('/login')).toBe('/')
    expect(safeRedirect('/login?redirect=/x')).toBe('/')
    expect(safeRedirect('/register')).toBe('/')
    expect(safeRedirect('/forgot-password')).toBe('/')
    // but a path merely starting with those words is fine
    expect(safeRedirect('/logins-report')).toBe('/logins-report')
  })
})
