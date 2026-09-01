export interface Lead {
  id: string
  name: string
  email: string | null
  phone: string | null
  source: string
  status: string
  estimated_value: number
  currency: string
  notes: string | null
  tags: string[]
  company_id: string | null
  is_converted: boolean
  converted_opportunity_id: string | null
  converted_at: string | null
  created_at: string | null
  /*
   * LEAD-DEDUP-001 — the duplicate RELATIONSHIP. Never a deletion, never a hidden row.
   *
   * `duplicate_reason` is separate from the link because `ambiguous` is not a kind of duplicate: it
   * is an identity whose email says one person and whose phone says another, deliberately linked to
   * NEITHER. A row can carry a reason and no canonical, and that combination is the product
   * declining to guess rather than a missing value.
   */
  canonical_lead_id?: string | null
  duplicate_reason?: string | null
  attribution?: LeadAttribution
  /** How many later arrivals this lead absorbed. Absent unless the list asked for it. */
  duplicate_count?: number
}

export interface Opportunity {
  id: string
  name: string
  amount: number
  currency: string
  probability: number
  status: string
  stage_id: string
  stage?: { id: string; name: string }
  lead_id: string | null
  expected_close_date: string | null
  created_at: string | null
}

export interface Pagination {
  total: number
  per_page: number
  current_page: number
  last_page: number
}

export const LEAD_STATUSES = [
  'new',
  'contacted',
  'qualified',
  'proposal_sent',
  'negotiation',
  'won',
  'lost',
] as const

export const LEAD_SOURCES = [
  'website',
  'referral',
  'paid',
  'whatsapp',
  'email',
  'phone',
  'event',
  'exhibition',
  'manual',
  'api',
  'webhook',
] as const

/**
 * LEAD-SOURCE-ATTRIBUTION-001 — where the lead came from, rung by rung.
 *
 * The four states are the whole point. A screen that renders «platform does not offer this», «this
 * lead lost it», and «nothing paid for this lead» as the same dash has answered none of them, and
 * the client cannot tell a platform limit from a broken sync.
 */
export type AttributionState = 'named' | 'not_offered' | 'missing' | 'no_platform'

export interface AttributionRung {
  rung: 'creative' | 'ad' | 'adset' | 'campaign'
  state: AttributionState
  id: string | null
  name: string | null
  /** Present only for `not_offered`: why this platform has nothing to say about this rung. */
  reason: string | null
  /**
   * The same sentence in English.
   *
   * Both are sent rather than one resolved server-side, because the reader can switch language in
   * the browser and a chain already on screen must follow — and because a reason that exists in one
   * language only degrades to the unexplained dash this feature exists to remove.
   */
  reason_en: string | null
}

export interface LeadAttribution {
  route: 'native_form' | 'website_form' | 'manual' | 'imported'
  route_label: string
  route_label_en: string
  platform: {
    state: 'named' | 'unrecognised' | 'no_platform'
    provider: string | null
    label: string | null
    label_en: string | null
  }
  rungs: AttributionRung[]
  /** «Nothing is missing that COULD have been here» — not «all four rungs are named». */
  complete: boolean
  web: Record<string, string>
}
