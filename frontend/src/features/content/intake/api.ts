import { getData, getEnvelope, postData } from '@/lib/api/client'
import { libraryQueryString, type LibraryQuery } from '../api'

/*
 * CONTENT-INTAKE-001 — the quality gate for new content. Mirrors `ContentIntakeController`.
 *
 * A check is `not_measured` when the server could not measure it (a Drive file's bytes are never read, an unreadable
 * header). It is never drawn as a pass. `reason_ar` / `reason_en` are the server's own sentences with the measured
 * values in them; the UI shows them as given.
 */

export const INTAKE_STATUSES = ['draft', 'in_review', 'needs_changes', 'approved', 'rejected', 'live'] as const
export type IntakeStatus = (typeof INTAKE_STATUSES)[number]

export const INTAKE_ACTIONS = ['submit', 'approve', 'reject', 'request_changes', 'reopen', 'mark_live'] as const
export type IntakeAction = (typeof INTAKE_ACTIONS)[number]

export type CheckStatus = 'pass' | 'warn' | 'fail' | 'not_measured'

export interface IntakeCheck {
  key: string
  status: CheckStatus
  params: Record<string, string | number | null>
  reason_ar: string
  reason_en: string
}

export interface IntakeRecommendation {
  key: string
  severity: 'must' | 'should' | 'info'
  fact_ar: string
  fact_en: string
}

export interface IntakeHistory {
  state: 'ok' | 'insufficient' | 'no_target' | 'not_reported'
  peers?: number
  needed?: number
  metric?: string
  fit_percent?: number
  top_quartile_count?: number
  top_quartile_same_format?: number
  window_days?: number
  average?: number | null
  best?: { name: string; value: number; format: string | null }
  format?: string | null
  same_format?: { count: number; average: number | null }
  other_formats?: { count: number; average: number | null }
}

export interface IntakeAnalysis {
  facts: Record<string, string | number | boolean | string[] | null>
  history: IntakeHistory
  recommendations: IntakeRecommendation[]
  coverage: { measured: number; total: number }
}

export const AI_FIELDS = ['description', 'hook', 'cta', 'on_image_text', 'tone', 'brand_fit'] as const

export interface IntakeAi {
  state: 'awaiting_credentials' | 'not_wired' | 'disabled' | 'active'
  fields: Record<(typeof AI_FIELDS)[number], string | null>
}

export interface IntakeEvent {
  id: string
  action: string
  from: string | null
  to: string | null
  actor: string | null
  reason: string | null
  notes: string | null
  item_name: string
  at: string | null
}

export interface IntakeAfterLaunch {
  state: string
  metric?: string
  value?: number
  average?: number | null
  delta_percent?: number | null
  higher_is_better?: boolean
  peers?: number
  rank?: number | null
  ranked?: number
  window_days?: number
}

export interface IntakeItem {
  id: string
  project_id: string
  name: string
  kind: 'image' | 'video' | 'copy' | 'link'
  provider: string | null
  objective: string | null
  placement: string | null
  status: IntakeStatus
  source: 'upload' | 'link' | 'drive'
  uploader_name: string | null
  uploaded_by: number | null
  mime: string | null
  size_bytes: number | null
  width: number | null
  height: number | null
  duration_ms: number | null
  headline: string | null
  cta: string | null
  copy_text: string | null
  destination_url: string | null
  drive_modified_time: string | null
  source_changed_at: string | null
  checks: IntakeCheck[] | null
  check_result: 'pass' | 'warn' | 'fail' | null
  readiness_score: number | null
  analysis: IntakeAnalysis | null
  ai: IntakeAi | null
  decided_at: string | null
  decider_name: string | null
  decision_reason: string | null
  decision_notes: string | null
  has_file: boolean
  can_edit: boolean
  available_actions: IntakeAction[]
  updated_at: string
  events?: IntakeEvent[]
  after_launch?: IntakeAfterLaunch | null
}

export interface IntakeList {
  items: IntakeItem[]
  total: number
  withheld: number
  counts: Partial<Record<IntakeStatus, number>>
  aiState: IntakeAi['state']
  objectives: string[]
  platforms: string[]
  maxUploadKb: number
}

export async function listIntake(params: { project?: string | null; status?: string }): Promise<IntakeList> {
  const q = new URLSearchParams()
  if (params.project) q.set('project', params.project)
  if (params.status) q.set('status', params.status)
  const res = await getEnvelope<IntakeItem[]>(`/content-intake?${q.toString()}`)
  const meta = res.meta as unknown as {
    total: number; withheld: number; counts: Record<string, number>; ai: { state: IntakeAi['state'] }
    objectives: string[]; platforms: string[]; max_upload_kb: number
  }
  return {
    items: res.data ?? [], total: meta.total, withheld: meta.withheld, counts: meta.counts ?? {},
    aiState: meta.ai?.state ?? 'awaiting_credentials', objectives: meta.objectives ?? [], platforms: meta.platforms ?? [],
    maxUploadKb: meta.max_upload_kb,
  }
}

export const getIntake = (id: string) => getData<IntakeItem>(`/content-intake/${id}`)

export interface IntakeInput {
  project_id?: string
  name?: string
  kind?: 'copy' | 'link'
  provider?: string
  objective?: string
  placement?: string
  headline?: string
  cta?: string
  copy_text?: string
  destination_url?: string
  file?: File | null
}

function form(input: IntakeInput): FormData {
  const f = new FormData()
  for (const [k, v] of Object.entries(input)) {
    if (v === undefined || v === null || v === '') continue
    f.append(k, v as string | Blob)
  }
  return f
}

export const createIntake = (input: IntakeInput) => postData<IntakeItem>('/content-intake', form(input))
export const updateIntake = (id: string, input: IntakeInput) => postData<IntakeItem>(`/content-intake/${id}`, form(input))
export const recheckIntake = (id: string) => postData<IntakeItem>(`/content-intake/${id}/recheck`)

export const transitionIntake = (id: string, action: IntakeAction, body: { reason?: string; notes?: string; creative_id?: string } = {}) =>
  postData<IntakeItem>(`/content-intake/${id}/transition`, { action, ...body })

export const intakeFileUrl = (id: string) => `/api/v1/content-intake/${id}/file`

export interface FollowUp {
  awaiting_review: IntakeItem[]
  needs_changes: IntakeItem[]
  rejected: IntakeItem[]
  approved_not_launched: IntakeItem[]
  live_fatigue: Array<{ item: IntakeItem; fatigue: { status: string; reason_ar?: string; reason_en?: string; note_ar?: string; note_en?: string } }>
}

export const getFollowUp = () => getData<FollowUp>('/content-intake/follow-up')

export interface DriveSyncResult {
  state: string
  created: number
  updated: number
  unchanged: number
  skipped: number
  truncated: boolean
  last_synced_at: string | null
  last_error: string | null
}

export const syncDriveLink = (linkId: string) => postData<DriveSyncResult>(`/content-intake/drive/${linkId}/sync`)

/* ── library overview ── */

export interface OverviewWhy {
  id: string
  name: string
  format: string | null
  kind: string | null
  value: number
  average: number | null
  delta_percent: number | null
  spend_share: number | null
}

export interface OverviewCell {
  provider: string
  family: string
  count: number
  fatigued: number
  watch: number
  objectives: string[]
  metric: string | null
  higher_is_better?: boolean
  average: number | null
  previous_average: number | null
  ranked: number
  best: OverviewWhy | null
  worst: OverviewWhy | null
}

export interface ContentOverview {
  matrix: OverviewCell[]
  fatigue: Record<string, number>
  flagged: Array<{
    id: string; name: string; provider: string; status: string; frequency: number | null; ctr: number | null
    signals: Array<{ metric?: string; key?: string; direction: string; change: number | null }>
    reason_ar: string | null; reason_en: string | null
  }>
  read: number
  in_scope: number
  window: { from: string; to: string }
}

export const getContentOverview = (query: LibraryQuery) => getData<ContentOverview>(`/content-overview${libraryQueryString(query)}`)
