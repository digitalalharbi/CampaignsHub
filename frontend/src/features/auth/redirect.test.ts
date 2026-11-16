import { describe, expect, it } from 'vitest'
import { safeRedirect } from './redirect'

describe('safeRedirect', () => {
  it('returns the fallback (dashboard) for null/empty', () => {
    expect(safeRedirect(null)).toBe('/app/home')
    expect(safeRedirect('')).toBe('/app/home')
    expect(safeRedirect(null, '/somewhere')).toBe('/somewhere')
  })

  it('honours in-app absolute paths (incl. encoded)', () => {
    expect(safeRedirect('/campaigns/42')).toBe('/campaigns/42')
    expect(safeRedirect(encodeURIComponent('/projects/7?tab=analytics'))).toBe('/projects/7?tab=analytics')
  })

  it('rejects open-redirect and off-site targets', () => {
    expect(safeRedirect('https://evil.example')).toBe('/app/home')
    expect(safeRedirect('//evil.example')).toBe('/app/home')
    expect(safeRedirect('/\\evil.example')).toBe('/app/home')
    expect(safeRedirect('javascript:alert(1)')).toBe('/app/home')
    expect(safeRedirect('relative/path')).toBe('/app/home')
  })

  it('never bounces back to an auth page (no login loop)', () => {
    expect(safeRedirect('/login')).toBe('/app/home')
    expect(safeRedirect('/login?redirect=/x')).toBe('/app/home')
    expect(safeRedirect('/register')).toBe('/app/home')
    expect(safeRedirect('/forgot-password')).toBe('/app/home')
    // but a path merely starting with those words is fine
    expect(safeRedirect('/logins-report')).toBe('/logins-report')
  })
})
