import { ExportError, type ExportPlan } from './exportPlan'

/**
 * CONTENT-STUDIO-001 batch 6 — can this browser export at all? Detected, never assumed: a browser without WebCodecs (older Safari)
 * keeps every preview and image export and simply does not offer the MP4, with the reason stated, instead of failing half way.
 */
export interface EncoderEnv {
  VideoEncoder?: { isConfigSupported(config: Record<string, unknown>): Promise<{ supported?: boolean }> }
  AudioEncoder?: { isConfigSupported(config: Record<string, unknown>): Promise<{ supported?: boolean }> }
}

export interface Capability {
  video: boolean
  audio: boolean
  reasons: Array<'no_webcodecs' | 'video_config_unsupported' | 'no_audio_encoder' | 'audio_config_unsupported'>
}

export async function detectCapability(env: EncoderEnv, plan: ExportPlan): Promise<Capability> {
  const reasons: Capability['reasons'] = []
  let video = false
  let audio = false

  if (!env.VideoEncoder) reasons.push('no_webcodecs')
  else {
    const r = await env.VideoEncoder.isConfigSupported({ codec: plan.videoCodec, width: plan.width, height: plan.height, bitrate: plan.bitrate, framerate: plan.fps }).catch(() => ({ supported: false }))

    video = r.supported === true
    if (!video) reasons.push('video_config_unsupported')
  }

  if (plan.audio) {
    if (!env.AudioEncoder) reasons.push('no_audio_encoder')
    else {
      const r = await env.AudioEncoder.isConfigSupported({ codec: plan.audio.codec, sampleRate: plan.audio.sampleRate, numberOfChannels: plan.audio.channels, bitrate: plan.audio.bitrate }).catch(() => ({ supported: false }))

      audio = r.supported === true
      if (!audio) reasons.push('audio_config_unsupported')
    }
  }

  return { video, audio, reasons }
}

/** Throws the typed error the UI turns into a stated reason. A silent video is allowed to export; a missing video encoder is not. */
export function requireVideo(c: Capability): void {
  if (!c.video) throw new ExportError('not_supported', c.reasons.join(','))
}
