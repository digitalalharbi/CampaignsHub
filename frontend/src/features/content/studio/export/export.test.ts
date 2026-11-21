import { describe, expect, it } from 'vitest'
import { detectCapability, requireVideo } from './capability'
import { AUDIO_CODEC, ExportError, frameTimeMs, MAX_OUTPUT_BYTES, planExport, VIDEO_BITRATE } from './exportPlan'
import { exportVideo, type FrameEncoder, type Muxer } from './exportVideo'

const plan12 = planExport({ width: 1080, height: 1920, durationMs: 12_000, withAudio: true })

describe('the export plan', () => {
  it('is 1080×1920, 30 fps, 360 frames for the default 12 seconds, H.264 High at 7 Mbps and AAC 128k', () => {
    expect(plan12.frames).toBe(360)
    expect(plan12.fps).toBe(30)
    expect(plan12.bitrate).toBe(VIDEO_BITRATE)
    expect(plan12.bitrate).toBeGreaterThanOrEqual(6_000_000)
    expect(plan12.bitrate).toBeLessThanOrEqual(8_000_000)
    expect(plan12.videoCodec.startsWith('avc1.64')).toBe(true)
    expect(plan12.audio).toEqual({ codec: AUDIO_CODEC, bitrate: 128_000, sampleRate: 48_000, channels: 2 })
    expect(plan12.keyFrameInterval).toBe(60)
  })

  it('refuses anything longer than 15 seconds, and accepts exactly 15', () => {
    expect(() => planExport({ width: 1080, height: 1920, durationMs: 15_001, withAudio: false })).toThrowError(ExportError)
    expect(planExport({ width: 1080, height: 1920, durationMs: 15_000, withAudio: false }).frames).toBe(450)
  })

  it('timestamps are in microseconds, strictly increasing, one per frame', () => {
    expect(plan12.timestampsUs).toHaveLength(plan12.frames)
    expect(plan12.timestampsUs[0]).toBe(0)
    expect(plan12.timestampsUs[30]).toBe(1_000_000)
    for (let i = 1; i < plan12.timestampsUs.length; i++) expect(plan12.timestampsUs[i]!).toBeGreaterThan(plan12.timestampsUs[i - 1]!)
  })

  it('the draw time of frame i is i/30 seconds', () => {
    expect(frameTimeMs(plan12, 0)).toBe(0)
    expect(frameTimeMs(plan12, 45)).toBe(1500)
  })

  it('no audio means no audio config', () => {
    expect(planExport({ width: 1080, height: 1920, durationMs: 6_000, withAudio: false }).audio).toBeNull()
  })
})

function fakes(options: { bytes?: number; failAt?: number } = {}) {
  const log: string[] = []
  const frames: Array<{ i: number; key: boolean; t: number }> = []
  const encoder: FrameEncoder = {
    encodeFrame(i, _ts, key, t) {
      if (options.failAt === i) throw new Error('encoder exploded')
      frames.push({ i, key, t })
    },
    async flush() { log.push('flush') },
    close() { log.push('close') },
  }
  const muxer: Muxer = { finish() { log.push('finish'); return new Uint8Array(options.bytes ?? 1000) } }

  return { encoder, muxer, log, frames }
}
const instant = () => Promise.resolve()

describe('the export loop', () => {
  it('encodes every frame in order, flushes, finishes the file and closes the encoder', async () => {
    const f = fakes()
    const bytes = await exportVideo({ plan: plan12, encoder: f.encoder, muxer: f.muxer, yieldToLoop: instant })

    expect(f.frames).toHaveLength(360)
    expect(f.frames.map((x) => x.i)).toEqual(Array.from({ length: 360 }, (_, i) => i))
    expect(f.log).toEqual(['flush', 'finish', 'close'])
    expect(bytes.byteLength).toBe(1000)
  })

  it('marks the first frame and every 60th as a keyframe, and no others', () => {
    const f = fakes()

    return exportVideo({ plan: plan12, encoder: f.encoder, muxer: f.muxer, yieldToLoop: instant }).then(() => {
      expect(f.frames.filter((x) => x.key).map((x) => x.i)).toEqual([0, 60, 120, 180, 240, 300])
    })
  })

  it('reports progress up to the total, once per frame', async () => {
    const f = fakes()
    const seen: Array<[number, number]> = []

    await exportVideo({ plan: plan12, encoder: f.encoder, muxer: f.muxer, yieldToLoop: instant, onProgress: (d, t) => seen.push([d, t]) })

    expect(seen).toHaveLength(360)
    expect(seen[0]).toEqual([1, 360])
    expect(seen.at(-1)).toEqual([360, 360])
  })

  it('a cancel stops at the next frame boundary, produces no file and still closes the encoder', async () => {
    const f = fakes()
    const ctl = new AbortController()
    let n = 0
    const encoder: FrameEncoder = { ...f.encoder, encodeFrame(i, ts, k, t) { if (++n === 10) ctl.abort(); return f.encoder.encodeFrame(i, ts, k, t) } }

    await expect(exportVideo({ plan: plan12, encoder, muxer: f.muxer, yieldToLoop: instant, signal: ctl.signal })).rejects.toMatchObject({ code: 'cancelled' })

    expect(f.frames.length).toBeLessThan(15)
    expect(f.log).toEqual(['close'])
  })

  it('an encoder failure becomes a typed error and the encoder is still closed', async () => {
    const f = fakes({ failAt: 5 })

    await expect(exportVideo({ plan: plan12, encoder: f.encoder, muxer: f.muxer, yieldToLoop: instant })).rejects.toMatchObject({ code: 'encode_failed' })
    expect(f.log).toEqual(['close'])
  })

  it('refuses a file over the size ceiling instead of handing it on', async () => {
    const f = fakes({ bytes: MAX_OUTPUT_BYTES + 1 })

    await expect(exportVideo({ plan: plan12, encoder: f.encoder, muxer: f.muxer, yieldToLoop: instant })).rejects.toMatchObject({ code: 'too_large' })
  })
})

describe('what this browser can do', () => {
  const supported = { isConfigSupported: async () => ({ supported: true }) }

  it('a browser with both encoders can export the video and the sound', async () => {
    const c = await detectCapability({ VideoEncoder: supported, AudioEncoder: supported }, plan12)

    expect(c).toEqual({ video: true, audio: true, reasons: [] })
    expect(() => requireVideo(c)).not.toThrow()
  })

  it('a browser without WebCodecs keeps everything else and states why it cannot make the MP4', async () => {
    const c = await detectCapability({}, plan12)

    expect(c.video).toBe(false)
    expect(c.reasons).toContain('no_webcodecs')
    expect(() => requireVideo(c)).toThrowError(ExportError)
  })

  it('a missing audio encoder still allows a silent video', async () => {
    const c = await detectCapability({ VideoEncoder: supported }, plan12)

    expect(c.video).toBe(true)
    expect(c.audio).toBe(false)
    expect(c.reasons).toContain('no_audio_encoder')
    expect(() => requireVideo(c)).not.toThrow()
  })

  it('an unsupported video config, or an encoder that throws, is «not supported», not a crash', async () => {
    const no = { isConfigSupported: async () => ({ supported: false }) }
    const boom = { isConfigSupported: async () => { throw new Error('nope') } }

    expect((await detectCapability({ VideoEncoder: no }, plan12)).reasons).toContain('video_config_unsupported')
    expect((await detectCapability({ VideoEncoder: boom }, plan12)).video).toBe(false)
  })
})
