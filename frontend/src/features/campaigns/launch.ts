import type { ApiEnvelope } from '@/lib/api/types'
import type { UnifiedCampaign } from './types'

/**
 * LAUNCH-SUCCESS-001 — the client's side of "a celebration describes something that happened".
 *
 * `readLaunchOutcome` is the ONLY way the success experience can come into existence. It takes the
 * activate response and returns an outcome or `null`; there is no constructor a screen can call with
 * a campaign it happens to have lying around. Two things have to be true before it returns anything:
 *
 *  - the server sent `meta.launch` — only `POST …/activate` does, so a pause, a rename or a draft
 *    save has nothing to offer here;
 *  - the campaign in `data` actually came back `active` — a 200 that left the campaign in some other
 *    state is not a launch, whatever the meta says.
 *
 * Everything the modal shows is read off this object. Nothing is inferred, defaulted or timestamped
 * locally, because the moment a field can be invented, the screen can be right about a launch that
 * never happened.
 */
export interface LaunchPlatform {
  provider: string
  external_id: string
  name: string
  /** The platform's own normalised status — carried so a partial launch can say why. */
  status: string
  live: boolean
}

export interface LaunchOutcome {
  campaign_id: string
  project_id: string
  name: string
  objective: string
  total_budget: number | null
  budget_currency: string
  /** ISO-8601, from the server's clock. Never generated in the browser. */
  activated_at: string | null
  platforms: LaunchPlatform[]
  platforms_live: number
  platforms_total: number
  outcome: 'launched' | 'partial'
}

export function readLaunchOutcome(envelope: ApiEnvelope<UnifiedCampaign>): LaunchOutcome | null {
  // A success screen for a campaign that is not active would be a lie told in the product's own voice.
  if (envelope.data?.status !== 'active') return null

  const raw = envelope.meta?.launch as Partial<LaunchOutcome> | undefined
  if (raw === undefined || raw === null) return null
  if (typeof raw.campaign_id !== 'string' || typeof raw.project_id !== 'string') return null
  if (raw.outcome !== 'launched' && raw.outcome !== 'partial') return null

  const platforms = Array.isArray(raw.platforms) ? raw.platforms : []

  return {
    campaign_id: raw.campaign_id,
    project_id: raw.project_id,
    name: typeof raw.name === 'string' ? raw.name : envelope.data.name,
    objective: typeof raw.objective === 'string' ? raw.objective : envelope.data.objective,
    total_budget: typeof raw.total_budget === 'number' ? raw.total_budget : null,
    budget_currency: typeof raw.budget_currency === 'string' ? raw.budget_currency : envelope.data.budget_currency,
    activated_at: typeof raw.activated_at === 'string' ? raw.activated_at : null,
    platforms,
    platforms_live: typeof raw.platforms_live === 'number' ? raw.platforms_live : platforms.filter((p) => p.live).length,
    platforms_total: typeof raw.platforms_total === 'number' ? raw.platforms_total : platforms.length,
    outcome: raw.outcome,
  }
}
