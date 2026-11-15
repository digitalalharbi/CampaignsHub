import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { PING_MAX_GAIN, PING_SECONDS, decidePing, installAudioUnlock, playPing, audioReady } from './alertSound'

/**
 * NOTIF-SOUND-001 — when the ping may play, and that the tone itself is short, soft, single and silent about autoplay.
 */
const on = { enabled: true, volume: 40, quiet: false }
const crit = (id: string) => ({ id, severity: 'critical' })

describe('decidePing', () => {
  it('plays ONCE for a batch of 5 critical notifications that arrive together', () => {
    const first = decidePing({ attention: [], seen: null, sound: on })
    const batch = decidePing({ attention: ['a', 'b', 'c', 'd', 'e'].map(crit), seen: first.seen, sound: on })

    expect(batch.play).toBe(true) // one verdict for the whole batch, not five
    expect(decidePing({ attention: ['a', 'b', 'c', 'd', 'e'].map(crit), seen: batch.seen, sound: on }).play).toBe(false)
  })

  it('plays for a warning, not for plain info', () => {
    const seen = decidePing({ attention: [], seen: null, sound: on }).seen

    expect(decidePing({ attention: [{ id: 'w', severity: 'warning' }], seen, sound: on }).play).toBe(true)
    expect(decidePing({ attention: [{ id: 'i', severity: 'info' }], seen, sound: on }).play).toBe(false)
  })

  it('does not play what was already waiting when the page opened', () => {
    expect(decidePing({ attention: [crit('old')], seen: null, sound: on }).play).toBe(false)
  })

  it('does not play in quiet hours, and does not ring when quiet hours end for what arrived during them', () => {
    const seen = decidePing({ attention: [], seen: null, sound: on }).seen
    const quiet = decidePing({ attention: [crit('n1')], seen, sound: { ...on, quiet: true } })

    expect(quiet.play).toBe(false)
    expect(decidePing({ attention: [crit('n1')], seen: quiet.seen, sound: on }).play).toBe(false)
  })

  it('does not play when the person switched it off, or set the volume to zero', () => {
    const seen = decidePing({ attention: [], seen: null, sound: on }).seen

    expect(decidePing({ attention: [crit('x')], seen, sound: { ...on, enabled: false } }).play).toBe(false)
    expect(decidePing({ attention: [crit('y')], seen, sound: { ...on, volume: 0 } }).play).toBe(false)
  })

  it('counts a warning that became critical as new', () => {
    const seen = decidePing({ attention: [{ id: 'w', severity: 'warning' }], seen: new Set(), sound: on }).seen

    expect(decidePing({ attention: [crit('w')], seen, sound: on }).play).toBe(true)
  })
})

describe('playPing', () => {
  const started: Array<{ stop: number; peak: number; type: string }> = []
  let state: AudioContextState

  class FakeContext {
    state = state
    currentTime = 0
    destination = {}
    resume() { this.state = 'running'; state = 'running'; return Promise.resolve() }
    createGain() {
      return {
        gain: {
          setValueAtTime: () => undefined,
          linearRampToValueAtTime: (v: number) => { peak = Math.max(peak, v) },
          exponentialRampToValueAtTime: () => undefined,
        },
        connect: () => undefined,
        disconnect: () => undefined,
      }
    }
    createOscillator() {
      const osc = {
        type: '',
        frequency: { setValueAtTime: () => undefined },
        onended: null as null | (() => void),
        connect: () => undefined,
        disconnect: () => undefined,
        start: () => undefined,
        stop: (t: number) => { started.push({ stop: t, peak, type: osc.type }) },
      }

      return osc
    }
  }
  let peak = 0

  beforeEach(() => {
    started.length = 0
    peak = 0
    state = 'suspended'
    vi.stubGlobal('AudioContext', FakeContext)
  })
  afterEach(() => vi.unstubAllGlobals())

  it('is silent and error-free before the first user interaction, then plays one short low sine after it', () => {
    const err = vi.spyOn(console, 'error').mockImplementation(() => undefined)
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined)

    expect(playPing(40)).toBe(false) // locked: nothing played, nothing thrown
    expect(audioReady()).toBe(false)

    installAudioUnlock()
    window.dispatchEvent(new Event('pointerdown'))
    expect(audioReady()).toBe(true)

    expect(playPing(100)).toBe(true)
    expect(started).toHaveLength(1)
    expect(started[0]!.type).toBe('sine')
    expect(started[0]!.stop).toBeLessThan(0.4) // under 400 ms
    expect(PING_SECONDS).toBeLessThan(0.4)
    expect(started[0]!.peak).toBeLessThanOrEqual(PING_MAX_GAIN) // soft: a quarter of full scale at the loudest
    expect(err).not.toHaveBeenCalled()
    expect(warn).not.toHaveBeenCalled()
  })

  it('plays nothing at volume 0', () => {
    installAudioUnlock()
    window.dispatchEvent(new Event('pointerdown'))

    expect(playPing(0)).toBe(false)
  })
})

/**
 * Firefox's order of things (CI PR #1071, notifications-bell «the ping»): the gesture reaches the page as mouse events,
 * and a context resumed inside it still reads `suspended` until its audio thread starts. The ping must still ring once,
 * after the context is running — never before the first gesture.
 */
describe('playPing, the Firefox way', () => {
  const stops: number[] = []
  let ctxState: AudioContextState = 'suspended'
  let release: () => void = () => undefined

  class SlowContext {
    get state() { return ctxState }
    currentTime = 0
    destination = {}
    resume() { return new Promise<void>((r) => { release = () => { ctxState = 'running'; r() } }) }
    createOscillator() {
      return { type: '', frequency: { setValueAtTime: () => undefined }, connect: () => undefined, disconnect: () => undefined,
        start: () => undefined, stop: (w: number) => { stops.push(w) }, onended: null }
    }
    createGain() {
      return { gain: { setValueAtTime: () => undefined, linearRampToValueAtTime: () => undefined, exponentialRampToValueAtTime: () => undefined },
        connect: () => undefined, disconnect: () => undefined }
    }
  }

  beforeEach(() => {
    stops.length = 0
    ctxState = 'suspended'
    vi.resetModules()
    vi.stubGlobal('AudioContext', SlowContext)
  })
  afterEach(() => vi.unstubAllGlobals())

  it('unlocks on a mouse-only gesture and rings once the context is running', async () => {
    const sound = await import('./alertSound')
    expect(sound.playPing(40)).toBe(false) // no gesture yet: nothing attempted

    sound.installAudioUnlock()
    window.dispatchEvent(new Event('mousedown')) // no pointer events, as Firefox under automation delivers them
    expect(sound.audioReady()).toBe(false) // still starting

    expect(sound.playPing(40)).toBe(true)
    expect(stops).toHaveLength(1) // one ping, scheduled on the starting context — it sounds when the context runs
    release()
    await Promise.resolve(); await Promise.resolve()
    expect(stops).toHaveLength(1) // and not a second one when it does
  })

  it('still schedules the ping when the context never reports running (no audio device), and never before a gesture', async () => {
    const sound = await import('./alertSound')
    sound.installAudioUnlock()
    expect(sound.playPing(40)).toBe(false)
    expect(stops).toHaveLength(0)

    window.dispatchEvent(new Event('click')) // resume() is asked and never settles
    expect(sound.playPing(40)).toBe(true)
    expect(stops).toHaveLength(1)
  })
})
