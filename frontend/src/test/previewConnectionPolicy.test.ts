import { EventEmitter } from 'node:events'
import { describe, expect, it } from 'vitest'
import {
  applyPreviewConnectionPolicy,
  formatSocketClose,
  PREVIEW_HEADERS_TIMEOUT_MS,
  PREVIEW_KEEP_ALIVE_TIMEOUT_MS,
} from '../build/previewConnectionPolicy'

/** GATE-WK-ENGINE-FAULT-001 (experiment) — the preview server must outlive any client reuse window. */
describe('preview connection policy', () => {
  it('raises keepAliveTimeout above Node’s 5 s default and headersTimeout above it', () => {
    const server = Object.assign(new EventEmitter(), { keepAliveTimeout: 5000, headersTimeout: 60000 })
    applyPreviewConnectionPolicy(server as never)
    expect(server.keepAliveTimeout).toBe(PREVIEW_KEEP_ALIVE_TIMEOUT_MS)
    expect(server.keepAliveTimeout).toBeGreaterThan(5000)
    expect(server.headersTimeout).toBeGreaterThan(server.keepAliveTimeout)
    expect(server.headersTimeout).toBe(PREVIEW_HEADERS_TIMEOUT_MS)
  })

  it('formats a close line carrying idle time and request count', () => {
    expect(formatSocketClose(3, false, 7, 5000.4, 12000)).toMatch(
      /^\S+ close socket=3 hadError=false requests=7 idle_ms=5000 age_ms=12000$/,
    )
  })
})
