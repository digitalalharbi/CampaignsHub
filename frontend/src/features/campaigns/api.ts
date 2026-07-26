import { api, ensureCsrfCookie, deleteData, getData, postData } from '@/lib/api/client'
import type { ApiEnvelope } from '@/lib/api/types'
import type { ExternalCampaign, UnifiedCampaign } from './types'

const base = (projectId: string) => `/projects/${projectId}/campaigns`

export interface CampaignListParams {
  status?: string
  objective?: string
  search?: string
}

export function listCampaigns(projectId: string, params: CampaignListParams = {}): Promise<UnifiedCampaign[]> {
  return api
    .get<ApiEnvelope<UnifiedCampaign[]>>(base(projectId), { params })
    .then((r) => r.data.data)
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
