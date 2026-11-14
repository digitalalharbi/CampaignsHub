import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { RolesPanel } from './RolesPanel'
import type { RolesData } from '../api'
import { renderWithProviders } from '@/test/utils'

const save = vi.fn()
const reset = vi.fn()
let data: RolesData

vi.mock('../api', () => ({
  useRoles: () => ({ data, isLoading: false, isError: false }),
  useRoleActions: () => ({
    save: { mutate: save, isPending: false, isError: false },
    reset: { mutate: reset, isPending: false, isError: false },
  }),
}))

/** TEAM-ROLE-PRESETS-001 — ready-made roles, edited with boxes: which page shows, which action is allowed. */
describe('RolesPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    data = {
      sections: [
        { key: 'campaigns', ar: 'الحملات', en: 'Campaigns', page: 'campaigns.view', actions: [{ key: 'campaigns.launch', ar: 'شغّل', en: 'Launch' }] },
        { key: 'clients', ar: 'العملاء', en: 'Clients', page: 'clients.view', actions: [{ key: 'clients.view_all', ar: 'كل العملاء', en: 'Every client' }] },
      ],
      roles: [
        { slug: 'tenant-owner', name_ar: 'مدير المساحة', name_en: 'Workspace manager', locked: true, permissions: [], members: 1 },
        { slug: 'project-manager', name_ar: 'مدير مشروع', name_en: 'Project manager', locked: false, permissions: ['campaigns.view', 'campaigns.launch', 'clients.view'], members: 2 },
      ],
      grantable: ['campaigns.view', 'campaigns.launch', 'clients.view'],
    }
  })

  it('opens on the project manager with the Owner names on the chips', () => {
    renderWithProviders(<RolesPanel />, { locale: 'ar' })
    expect(screen.getByTestId('role-chip-tenant-owner')).toHaveTextContent('مدير المساحة')
    expect(screen.getByTestId('role-chip-project-manager')).toHaveAttribute('aria-selected', 'true')
    expect(screen.getByTestId('perm-campaigns.view')).toBeChecked()
  })

  it('shows the workspace manager as locked, with no boxes', () => {
    renderWithProviders(<RolesPanel />, { locale: 'ar' })
    fireEvent.click(screen.getByTestId('role-chip-tenant-owner'))
    expect(screen.getByTestId('role-locked')).toBeInTheDocument()
    expect(screen.queryByTestId('perm-campaigns.view')).not.toBeInTheDocument()
  })

  it('hiding a page takes its actions with it, and the save sends exactly the ticked boxes', () => {
    renderWithProviders(<RolesPanel />, { locale: 'ar' })
    expect(screen.getByTestId('role-save')).toBeDisabled()

    fireEvent.click(screen.getByTestId('perm-campaigns.view'))
    expect(screen.getByTestId('perm-campaigns.launch')).not.toBeChecked()
    expect(screen.getByTestId('perm-campaigns.launch')).toBeDisabled()

    fireEvent.click(screen.getByTestId('role-save'))
    expect(save).toHaveBeenCalledWith({ slug: 'project-manager', permissions: ['clients.view'] }, expect.anything())
  })

  it('never offers a box the reader does not hold', () => {
    renderWithProviders(<RolesPanel />, { locale: 'ar' })
    expect(screen.getByTestId('perm-clients.view_all')).toBeDisabled()
  })
})
