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
