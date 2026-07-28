import { api, deleteData, ensureCsrfCookie, getData, putData } from '@/lib/api/client'
import type { ApiEnvelope } from '@/lib/api/types'

/**
 * Branding Center API layer. Mirrors the backend App\Domains\Branding\BrandingSpec so the UI can validate a
 * file against the SAME rules BEFORE uploading, and render the required sizes / accepted formats without a
 * round-trip. The API NEVER returns a filesystem path — an asset is an opaque id + a download `url` only.
 */

/** Ownership layers, widest fallback first (mirror of BrandingSpec::SCOPES). */
export const BRANDING_SCOPES = ['platform', 'tenant', 'client', 'project', 'report', 'portal', 'email'] as const
export type BrandingScope = (typeof BRANDING_SCOPES)[number]

/** Render slots (mirror of BrandingSpec::KINDS). */
export const BRANDING_KINDS = [
  'primary_horizontal', 'report_logo', 'square_icon', 'favicon', 'email_header', 'client_logo',
] as const
export type BrandingKind = (typeof BRANDING_KINDS)[number]

export const BRANDING_THEMES = ['light', 'dark', 'any'] as const
export type BrandingTheme = (typeof BRANDING_THEMES)[number]

/** Only vector or lossless raster is accepted (mirror of BrandingSpec::ALLOWED_MIME). */
export const BRANDING_ALLOWED_MIME = ['image/svg+xml', 'image/png'] as const
/** Hard ceiling per file — 2 MB (mirror of BrandingSpec::MAX_BYTES). */
export const BRANDING_MAX_BYTES = 2 * 1024 * 1024

export interface KindSpec {
  label: string
  formats: Record<string, string>
  sizes: { w: number; h: number }[]
  themed: boolean
}

/** Full per-kind spec (mirror of BrandingSpec::SPEC). */
export const BRANDING_SPEC: Record<BrandingKind, KindSpec> = {
  primary_horizontal: { label: 'Primary horizontal logo', formats: { svg: 'preferred', png: 'fallback' }, sizes: [{ w: 1200, h: 300 }], themed: true },
  report_logo: { label: 'Report logo', formats: { svg: 'preferred', png: 'fallback' }, sizes: [{ w: 800, h: 240 }], themed: true },
  square_icon: { label: 'Square app icon', formats: { svg: 'preferred', png: 'fallback' }, sizes: [{ w: 512, h: 512 }], themed: true },
  favicon: { label: 'Favicon', formats: { png: 'required', svg: 'optional' }, sizes: [{ w: 32, h: 32 }, { w: 48, h: 48 }, { w: 180, h: 180 }], themed: false },
  email_header: { label: 'Email header', formats: { png: 'preferred', svg: 'fallback' }, sizes: [{ w: 600, h: 150 }], themed: true },
  client_logo: { label: 'Client logo (square + horizontal)', formats: { svg: 'preferred', png: 'fallback' }, sizes: [{ w: 512, h: 512 }, { w: 800, h: 240 }], themed: true },
}

/** Whether a kind ships a distinct light + dark pair (vs. a single theme-agnostic asset). */
export const kindSupportsThemes = (kind: BrandingKind): boolean => BRANDING_SPEC[kind]?.themed ?? false

/** The path-free public shape of a stored brand asset. */
export interface BrandingAsset {
  id: string
  scope: BrandingScope
  scope_id: string | null
  kind: BrandingKind
  theme: BrandingTheme
  mime: string
  width: number | null
  height: number | null
  bytes: number | null
  checksum: string | null
  url: string
  created_at: string | null
}

export interface BrandingSettings {
  scope: BrandingScope
  scope_id: string | null
  colors: Record<string, string> | null
  fonts: Record<string, string> | null
  white_label: boolean
}

/**
 * Client-side validation against the spec — MIME + byte size, exactly what the backend enforces. Returns a
 * small verdict so the UI can block an upload that would only 422 on the server.
 */
export function validateBrandingUpload(kind: BrandingKind, mime: string, bytes: number): { ok: boolean; error?: string } {
  if (!BRANDING_KINDS.includes(kind)) return { ok: false, error: `Unknown branding kind [${kind}].` }
  if (!(BRANDING_ALLOWED_MIME as readonly string[]).includes(mime)) return { ok: false, error: 'Only SVG or PNG brand assets are accepted.' }
  if (bytes <= 0) return { ok: false, error: 'The uploaded file is empty.' }
  if (bytes > BRANDING_MAX_BYTES) return { ok: false, error: 'Brand assets may not exceed 2 MB.' }
  return { ok: true }
}

export interface AssetScopeQuery {
  scope?: BrandingScope
  scopeId?: string | null
}

export async function listBrandingAssets({ scope, scopeId }: AssetScopeQuery = {}): Promise<BrandingAsset[]> {
  const params: Record<string, string> = {}
  if (scope) params.scope = scope
  if (scopeId) params.scope_id = scopeId
  const res = await api.get<ApiEnvelope<BrandingAsset[]>>('/branding/assets', { params })
  return res.data.data ?? []
}

export interface UploadAssetInput {
  scope: BrandingScope
  scopeId?: string | null
  kind: BrandingKind
  theme?: BrandingTheme
  file: File
}

export async function uploadBrandingAsset(input: UploadAssetInput): Promise<BrandingAsset> {
  await ensureCsrfCookie()
  const form = new FormData()
  form.append('scope', input.scope)
  if (input.scopeId) form.append('scope_id', input.scopeId)
  form.append('kind', input.kind)
  form.append('theme', input.theme ?? 'any')
  form.append('file', input.file)
  const res = await api.post<ApiEnvelope<BrandingAsset>>('/branding/assets', form)
  return res.data.data
}

export async function deleteBrandingAsset(id: string): Promise<void> {
  await ensureCsrfCookie()
  await deleteData(`/branding/assets/${encodeURIComponent(id)}`)
}

export function getBrandingSettings({ scope, scopeId }: AssetScopeQuery = {}): Promise<BrandingSettings> {
  const params = new URLSearchParams()
  if (scope) params.set('scope', scope)
  if (scopeId) params.set('scope_id', scopeId)
  const qs = params.toString()
  return getData<BrandingSettings>(`/branding/settings${qs ? `?${qs}` : ''}`)
}

export interface SaveSettingsInput {
  scope: BrandingScope
  scopeId?: string | null
  colors?: Record<string, string> | null
  fonts?: Record<string, string> | null
  white_label?: boolean
}

export async function saveBrandingSettings(input: SaveSettingsInput): Promise<BrandingSettings> {
  await ensureCsrfCookie()
  return putData<BrandingSettings>('/branding/settings', {
    scope: input.scope,
    scope_id: input.scopeId ?? null,
    colors: input.colors ?? null,
    fonts: input.fonts ?? null,
    white_label: input.white_label ?? false,
  })
}
