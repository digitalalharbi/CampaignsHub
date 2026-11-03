import { describe, expect, it } from 'vitest'
import { formatFailedRequest, isEntryBundleUrl, type RequestTiming } from '../../e2e/failedRequest'

/** GATE-WK-ENGINE-FAULT-001 — the evidence line has to say which request, which error, which phase. */
const NEVER: RequestTiming = {
  startTime: 1, domainLookupStart: -1, domainLookupEnd: -1, connectStart: -1, connectEnd: -1,
  requestStart: -1, responseStart: -1, responseEnd: -1,
}

describe('failed entry-bundle request evidence', () => {
  it('recognises the three entry requests and nothing else', () => {
    expect(isEntryBundleUrl('http://localhost:5273/assets/index-AbC123_x.js')).toBe(true)
    expect(isEntryBundleUrl('http://localhost:5273/assets/index-AbC123_x.js?v=1')).toBe(true)
    expect(isEntryBundleUrl('http://localhost:5173/@vite/client')).toBe(true)
    expect(isEntryBundleUrl('http://localhost:5173/src/main.tsx')).toBe(true)
    expect(isEntryBundleUrl('http://localhost:5273/assets/index-AbC123_x.css')).toBe(false)
    expect(isEntryBundleUrl('http://localhost:5273/assets/Campaigns-9f8e.js')).toBe(false)
    expect(isEntryBundleUrl('http://localhost:5273/api/v1/me')).toBe(false)
  })

  it('prints url, error text and phase timings, with - for phases that never happened', () => {
    const line = formatFailedRequest(
      {
        method: 'GET',
        url: 'http://localhost:5273/assets/index-AbC123_x.js',
        resourceType: 'script',
        errorText: 'WebKit encountered an internal error',
        timing: { ...NEVER, connectEnd: 0.4, requestStart: 1.2, responseStart: -1 },
      },
      { project: 'webkit', title: 'logs in', at: Date.UTC(2026, 10, 3, 12, 0, 0) },
    )
    expect(line).toBe(
      '[wk-evidence] FAILED-REQUEST ENTRY at=2026-11-03T12:00:00.000Z project=webkit test="logs in" ' +
        'GET http://localhost:5273/assets/index-AbC123_x.js resource=script ' +
        'error="WebKit encountered an internal error" timing_ms[dns=- connect=0 request=1 response=- end=-]',
    )
  })

  it('marks a non-entry request as other and survives a missing error text', () => {
    const line = formatFailedRequest(
      { method: 'GET', url: 'http://localhost:5273/fonts/a.woff2', resourceType: 'font', errorText: undefined, timing: NEVER },
      { project: 'chromium', title: 't', at: 0 },
    )
    expect(line).toContain('FAILED-REQUEST other')
    expect(line).toContain('error="unknown"')
  })
})
