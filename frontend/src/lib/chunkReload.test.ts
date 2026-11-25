import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { isChunkLoadError, reloadOnceFor, scheduleChunkReload } from './chunkReload'

/**
 * CHUNK-RELOAD-001 — the Owner opened «التحليلات» after a release and met «حدث خطأ في عرض هذه الصفحة»: a section of
 * the board was loaded with a raw `lazy()` that never reloaded on a removed chunk. These tests pin the mechanism and
 * forbid the raw call from coming back.
 */
describe('chunk reload', () => {
  const reload = vi.fn()

  beforeEach(() => {
    vi.useFakeTimers()
    sessionStorage.clear()
    reload.mockReset()
    Object.defineProperty(window, 'location', { configurable: true, value: { ...window.location, reload } })
  })

  afterEach(() => {
    vi.runOnlyPendingTimers()
    vi.useRealTimers()
  })

  it('recognises every engine\'s wording, and nothing else', () => {
    expect(isChunkLoadError(new TypeError('Failed to fetch dynamically imported module: https://campaignshub.io/assets/ReportFromView-CLIVq8Vj.js'))).toBe(true)
    expect(isChunkLoadError(new TypeError('error loading dynamically imported module'))).toBe(true)
    expect(isChunkLoadError(new TypeError('Importing a module script failed.'))).toBe(true)
    expect(isChunkLoadError(new TypeError("Cannot read properties of undefined (reading 'slice')"))).toBe(false)
    expect(isChunkLoadError(undefined)).toBe(false)
  })

  it('reloads exactly once, after the delay', () => {
    expect(scheduleChunkReload()).toBe(true)
    expect(reload).not.toHaveBeenCalled()
    vi.advanceTimersByTime(1000)
    expect(reload).toHaveBeenCalledTimes(1)

    // The single reload is spent: the next failure reaches the error boundary instead of looping.
    expect(scheduleChunkReload()).toBe(false)
    expect(() => reloadOnceFor(new TypeError('Failed to fetch dynamically imported module: x'))).toThrow()
  })

  it('no raw React lazy() outside the reload-aware helper', () => {
    const src = join(__dirname, '..')
    const offenders: string[] = []
    const walk = (dir: string) => {
      for (const name of readdirSync(dir)) {
        const full = join(dir, name)
        if (statSync(full).isDirectory()) walk(full)
        else if (/\.tsx?$/.test(name) && !/\.test\.tsx?$/.test(name) && !full.endsWith('chunkReload.ts')) {
          const text = readFileSync(full, 'utf8')
          if (/(?<![\w.])lazy\(/.test(text) || /React\.lazy\(/.test(text)) offenders.push(relative(src, full))
        }
      }
    }
    walk(src)
    expect(offenders, 'use lazyWithReload from @/lib/chunkReload').toEqual([])
  })
})
