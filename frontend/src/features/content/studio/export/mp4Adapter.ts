import { ArrayBufferTarget, Muxer as Mp4Muxer } from 'mp4-muxer'
import type { FrameEncoder, Muxer } from './exportVideo'
import type { ExportPlan } from './exportPlan'

/**
 * CONTENT-STUDIO-001 batch 6 — the browser adapter: WebCodecs `VideoEncoder` feeding mp4-muxer into memory. It is deliberately thin
 * (everything with a decision in it lives in `exportVideo` and `exportPlan`), because it can only run in a real browser.
 *
 * It is type-checked here but NOT executed by any test: a headless run cannot prove an encoded file plays. That proof is the live
 * export in Chrome, recorded separately, and until it exists this adapter is `IMPLEMENTED_NOT_VERIFIED`.
 *
 * Note: mp4-muxer is MIT and its author now points to Mediabunny as its successor; the pinned version is stable and the adapter is
 * the only file that imports it, so a later swap touches this file alone.
 */
export interface Mp4Pipeline {
  encoder: FrameEncoder
  muxer: Muxer
}

export function createMp4Pipeline(plan: ExportPlan, drawFrameToCanvas: (timeMs: number) => OffscreenCanvas | HTMLCanvasElement): Mp4Pipeline {
  const target = new ArrayBufferTarget()
  const mux = new Mp4Muxer({
    target,
    video: { codec: 'avc', width: plan.width, height: plan.height, frameRate: plan.fps },
    fastStart: 'in-memory',
  })

  let failure: unknown = null
  const videoEncoder = new VideoEncoder({
    output: (chunk, meta) => mux.addVideoChunk(chunk, meta),
    error: (e) => {
      failure = e
    },
  })

  videoEncoder.configure({ codec: plan.videoCodec, width: plan.width, height: plan.height, bitrate: plan.bitrate, framerate: plan.fps })

  const encoder: FrameEncoder = {
    async encodeFrame(_index, timestampUs, keyFrame, timeMs) {
      if (failure) throw failure instanceof Error ? failure : new Error(String(failure))

      const frame = new VideoFrame(drawFrameToCanvas(timeMs), { timestamp: timestampUs, duration: Math.round(1_000_000 / plan.fps) })

      videoEncoder.encode(frame, { keyFrame })
      frame.close()
    },
    async flush() {
      await videoEncoder.flush()

      if (failure) throw failure instanceof Error ? failure : new Error(String(failure))
    },
    close() {
      if (videoEncoder.state !== 'closed') videoEncoder.close()
    },
  }

  const muxer: Muxer = {
    finish() {
      mux.finalize()

      return new Uint8Array(target.buffer)
    },
  }

  return { encoder, muxer }
}
