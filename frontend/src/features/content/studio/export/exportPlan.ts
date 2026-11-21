import { MAX_DURATION_MS } from '../direction/timeline'

/**
 * CONTENT-STUDIO-001 batch 6 — what an export is: 1080×1920, 30 fps, H.264 High at 6–8 Mbps, AAC 128 kbps, never longer than 15 seconds.
 * The plan is plain numbers, decided before any encoder is touched, so the same plan drives the real encoder and the tests.
 */
export const FPS = 30
export const VIDEO_BITRATE = 7_000_000
export const KEYFRAME_EVERY_SECONDS = 2
/** H.264 High profile, level 4.2 — the level 1080×1920 at 30 fps needs. */
export const VIDEO_CODEC = 'avc1.64002a'
export const AUDIO_CODEC = 'mp4a.40.2'
export const AUDIO_BITRATE = 128_000
export const AUDIO_SAMPLE_RATE = 48_000
/** A saved output is a normal asset in the library; this keeps one export from being a runaway upload. */
export const MAX_OUTPUT_BYTES = 50 * 1024 * 1024

export interface ExportPlan {
  width: number
  height: number
  fps: number
  durationMs: number
  frames: number
  bitrate: number
  keyFrameInterval: number
  videoCodec: string
  audio: { codec: string; bitrate: number; sampleRate: number; channels: 2 } | null
  /** Timestamp of each frame in microseconds, the unit WebCodecs uses. */
  timestampsUs: number[]
}

export type ExportErrorCode = 'too_long' | 'not_supported' | 'cancelled' | 'too_large' | 'encode_failed'

export class ExportError extends Error {
  readonly code: ExportErrorCode

  constructor(code: ExportErrorCode, message?: string) {
    super(message ?? code)
    this.code = code
  }
}

export function planExport(input: { width: number; height: number; durationMs: number; withAudio: boolean }): ExportPlan {
  if (input.durationMs > MAX_DURATION_MS) throw new ExportError('too_long', `${input.durationMs}ms is over the ${MAX_DURATION_MS}ms ceiling`)

  const frames = Math.max(1, Math.round((input.durationMs / 1000) * FPS))

  return {
    width: input.width,
    height: input.height,
    fps: FPS,
    durationMs: input.durationMs,
    frames,
    bitrate: VIDEO_BITRATE,
    keyFrameInterval: KEYFRAME_EVERY_SECONDS * FPS,
    videoCodec: VIDEO_CODEC,
    audio: input.withAudio ? { codec: AUDIO_CODEC, bitrate: AUDIO_BITRATE, sampleRate: AUDIO_SAMPLE_RATE, channels: 2 } : null,
    timestampsUs: Array.from({ length: frames }, (_, i) => Math.round((i * 1_000_000) / FPS)),
  }
}

/** The time of frame `i` in milliseconds, for drawing it. */
export const frameTimeMs = (plan: ExportPlan, i: number): number => (i * 1000) / plan.fps
