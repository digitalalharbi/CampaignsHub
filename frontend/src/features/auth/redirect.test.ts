import { describe, expect, it } from 'vitest'
import { safeRedirect } from './redirect'

describe('safeRedirect', () => {
  it('returns the fallback (dashboard) for null/empty', () => {
    expect(safeRedirect(null)).toBe('/dashboard')
    expect(safeRedirect('')).toBe('/dashboard')
    expect(safeRedirect(null, '/somewhere')).toBe('/somewhere')
  })

  it('honours in-app absolute paths (incl. encoded)', () => {
    expect(safeRedirect('/campaigns/42')).toBe('/campaigns/42')
    expect(safeRedirect(encodeURIComponent('/projects/7?tab=analytics'))).toBe('/projects/7?tab=analytics')
  })

  it('rejects open-redirect and off-site targets', () => {
    expect(safeRedirect('https://evil.example')).toBe('/dashboard')
    expect(safeRedirect('//evil.example')).toBe('/dashboard')
    expect(safeRedirect('/\\evil.example')).toBe('/dashboard')
    expect(safeRedirect('javascript:alert(1)')).toBe('/dashboard')
    expect(safeRedirect('relative/path')).toBe('/dashboard')
  })

  it('never bounces back to an auth page (no login loop)', () => {
    expect(safeRedirect('/login')).toBe('/dashboard')
    expect(safeRedirect('/login?redirect=/x')).toBe('/dashboard')
    expect(safeRedirect('/register')).toBe('/dashboard')
    expect(safeRedirect('/forgot-password')).toBe('/dashboard')
    // but a path merely starting with those words is fine
    expect(safeRedirect('/logins-report')).toBe('/logins-report')
  })
})
