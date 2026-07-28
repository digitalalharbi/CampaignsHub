import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import { BrandingCenterPage } from './BrandingCenterPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import type { BrandingAsset } from './api'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return {
    ...actual,
    listBrandingAssets: vi.fn(),
    getBrandingSettings: vi.fn(),
    uploadBrandingAsset: vi.fn(),
    deleteBrandingAsset: vi.fn(),
    saveBrandingSettings: vi.fn(),
  }
})

import { getBrandingSettings, listBrandingAssets } from './api'

function asset(kind: BrandingAsset['kind'], theme: BrandingAsset['theme']): BrandingAsset {
  return {
    id: `${kind}-${theme}`, scope: 'tenant', scope_id: null, kind, theme, mime: 'image/png',
    width: 1200, height: 300, bytes: 2048, checksum: null, url: `https://example.test/${kind}.png`, created_at: null,
  }
}

describe('BrandingCenterPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listBrandingAssets).mockResolvedValue([asset('primary_horizontal', 'light')])
    vi.mocked(getBrandingSettings).mockResolvedValue({ scope: 'tenant', scope_id: null, colors: null, fonts: null, white_label: false })
  })
  afterEach(() => signOut())

  it('blocks the whole page without branding.view', () => {
    signInWith([])
    renderWithProviders(<BrandingCenterPage />)
    expect(screen.getByText(/do not have permission/i)).toBeInTheDocument()
  })

  it('renders the spec (required sizes + accepted formats) and previews an uploaded asset by its URL', async () => {
    signInWith(['branding.view'])
    renderWithProviders(<BrandingCenterPage />)

    // Required sizes from the spec are shown for the primary horizontal logo.
    expect(await screen.findByText(/Required sizes: 1200×300/)).toBeInTheDocument()
    // Accepted formats surfaced from the spec.
    expect(screen.getAllByText(/SVG \(preferred\)/).length).toBeGreaterThan(0)

    // The uploaded asset previews via its download URL — never a filesystem path.
    const img = await screen.findByAltText('Light')
    expect(img).toHaveAttribute('src', 'https://example.test/primary_horizontal.png')
  })

  it('hides upload controls without branding.manage and shows the honest note', async () => {
    signInWith(['branding.view'])
    renderWithProviders(<BrandingCenterPage />)
    await screen.findByText(/Required sizes: 1200×300/)
    expect(screen.queryByText('Upload')).not.toBeInTheDocument()
    expect(screen.getByText(/branding\.manage/)).toBeInTheDocument()
  })

  it('shows upload controls with branding.manage', async () => {
    signInWith(['branding.view', 'branding.manage'])
    renderWithProviders(<BrandingCenterPage />)
    await waitFor(() => expect(screen.getAllByText('Upload').length).toBeGreaterThan(0))
  })
})
