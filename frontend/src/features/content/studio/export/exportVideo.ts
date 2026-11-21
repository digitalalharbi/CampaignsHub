import { ExportError, frameTimeMs, MAX_OUTPUT_BYTES, type ExportPlan } from './exportPlan'

/**
 * CONTENT-STUDIO-001 batch 6 — the export loop, over injected pieces. The real ones are WebCodecs (VideoEncoder) and mp4-muxer; the
 * loop only knows the four things it needs from them, so it is tested without a browser and the browser adapter stays thin.
 *
 * Frames are drawn and encoded strictly one after another in a Worker, so a long export never freezes the page and never holds more
 * than one frame in memory. A cancel stops at the next frame boundary and discards everything.
 */
export interface FrameEncoder {
  /** Draw frame `i` and hand it to the encoder. `keyFrame` is true on the first frame and every `keyFrameInterval`. */
  encodeFrame(index: number, timestampUs: number, keyFrame: boolean, timeMs: number): Promise<void> | void
  /** Wait for the encoder to emit everything it still holds. */
  flush(): Promise<void>
  close(): void
}

export interface Muxer {
  /** Everything written so far, finalised; returns the MP4 bytes. */
  finish(): Uint8Array
}

export interface ExportInput {
  plan: ExportPlan
  encoder: FrameEncoder
  muxer: Muxer
  onProgress?: (done: number, total: number) => void
  signal?: AbortSignal
  /** Lets the event loop breathe between frames; defaults to a macrotask. */
  yieldToLoop?: () => Promise<void>
}

const defaultYield = (): Promise<void> => new Promise((resolve) => setTimeout(resolve, 0))

export async function exportVideo(input: ExportInput): Promise<Uint8Array> {
  const { plan, encoder, muxer } = input
  const breathe = input.yieldToLoop ?? defaultYield

  try {
    for (let i = 0; i < plan.frames; i++) {
      if (input.signal?.aborted) throw new ExportError('cancelled')

      await encoder.encodeFrame(i, plan.timestampsUs[i]!, i % plan.keyFrameInterval === 0, frameTimeMs(plan, i))
      input.onProgress?.(i + 1, plan.frames)
      await breathe()
    }

    if (input.signal?.aborted) throw new ExportError('cancelled')

    await encoder.flush()

    const bytes = muxer.finish()

    if (bytes.byteLength > MAX_OUTPUT_BYTES) throw new ExportError('too_large', `${bytes.byteLength} bytes`)

    return bytes
  } catch (e) {
    if (e instanceof ExportError) throw e

    throw new ExportError('encode_failed', e instanceof Error ? e.message : String(e))
  } finally {
    encoder.close()
  }
}
