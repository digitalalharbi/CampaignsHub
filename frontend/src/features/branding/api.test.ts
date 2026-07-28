import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/api/client', () => ({
  api: { get: vi.fn(), post: vi.fn() },
  getData: vi.fn(),
  putData: vi.fn(),
  deleteData: vi.fn(),
  ensureCsrfCookie: vi.fn().mockResolvedValue(undefined),
}))

import { api, deleteData, ensureCsrfCookie, getData, putData } from '@/lib/api/client'
import {
  BRANDING_MAX_BYTES, deleteBrandingAsset, getBrandingSettings, kindSupportsThemes, listBrandingAssets,
  saveBrandingSettings, uploadBrandingAsset, validateBrandingUpload,
} from './api'

const mockGet = vi.mocked(api.get)
const mockPost = vi.mocked(api.post)

describe('branding validateBrandingUpload — mirrors BrandingSpec', () => {
  it('rejects an unknown kind', () => {
    // @ts-expect-error deliberately invalid kind
    expect(validateBrandingUpload('nope', 'image/png', 10).ok).toBe(false)
  })
  it('rejects a non-SVG/PNG mime', () => {
    const v = validateBrandingUpload('favicon', 'image/jpeg', 10)
    expect(v.ok).toBe(false)
    expect(v.error).toMatch(/SVG or PNG/)
  })
  it('rejects an empty file and one over 2 MB', () => {
    expect(validateBrandingUpload('favicon', 'image/png', 0).ok).toBe(false)
    expect(validateBrandingUpload('favicon', 'image/png', BRANDING_MAX_BYTES + 1).ok).toBe(false)
  })
  it('accepts a valid PNG under the ceiling', () => {
    expect(validateBrandingUpload('primary_horizontal', 'image/png', 1024)).toEqual({ ok: true })
  })
})

describe('branding kindSupportsThemes', () => {
  it('is true for themed kinds and false for the favicon', () => {
    expect(kindSupportsThemes('primary_horizontal')).toBe(true)
    expect(kindSupportsThemes('favicon')).toBe(false)
  })
})

describe('branding api layer', () => {
  beforeEach(() => vi.clearAllMocks())

  it('lists assets and passes the scope filter through', async () => {
    mockGet.mockResolvedValue({ data: { data: [{ id: 'a1' }] } })
    const out = await listBrandingAssets({ scope: 'tenant', scopeId: 'u-1' })
    expect(api.get).toHaveBeenCalledWith('/branding/assets', { params: { scope: 'tenant', scope_id: 'u-1' } })
    expect(out).toEqual([{ id: 'a1' }])
  })

  it('uploads a file as multipart with the spec fields', async () => {
    mockPost.mockResolvedValue({ data: { data: { id: 'a2' } } })
    const file = new File(['<svg/>'], 'logo.svg', { type: 'image/svg+xml' })
    const out = await uploadBrandingAsset({ scope: 'client', scopeId: 'c-1', kind: 'client_logo', theme: 'dark', file })
    expect(ensureCsrfCookie).toHaveBeenCalled()
    const [url, body] = mockPost.mock.calls[0]
    expect(url).toBe('/branding/assets')
    expect(body).toBeInstanceOf(FormData)
    const form = body as FormData
    expect(form.get('scope')).toBe('client')
    expect(form.get('scope_id')).toBe('c-1')
    expect(form.get('kind')).toBe('client_logo')
    expect(form.get('theme')).toBe('dark')
    expect(form.get('file')).toBeInstanceOf(File)
    expect(out).toEqual({ id: 'a2' })
  })

  it('deletes by encoded id after priming CSRF', async () => {
    await deleteBrandingAsset('a 3')
    expect(ensureCsrfCookie).toHaveBeenCalled()
    expect(deleteData).toHaveBeenCalledWith('/branding/assets/a%203')
  })

  it('reads settings with a query string and saves with a normalized body', async () => {
    vi.mocked(getData).mockResolvedValue({ scope: 'tenant', white_label: false })
    await getBrandingSettings({ scope: 'tenant' })
    expect(getData).toHaveBeenCalledWith('/branding/settings?scope=tenant')

    vi.mocked(putData).mockResolvedValue({ scope: 'tenant', white_label: true })
    await saveBrandingSettings({ scope: 'tenant', white_label: true, colors: { primary: '#fff' } })
    expect(putData).toHaveBeenCalledWith('/branding/settings', {
      scope: 'tenant', scope_id: null, colors: { primary: '#fff' }, fonts: null, white_label: true,
    })
  })
})
