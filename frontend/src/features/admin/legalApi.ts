import { getData, putData } from '@/lib/api/client'

/**
 * LEGAL-001 — the platform operator's own legal identity, and the policy versions in force.
 *
 * Every field but the contact address is nullable, and that is the design rather than an oversight:
 * a legal name, a registration number and a jurisdiction are business facts the operator supplies.
 * A plausible default for any of them would end up printed on a published privacy policy and relied
 * on by a reader, so unset stays visibly unset and the editor names what is still missing.
 */
export interface PlatformOperator {
  published: boolean
  legal_name_ar: string | null
  legal_name_en: string | null
  trading_name: string | null
  registration_number: string | null
  tax_number: string | null
  jurisdiction: string | null
  address_ar: string | null
  address_en: string | null
  /** The one address that always resolves — the others fall back to it. */
  contact_email: string
  support_email: string
  security_email: string
  privacy_email: string
  phone: string | null
  dpo_name: string | null
}

export interface PlatformSettingsPayload extends PlatformOperator {
  dpo_email: string | null
  updated_at: string | null
  /** Field groups still unpublished, named so «is my policy publishable» is answerable on screen. */
  missing: string[]
}

/** A published document, its version and the date that version took effect. */
export interface PolicyDocument {
  slug: string
  version: string
  effective: string
  /** True when a user must accept it to register or to pay. */
  binding: boolean
}

export interface LegalMeta {
  operator: PlatformOperator
  documents: PolicyDocument[]
  binding: string[]
}

/** Public — no session. The OAuth reviewers of eight platforms fetch this domain unauthenticated. */
export const getLegalMeta = () => getData<LegalMeta>('/legal')

export const getPlatformSettings = () => getData<PlatformSettingsPayload>('/admin/settings/platform')

export const savePlatformSettings = (body: Record<string, string | null>) =>
  putData<PlatformSettingsPayload>('/admin/settings/platform', body)
