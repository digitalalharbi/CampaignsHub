import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { BrandingCenterPage } from './BrandingCenterPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', async (importOriginal) => ({ ...(await importOriginal<typeof import('./api')>()), listBrandingAssets: vi.fn(), getBrandingSettings: vi.fn() }))
vi.mock('@/features/clients/api', async (orig) => ({ ...(await orig<typeof import('@/features/clients/api')>()), listClients: vi.fn() }))
vi.mock('@/features/projects/api', async (orig) => ({ ...(await orig<typeof import('@/features/projects/api')>()), listProjects: vi.fn() }))
vi.mock('@/features/reports/api', async (orig) => ({ ...(await orig<typeof import('@/features/reports/api')>()), listReports: vi.fn() }))

import { getBrandingSettings, listBrandingAssets } from './api'
import { listClients } from '@/features/clients/api'
import { listProjects } from '@/features/projects/api'
import { listReports } from '@/features/reports/api'

/**
 * BRANDING-TARGET-PICKER-001 — a client's, project's or report's brand is chosen from a list; nothing asks for a UUID, and nothing is
 * shown or saved against a target until one is chosen.
 */
describe('Branding target picker', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['branding.view', 'branding.manage'])
    vi.mocked(listBrandingAssets).mockResolvedValue([])
    vi.mocked(getBrandingSettings).mockResolvedValue({ scope: 'client', scope_id: 'c1', colors: null, fonts: null, white_label: false } as never)
    vi.mocked(listClients).mockResolvedValue({ data: [{ id: 'c1', name: 'Razzah' }, { id: 'c2', name: 'Henka' }], meta: {} } as never)
    vi.mocked(listProjects).mockResolvedValue([{ id: 'p1', name: 'Store' }] as never)
    vi.mocked(listReports).mockResolvedValue({ reports: [{ id: 'r1', name: 'Weekly' }], summary: {} } as never)
  })
  afterEach(() => signOut())

  it('asks for no UUID and lists the clients to choose from; assets load only after a choice', async () => {
    renderWithProviders(<BrandingCenterPage />, { locale: 'en' })
    expect(screen.queryByPlaceholderText('—')).toBeNull()
    await waitFor(() => expect(listBrandingAssets).toHaveBeenCalled())
    vi.mocked(listBrandingAssets).mockClear()

    fireEvent.change(screen.getByTestId('branding-scope'), { target: { value: 'client' } })
    expect(await screen.findByTestId('branding-pick-first')).toBeInTheDocument()
    expect(listBrandingAssets).not.toHaveBeenCalled()

    await screen.findByRole('option', { name: 'Razzah' })
    fireEvent.change(screen.getByTestId('branding-target'), { target: { value: 'c1' } })
    await waitFor(() => expect(listBrandingAssets).toHaveBeenCalledWith({ scope: 'client', scopeId: 'c1' }))
    expect(screen.queryByTestId('branding-pick-first')).toBeNull()
  })

  it('a report brand is chosen through its project, and changing the scope clears the target', async () => {
    renderWithProviders(<BrandingCenterPage />, { locale: 'en' })
    fireEvent.change(screen.getByTestId('branding-scope'), { target: { value: 'report' } })

    await screen.findByRole('option', { name: 'Store' })
    expect((screen.getByTestId('branding-target') as HTMLSelectElement).disabled).toBe(true)
    fireEvent.change(screen.getByTestId('branding-report-project'), { target: { value: 'p1' } })
    await screen.findByRole('option', { name: 'Weekly' })
    fireEvent.change(screen.getByTestId('branding-target'), { target: { value: 'r1' } })
    await waitFor(() => expect(listBrandingAssets).toHaveBeenCalledWith({ scope: 'report', scopeId: 'r1' }))

    fireEvent.change(screen.getByTestId('branding-scope'), { target: { value: 'tenant' } })
    await waitFor(() => expect(listBrandingAssets).toHaveBeenLastCalledWith({ scope: 'tenant', scopeId: null }))
  })
})
