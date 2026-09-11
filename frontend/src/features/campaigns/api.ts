import { api, ensureCsrfCookie, deleteData, getData, postData } from '@/lib/api/client'
import type { ApiEnvelope } from '@/lib/api/types'
import type { ExternalCampaign, UnifiedCampaign } from './types'

const base = (projectId: string) => `/projects/${projectId}/campaigns`

export interface CampaignListParams {
  status?: string
  objective?: string
  search?: string
  /**
   * The column the reader chose, ordered SERVER-side over the whole filtered set.
   *
   * Sorting the page the browser holds would answer «the dearest of the most relevant twenty-five»
   * while looking like an answer about the project — the silent truncation this list already moved
   * its relevance ranking to the server to avoid. An unrecognised value falls back to relevance
   * there rather than reaching a column name from a URL.
   */
  sort?: string
  dir?: 'asc' | 'desc'
}

export interface CampaignPage {
  campaigns: UnifiedCampaign[]
  total: number
  page: number
  lastPage: number
  /** Per status, over the whole FILTERED project — never over the rows that fitted on this page. */
  counts: Record<string, number>
}

/**
 * CAMPAIGNS-LEDGER-001 — a page, ordered by relevance on the server, with the project's own counts.
 *
 * The endpoint handed over every campaign in the project and this page derived its status counts,
 * its donut and its «what is running» ordering from the array it held. Campaigns are the one list
 * here that grows without a ceiling.
 *
 * The ordering had to move with the page: cutting by `created_at` and re-ordering twenty-five rows
 * in the browser would make «the campaigns that need you» mean «the most relevant of the twenty-five
 * newest». The server ranks the whole filtered set first — see `CampaignRelevance`, which sorts rows
 * the canonical aggregator produced rather than aggregating anything itself.
 */
export function listCampaigns(
  projectId: string,
  params: CampaignListParams & { page?: number; per_page?: number; from?: string; to?: string } = {},
): Promise<CampaignPage> {
  return api
    .get<ApiEnvelope<UnifiedCampaign[]>>(base(projectId), { params: { per_page: 25, ...params } })
    .then((r) => {
      const campaigns = r.data.data ?? []
      const meta = r.data.meta as
        | { total?: number; current_page?: number; last_page?: number; counts?: Record<string, number> }
        | undefined

      return {
        campaigns,
        total: Number(meta?.total ?? campaigns.length),
        page: Number(meta?.current_page ?? 1),
        lastPage: Number(meta?.last_page ?? 1),
        /*
         * Counting the rows we hold is the honest fallback for an older server that sends no counts:
         * it is what the page did before, and it can only under-count a bounded list rather than
         * invent campaigns that do not exist.
         */
        counts: meta?.counts ?? campaigns.reduce<Record<string, number>>((acc, c) => {
          acc[c.status] = (acc[c.status] ?? 0) + 1

          return acc
        }, {}),
      }
    })
}

export function getCampaign(projectId: string, campaignId: string): Promise<UnifiedCampaign> {
  return getData<UnifiedCampaign>(`${base(projectId)}/${campaignId}`)
}

export interface CampaignInput {
  name: string
  client_display_name?: string | null
  objective?: string
  status?: string
  stage?: string | null
  performance_label?: string | null
  priority?: string | null
  total_budget?: number | null
  budget_currency?: string
  starts_on?: string | null
  ends_on?: string | null
  audience?: string | null
  owner_id?: number | null
  attribution_model?: string | null
  attribution_window?: string | null
  /** Derived from the selected objective's metadata (primary/secondary KPIs, funnel, report template). */
  target_kpi?: Record<string, unknown> | null
  // Additive taxonomy-driven multi-selects (nullable jsonb columns; the backend validates these keys).
  platforms?: string[]
  regions?: string[]
  audiences?: string[]
  conversion_events?: string[]
  creative_types?: string[]
  tags?: string[]
}

export async function createCampaign(projectId: string, input: CampaignInput): Promise<UnifiedCampaign> {
  await ensureCsrfCookie()
  return postData<UnifiedCampaign>(base(projectId), input)
}

export async function updateCampaign(
  projectId: string,
  campaignId: string,
  input: Partial<CampaignInput>,
): Promise<UnifiedCampaign> {
  await ensureCsrfCookie()
  const res = await api.patch<ApiEnvelope<UnifiedCampaign>>(`${base(projectId)}/${campaignId}`, input)
  return res.data.data
}

export async function campaignAction(
  projectId: string,
  campaignId: string,
  action: 'pause' | 'activate',
): Promise<UnifiedCampaign> {
  await ensureCsrfCookie()
  return postData<UnifiedCampaign>(`${base(projectId)}/${campaignId}/${action}`)
}

export async function archiveCampaign(projectId: string, campaignId: string): Promise<null> {
  await ensureCsrfCookie()
  return deleteData<null>(`${base(projectId)}/${campaignId}`)
}

// ---- External campaigns + linking ------------------------------------------------------------

export interface ExternalCampaignListParams {
  provider?: string
  status?: string
  linked?: boolean
  search?: string
}

export function listExternalCampaigns(
  projectId: string,
  params: ExternalCampaignListParams = {},
): Promise<ExternalCampaign[]> {
  return api
    .get<ApiEnvelope<ExternalCampaign[]>>(`/projects/${projectId}/external-campaigns`, { params })
    .then((r) => r.data.data)
}

export function listLinkedExternal(projectId: string, campaignId: string): Promise<ExternalCampaign[]> {
  return getData<ExternalCampaign[]>(`${base(projectId)}/${campaignId}/external`)
}

export function listLinkSuggestions(projectId: string, campaignId: string): Promise<ExternalCampaign[]> {
  return getData<ExternalCampaign[]>(`${base(projectId)}/${campaignId}/suggestions`)
}

/**
 * Link an external campaign. On 409 (already linked to a different unified campaign) the raw error
 * bubbles up so the caller can read meta.requires_confirmation and re-send with confirm=true.
 */
export async function linkExternal(
  projectId: string,
  campaignId: string,
  externalCampaignId: string,
  confirm = false,
): Promise<ExternalCampaign> {
  await ensureCsrfCookie()
  const res = await api.post<ApiEnvelope<ExternalCampaign>>(`${base(projectId)}/${campaignId}/external`, {
    external_campaign_id: externalCampaignId,
    confirm,
  })
  return res.data.data
}

export async function unlinkExternal(
  projectId: string,
  campaignId: string,
  externalCampaignId: string,
): Promise<null> {
  await ensureCsrfCookie()
  return deleteData<null>(`${base(projectId)}/${campaignId}/external/${externalCampaignId}`)
}
