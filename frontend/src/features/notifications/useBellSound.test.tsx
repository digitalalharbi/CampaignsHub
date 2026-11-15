import { renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import * as sound from '@/lib/alertSound'
import { useBellSound } from './useBellSound'
import type { BellSummary } from './api'

/** NOTIF-SOUND-001 — the wiring: one ping per refresh that brought something new, none for the first load. */
const on = { enabled: true, volume: 40, quiet: false }
const summary = (ids: string[], over: Partial<BellSummary> = {}): BellSummary => ({
  unread: ids.length, attention: ids.map((id) => ({ id, severity: 'critical' as const })), sound: on, ...over,
})

describe('useBellSound', () => {
  let ping: ReturnType<typeof vi.spyOn>

  beforeEach(() => { ping = vi.spyOn(sound, 'playPing').mockReturnValue(true) })
  afterEach(() => vi.restoreAllMocks())

  it('stays silent on the first summary, then pings once for five that arrive together', () => {
    const { rerender } = renderHook(({ s }) => useBellSound(s), { initialProps: { s: summary(['old']) } })
    expect(ping).not.toHaveBeenCalled()

    rerender({ s: summary(['old', 'a', 'b', 'c', 'd', 'e']) })
    expect(ping).toHaveBeenCalledTimes(1)
    expect(ping).toHaveBeenCalledWith(40)

    rerender({ s: summary(['old', 'a', 'b', 'c', 'd', 'e'], { unread: 7 }) })
    expect(ping).toHaveBeenCalledTimes(1)
  })

  it('does not ping in quiet hours or when disabled', () => {
    const { rerender } = renderHook(({ s }) => useBellSound(s), { initialProps: { s: summary([]) } })

    rerender({ s: summary(['q1'], { sound: { ...on, quiet: true } }) })
    rerender({ s: summary(['q1', 'q2'], { sound: { ...on, enabled: false } }) })

    expect(ping).not.toHaveBeenCalled()
  })

  it('does not ping for plain info', () => {
    const { rerender } = renderHook(({ s }) => useBellSound(s), { initialProps: { s: summary([]) } })

    rerender({ s: { unread: 1, attention: [], sound: on } })

    expect(ping).not.toHaveBeenCalled()
  })
})
