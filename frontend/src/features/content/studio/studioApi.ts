import { getData, postData } from '@/lib/api/client'

/** CONTENT-STUDIO-001 batch 8 — the storage API (`ContentStudioController`). The browser renders; the server only stores. */
export interface StudioSessionDto {
  id: string
  project_id: string
  stage: string
  source_item_ids: string[]
  outputs: StudioOutputDto[]
}

export interface StudioOutputDto {
  id: string
  session_id: string
  intake_item_id: string
  kind: 'video' | 'image'
  aspect: string
  family_key: string
  rating: number | null
  readiness: { score: number; checks: unknown[] } | null
}

export interface SaveOutputInput {
  file: Blob
  kind: 'video' | 'image'
  name: string
  familyKey: string
  familyVersion: number
  hook: string
  message: string
  cta: string
  durationMs: number | null
  score: number
  tokens: Record<string, unknown>
  conceptName: string
  providerTarget?: string | null
}

export const openStudioSession = (projectId: string, sourceItemIds: string[] = []) =>
  postData<StudioSessionDto>('/content-studio/sessions', { project_id: projectId, source_item_ids: sourceItemIds })

export const getStudioSession = (id: string) => getData<StudioSessionDto>(`/content-studio/sessions/${id}`)

export function saveStudioOutput(sessionId: string, input: SaveOutputInput) {
  const body = new FormData()
  const ext = input.kind === 'video' ? 'mp4' : 'png'
  body.append('file', input.file, `${input.name}.${ext}`)
  body.append('kind', input.kind)
  body.append('aspect', '9x16')
  body.append('name', input.name)
  body.append('family_key', input.familyKey)
  body.append('family_version', String(input.familyVersion))
  body.append('hook', input.hook)
  body.append('message', input.message)
  body.append('cta', input.cta)
  if (input.durationMs !== null) body.append('duration_ms', String(Math.round(input.durationMs)))
  body.append('has_audio', '0')
  body.append('readiness[score]', String(input.score))
  body.append('readiness[hard_fail]', '0')
  body.append('tokens_source', 'derived')
  body.append('concept_name', input.conceptName)
  if (input.providerTarget) body.append('provider_target', input.providerTarget)
  for (const [k, v] of Object.entries(input.tokens)) {
    if (typeof v === 'string' || typeof v === 'number') body.append(`tokens[${k}]`, String(v))
  }

  return postData<StudioOutputDto>(`/content-studio/sessions/${sessionId}/outputs`, body)
}

export const rateStudioOutput = (id: string, rating: 1 | -1 | null) => postData<StudioOutputDto>(`/content-studio/outputs/${id}/rating`, { rating })
