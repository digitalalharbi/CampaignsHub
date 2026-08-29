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
