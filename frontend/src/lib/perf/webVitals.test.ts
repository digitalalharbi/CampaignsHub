import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { routeShape, sampleRate, startWebVitals, type VitalReport } from './webVitals'

/** PERF-BASELINE-WEBVITALS-001 — LCP, INP and CLS, measured in the browser, sent as nothing personal. */
type Entry = Record<string, unknown>
let observers: Array<{ type: string; cb: (list: { getEntries: () => Entry[] }) => void }> = []

class FakeObserver {
  static supportedEntryTypes = ['largest-contentful-paint', 'layout-shift', 'event']
  cb: (list: { getEntries: () => Entry[] }) => void
  constructor(cb: (list: { getEntries: () => Entry[] }) => void) { this.cb = cb }
  observe(init: { type: string }) { observers.push({ type: init.type, cb: this.cb }) }
  disconnect() { observers = observers.filter((o) => o.cb !== this.cb) }
}
const emit = (type: string, entries: Entry[]) => observers.filter((o) => o.type === type).forEach((o) => o.cb({ getEntries: () => entries }))

beforeEach(() => { observers = []; vi.stubGlobal('PerformanceObserver', FakeObserver) })
afterEach(() => { vi.unstubAllGlobals() })

describe('sampling', () => {
  it('is OFF unless a rate is configured: no observer is even constructed', () => {
    const spy = vi.fn()
    vi.stubGlobal('PerformanceObserver', class { static supportedEntryTypes = []; constructor() { spy() } observe() {} disconnect() {} })
    expect(sampleRate(undefined)).toBe(0)
    expect(startWebVitals({ rate: sampleRate(undefined), random: () => 0, report: () => {}, path: () => '/x' })).toBeNull()
    expect(spy).not.toHaveBeenCalled()
  })

  it('reads a rate from 0 to 1 and treats anything else as off', () => {
    expect(sampleRate('0.25')).toBe(0.25)
    expect(sampleRate('1')).toBe(1)
    expect(sampleRate('7')).toBe(0)
    expect(sampleRate('abc')).toBe(0)
    expect(sampleRate('-1')).toBe(0)
  })

  it('a session outside the sample measures nothing', () => {
    expect(startWebVitals({ rate: 0.1, random: () => 0.5, report: () => {}, path: () => '/x' })).toBeNull()
    expect(observers).toHaveLength(0)
  })
})

describe('what is measured', () => {
  function run() {
    const reports: VitalReport[] = []
    const stop = startWebVitals({ rate: 1, random: () => 0, report: (r) => reports.push(r), path: () => '/app/analytics' })
    return { reports, stop }
  }

  it('LCP is the last largest-contentful-paint candidate, reported when the page is hidden', () => {
    const { reports, stop } = run()
    emit('largest-contentful-paint', [{ startTime: 900 }])
    emit('largest-contentful-paint', [{ startTime: 1800 }])
    stop!.flush()
    expect(reports.find((r) => r.name === 'LCP')).toMatchObject({ value: 1800, rating: 'good' })
  })

  it('CLS is the worst session window, ignoring shifts that follow input', () => {
    const { reports, stop } = run()
    emit('layout-shift', [
      { startTime: 100, value: 0.04, hadRecentInput: false },
      { startTime: 400, value: 0.03, hadRecentInput: false },
      { startTime: 450, value: 0.5, hadRecentInput: true },
      // > 1s after the previous shift: a new window, smaller than the first.
      { startTime: 3000, value: 0.02, hadRecentInput: false },
    ])
    stop!.flush()
    const cls = reports.find((r) => r.name === 'CLS')!
    expect(cls.value).toBeCloseTo(0.07, 5)
    expect(cls.rating).toBe('good')
  })

  it('INP is the slowest interaction (an interaction is many events sharing one id)', () => {
    const { reports, stop } = run()
    emit('event', [
      { interactionId: 1, duration: 56 },
      { interactionId: 1, duration: 120 },
      { interactionId: 2, duration: 232 },
      { interactionId: 0, duration: 999 }, // not an interaction (hover/scroll noise)
    ])
    stop!.flush()
    expect(reports.find((r) => r.name === 'INP')).toMatchObject({ value: 232, rating: 'needs-improvement' })
  })

  it('rates against the Owner targets: LCP 2500, INP 200, CLS 0.1', () => {
    const { reports, stop } = run()
    emit('largest-contentful-paint', [{ startTime: 2600 }])
    emit('layout-shift', [{ startTime: 10, value: 0.3, hadRecentInput: false }])
    stop!.flush()
    expect(reports.find((r) => r.name === 'LCP')!.rating).not.toBe('good')
    expect(reports.find((r) => r.name === 'CLS')!.rating).toBe('poor')
  })

  it('reports each metric once per flush and nothing for a metric that never fired', () => {
    const { reports, stop } = run()
    stop!.flush()
    expect(reports).toEqual([])
  })
})

describe('what is sent', () => {
  it('is a fixed, personal-data-free shape', () => {
    const reports: VitalReport[] = []
    const stop = startWebVitals({ rate: 1, random: () => 0, report: (r) => reports.push(r), path: () => '/app/analytics' })
    emit('largest-contentful-paint', [{ startTime: 1000 }])
    stop!.flush()
    expect(Object.keys(reports[0]).sort()).toEqual(['name', 'navigation', 'path', 'rating', 'value'])
    expect(reports[0].path).toBe('/app/analytics')
  })

  it('reduces an address to its route shape: ids, tokens, query and hash never leave the browser', () => {
    expect(routeShape('/agency/analytics?project=3f2b8c1e-aaaa-bbbb-cccc-123456789012#x')).toBe('/agency/analytics')
    expect(routeShape('/app/campaigns/3f2b8c1e-aaaa-bbbb-cccc-123456789012')).toBe('/app/campaigns/:id')
    expect(routeShape('/app/clients/42/reports/918')).toBe('/app/clients/:id/reports/:id')
    expect(routeShape('/r/Zk3pQ9vLmN2xT8aBcD4eFgHiJkLm')).toBe('/r/:id')
    expect(routeShape('/reports/share/Zk3pQ9vLmN2xT8aBcD4eFgHiJkLm')).toBe('/reports/share/:id')
    expect(routeShape('/portal/someone@example.com')).toBe('/portal/:id')
  })
})
