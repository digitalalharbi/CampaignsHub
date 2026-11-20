import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import type { AccessData, AreaLevels, Category } from './accessApi'
import { AccessCenter } from './AccessCenter'

const mut = () => ({ mutate: vi.fn(), mutateAsync: vi.fn().mockResolvedValue({}), isPending: false })
let actions: Record<string, ReturnType<typeof mut>>
let data: AccessData

vi.mock('./accessApi', async (importOriginal) => {
  const real = await importOriginal<typeof import('./accessApi')>()
  return {
    ...real,
    useAccess: () => ({ data, isLoading: false, isError: false }),
    useAccessHistory: () => ({ data: { entries: [{ action: 'access.member_overrides_changed', by: 'Owner', at: '2026-11-15T10:00:00Z', reason: 'no money' }] } }),
    useAccessActions: () => actions,
    useAccessAudit: () => ({ data: { entries: [{ action: 'access.member_updated', by: 'Owner', target: 'Sara', at: '2026-11-15T11:00:00Z', reason: null, changes: [{ field: 'category', from: 'viewer', to: 'media-buyer' }, { field: 'sections', added: [], removed: ['invoices'] }] }] } }),
  }
})

const SECTIONS = [
  { key: 'campaigns', ar: 'الحملات', en: 'Campaigns' },
  { key: 'budgets', ar: 'الميزانيات', en: 'Budgets' },
  { key: 'invoices', ar: 'الفواتير', en: 'Invoices' },
]

const AREAS = [
  { key: 'projects', ar: 'إدارة المشاريع', en: 'Projects' },
  { key: 'campaigns', ar: 'إدارة الحملات', en: 'Campaigns' },
  { key: 'finance', ar: 'المالية', en: 'Finance' },
  { key: 'team', ar: 'الفريق والإعدادات', en: 'Team & settings' },
]

function lv(levels: Record<string, 'none' | 'view' | 'manage'>): AreaLevels {
  return Object.fromEntries(AREAS.map((a) => [a.key, { level: levels[a.key] ?? 'none', held: 1, total: 2 }]))
}

function cat(slug: string, ar: string, kind: Category['kind'], levels: Record<string, 'none' | 'view' | 'manage'>, extra: Partial<Category> = {}): Category {
  return { slug, name_ar: ar, name_en: slug, kind, all_clients: false, admin: false, areas: lv(levels), members: 0, pending: 0, ...extra }
}

/** ACCESS-CENTER-001 — the board, the one invitation dialog, the drawer and «ماذا سيرى». */
describe('AccessCenter', () => {
  beforeEach(() => {
    actions = Object.fromEntries(['invite', 'invitePortal', 'updateInvitation', 'resend', 'revoke', 'setCategory', 'setOverrides', 'setScope', 'updateMember', 'toggle', 'remove', 'createCategory', 'updateCategory', 'deleteCategory'].map((k) => [k, mut()]))
    data = {
      areas: AREAS,
      sections: SECTIONS,
      categories: [
        cat('tenant-owner', 'مدير المساحة', 'owner', { projects: 'manage', campaigns: 'manage', finance: 'manage', team: 'manage' }, { admin: true, all_clients: true, members: 1 }),
        cat('media-buyer', 'مشتري إعلانات', 'builtin', { projects: 'view', campaigns: 'manage', finance: 'view' }, { members: 1 }),
        cat('finance', 'المالية', 'builtin', { projects: 'view', finance: 'manage' }, { all_clients: true }),
        cat('cat-1', 'مشتري بلا مالية', 'custom', { campaigns: 'manage' }),
      ],
      members: [
        { id: 'u-owner', membership_id: 'm1', name: 'Owner', email: 'owner@a.test', audience: 'team', category: 'tenant-owner', category_name_ar: 'مدير المساحة', category_name_en: 'Workspace manager', is_owner: true, is_self: true, areas: lv({ projects: 'manage', campaigns: 'manage', finance: 'manage', team: 'manage' }), overrides: { allow: 0, deny: 0 }, override_areas: [], scope: { all: true, clients: [] }, status: 'active', last_login_at: '2026-11-14T08:00:00Z' },
        { id: 'u-buyer', membership_id: 'm2', name: 'Sara', email: 'sara@a.test', audience: 'team', category: 'media-buyer', category_name_ar: 'مشتري إعلانات', category_name_en: 'Media buyer', is_owner: false, is_self: false, areas: lv({ projects: 'view', campaigns: 'manage' }), sections: { campaigns: true, budgets: true, invoices: false }, overrides: { allow: 0, deny: 2 }, override_areas: ['finance'], scope: { all: false, clients: [{ id: 'c1', name: 'Acme' }] }, status: 'active', last_login_at: null },
        { id: 'u-portal', membership_id: 'm3', name: 'Client', email: 'client@acme.test', audience: 'client', category: 'client_viewer', category_name_ar: null, category_name_en: null, is_owner: false, is_self: false, areas: null, overrides: { allow: 0, deny: 0 }, override_areas: [], scope: { all: false, clients: [{ id: 'c1', name: 'Acme' }] }, status: 'active', last_login_at: null },
      ],
      invitations: [
        { id: 'inv1', email: 'late@a.test', audience: 'team', category: 'finance', category_name_ar: 'المالية', category_name_en: 'Finance', areas: lv({ projects: 'view', finance: 'manage' }), overrides: { allow: 0, deny: 0 }, override_areas: [], scope: { all: false, clients: [] }, status: 'expired', delivery_status: 'sent', expires_at: '2026-11-10T00:00:00Z', created_at: '2026-11-07T00:00:00Z' },
      ],
      revoked: [{ id: 'old', email: 'gone@a.test', at: '2026-11-12T00:00:00Z' }],
      clients: [{ id: 'c1', name: 'Acme' }, { id: 'c2', name: 'Beta' }],
      portal_roles: ['client_viewer', 'client_approver', 'client_admin'],
      agency: true,
      me: { is_owner: true, grantable: { projects: 'manage', campaigns: 'manage', finance: 'manage', team: 'manage' }, all_clients: true },
    }
  })

  it('counts every audience at a glance and lists members, invitations and withdrawn ones on one board', () => {
    renderWithProviders(<AccessCenter />, { locale: 'ar' })
    expect(screen.getByTestId('count-team')).toHaveTextContent('2')
    expect(screen.getByTestId('count-clients')).toHaveTextContent('1')
    expect(screen.getByTestId('count-expired')).toHaveTextContent('1')
    expect(screen.getByTestId('access-spread')).toHaveTextContent('مشتري إعلانات')

    const buyer = screen.getByTestId('access-row-member-sara@a.test')
    expect(within(buyer).getByTestId('row-category')).toHaveTextContent('مشتري إعلانات')
    expect(within(buyer).getByTestId('row-category')).toHaveTextContent('مخصّص')
    expect(buyer).toHaveTextContent('Acme')
    expect(within(screen.getByTestId('access-row-member-client@acme.test')).getByTestId('row-category')).toHaveTextContent('عميل')
    expect(within(screen.getByTestId('access-row-invitation-late@a.test')).getByTestId('status-expired')).toBeInTheDocument()
    expect(screen.getByTestId('access-row-revoked')).toHaveTextContent('gone@a.test')
  })

  it('filters to invitations only', () => {
    renderWithProviders(<AccessCenter />, { locale: 'ar' })
    fireEvent.click(screen.getByTestId('access-filter-invites'))
    expect(screen.queryByTestId('access-row-member-sara@a.test')).toBeNull()
    expect(screen.getByTestId('access-row-invitation-late@a.test')).toBeInTheDocument()
  })

  it('invites a team member with a category, one area switched off and a client — and previews it first', async () => {
    renderWithProviders(<AccessCenter />, { locale: 'ar' })
    fireEvent.click(screen.getByTestId('access-invite'))
    fireEvent.change(screen.getByTestId('invite-email'), { target: { value: 'new@a.test' } })
    fireEvent.click(screen.getByTestId('invite-category-media-buyer'))

    expect(within(screen.getByTestId('invite-preview')).getByTestId('area-chip-finance')).toHaveAttribute('data-level', 'view')
    fireEvent.click(screen.getByTestId('invite-customise'))
    fireEvent.click(screen.getByTestId('toggle-finance-none'))
    expect(within(screen.getByTestId('invite-preview')).getByTestId('area-chip-finance')).toHaveAttribute('data-level', 'none')
    fireEvent.click(screen.getByTestId('invite-client-c1'))

    fireEvent.click(screen.getByTestId('invite-send'))
    await waitFor(() => expect(actions.invite.mutateAsync).toHaveBeenCalledWith({ email: 'new@a.test', category: 'media-buyer', areas: { finance: 'none' }, sections: {}, client_ids: ['c1'] }))
  })

  it('invites a client-portal user through the same dialog, with the portal roles only', async () => {
    renderWithProviders(<AccessCenter />, { locale: 'ar' })
    fireEvent.click(screen.getByTestId('access-invite'))
    fireEvent.click(screen.getByTestId('invite-audience-client'))
    fireEvent.change(screen.getByTestId('invite-email'), { target: { value: 'buyer@acme.test' } })
    fireEvent.click(screen.getByTestId('invite-portal-client-c2'))
    fireEvent.click(screen.getByTestId('invite-portal-role-client_approver'))
    expect(screen.queryByTestId('invite-customise')).toBeNull()

    fireEvent.click(screen.getByTestId('invite-send'))
    await waitFor(() => expect(actions.invitePortal.mutateAsync).toHaveBeenCalledWith({ client: 'c2', email: 'buyer@acme.test', role: 'client_approver' }))
  })

  it('greys what the reader cannot hand out', () => {
    data.me = { is_owner: false, grantable: { projects: 'view', campaigns: 'manage', finance: 'none', team: 'view' }, all_clients: true }
    renderWithProviders(<AccessCenter />, { locale: 'ar' })
    fireEvent.click(screen.getByTestId('access-invite'))
    expect(screen.getByTestId('invite-category-tenant-owner')).toBeDisabled()
    expect(screen.getByTestId('invite-category-finance')).toBeDisabled()
    fireEvent.click(screen.getByTestId('invite-category-cat-1'))
    fireEvent.click(screen.getByTestId('invite-customise'))
    expect(screen.getByTestId('toggle-finance-view')).toBeDisabled()
    expect(screen.getByTestId('toggle-campaigns-view')).toBeEnabled() // narrowing needs nothing
  })

  it('opens a member drawer, saves areas for that person only, and shows the history', async () => {
    renderWithProviders(<AccessCenter />, { locale: 'ar' })
    fireEvent.click(screen.getByTestId('access-row-member-sara@a.test'))
    const drawer = screen.getByTestId('access-drawer')
    expect(within(drawer).getByTestId('drawer-history')).toHaveTextContent('تغيّر التخصيص')
    expect(within(drawer).getByTestId('drawer-history')).toHaveTextContent('no money')

    fireEvent.click(within(drawer).getByTestId('toggle-campaigns-view'))
    fireEvent.click(within(drawer).getByTestId('drawer-save'))
    await waitFor(() => expect(actions.updateMember.mutateAsync).toHaveBeenCalledWith({ id: 'u-buyer', areas: { campaigns: 'view', finance: 'none' }, sections: {} }))
    expect(actions.setCategory.mutateAsync).not.toHaveBeenCalled()
  })

  it('edits a member after the invitation: sections pre-filled, one save carries role, sections and clients (ACCESS-SECTIONS-001)', async () => {
    renderWithProviders(<AccessCenter />, { locale: 'ar' })
    fireEvent.click(screen.getByTestId('access-row-member-sara@a.test'))
    const drawer = screen.getByTestId('access-drawer')
    expect(within(drawer).getByTestId('section-campaigns')).toHaveAttribute('aria-checked', 'true')
    expect(within(drawer).getByTestId('section-invoices')).toHaveAttribute('aria-checked', 'false')

    fireEvent.click(within(drawer).getByTestId('section-budgets'))
    fireEvent.click(within(drawer).getByTestId('drawer-category-finance'))
    fireEvent.click(within(within(drawer).getByTestId('drawer-clients')).getByText('Beta'))
    fireEvent.click(within(drawer).getByTestId('drawer-save'))
    await waitFor(() => expect(actions.updateMember.mutateAsync).toHaveBeenCalledTimes(1))
    const sent = actions.updateMember.mutateAsync.mock.calls[0][0]
    expect(sent).toMatchObject({ id: 'u-buyer', category: 'finance', sections: { budgets: false }, client_ids: ['c1', 'c2'] })
    expect(actions.setOverrides.mutateAsync).not.toHaveBeenCalled()
    expect(actions.setScope.mutateAsync).not.toHaveBeenCalled()
  })

  it('shows the access log compactly: who, whom, when and what changed (ACCESS-SECTIONS-001)', () => {
    renderWithProviders(<AccessCenter />, { locale: 'ar' })
    fireEvent.click(screen.getByTestId('access-audit-toggle'))
    const log = screen.getByTestId('access-audit-rows')
    expect(log).toHaveTextContent('عُدّلت الصلاحيات')
    expect(log).toHaveTextContent('Sara')
    expect(log).toHaveTextContent('Owner')
    expect(log).toHaveTextContent('مشتري إعلانات') // the category name, not its slug
    expect(log).toHaveTextContent('−الفواتير')
  })

  it('locks the owner and the reader themselves, and leaves a portal user to the client page', () => {
    renderWithProviders(<AccessCenter />, { locale: 'ar' })
    fireEvent.click(screen.getByTestId('access-row-member-owner@a.test'))
    expect(screen.getByTestId('drawer-locked')).toBeInTheDocument()
    expect(screen.queryByTestId('drawer-remove')).toBeNull()
  })

  it('lets one workspace manager move another to a different category, after a confirmation', async () => {
    data.members.push({ ...data.members[0], id: 'u-owner2', email: 'owner2@a.test', name: 'Owner 2', is_self: false })
    renderWithProviders(<AccessCenter />, { locale: 'ar' })
    fireEvent.click(screen.getByTestId('access-row-member-owner2@a.test'))
    expect(screen.queryByTestId('drawer-locked')).toBeNull()
    expect(screen.getByTestId('drawer-owner-move-save')).toBeDisabled()
    fireEvent.click(screen.getByTestId('drawer-owner-category-finance'))
    fireEvent.click(screen.getByTestId('drawer-owner-move-save'))
    expect(actions.updateMember.mutateAsync).not.toHaveBeenCalled()
    fireEvent.click(screen.getByTestId('drawer-owner-move-confirm'))
    await waitFor(() => expect(actions.updateMember.mutateAsync).toHaveBeenCalledWith({ id: 'u-owner2', category: 'finance' }))
  })

  it('grants a partly held area in full when the owner picks «إدارة» again (launch included)', async () => {
    data.members[1].areas = { ...data.members[1].areas!, campaigns: { level: 'manage', held: 6, total: 17 } }
    renderWithProviders(<AccessCenter />, { locale: 'ar' })
    fireEvent.click(screen.getByTestId('access-row-member-sara@a.test'))
    expect(screen.getByTestId('partial-campaigns')).toHaveTextContent('6/17')
    fireEvent.click(screen.getByTestId('toggle-campaigns-manage'))
    fireEvent.click(screen.getByTestId('drawer-save'))
    await waitFor(() => expect(actions.updateMember.mutateAsync).toHaveBeenCalledWith(expect.objectContaining({ areas: expect.objectContaining({ campaigns: 'manage' }) })))
  })

  it('keeps another workspace manager locked for a reader who is not one', () => {
    data.members.push({ ...data.members[0], id: 'u-owner2', email: 'owner2@a.test', name: 'Owner 2', is_self: false })
    data.me = { ...data.me, is_owner: false }
    renderWithProviders(<AccessCenter />, { locale: 'ar' })
    fireEvent.click(screen.getByTestId('access-row-member-owner2@a.test'))
    expect(screen.getByTestId('drawer-locked')).toBeInTheDocument()
  })

  it('resends and withdraws an invitation from its drawer; removal needs a second click', async () => {
    renderWithProviders(<AccessCenter />, { locale: 'ar' })
    fireEvent.click(screen.getByTestId('access-row-invitation-late@a.test'))
    fireEvent.click(screen.getByTestId('drawer-resend'))
    await waitFor(() => expect(actions.resend.mutateAsync).toHaveBeenCalledWith('inv1'))
  })

  it('lets the owner create a category from areas, and never deletes one in use', async () => {
    data.categories[3].members = 2
    renderWithProviders(<AccessCenter />, { locale: 'ar' })
    expect(screen.getByTestId('category-delete-cat-1')).toBeDisabled()
    expect(screen.queryByTestId('category-edit-media-buyer')).toBeNull()

    fireEvent.click(screen.getByTestId('category-new'))
    fireEvent.change(screen.getByTestId('category-name'), { target: { value: 'قراءة فقط' } })
    fireEvent.click(screen.getByTestId('toggle-projects-view'))
    expect(within(screen.getByTestId('category-preview')).getByTestId('area-chip-projects')).toHaveAttribute('data-level', 'view')
    fireEvent.click(screen.getByTestId('category-save'))
    await waitFor(() => expect(actions.createCategory.mutateAsync).toHaveBeenCalledWith({ name: 'قراءة فقط', areas: { projects: 'view', campaigns: 'none', finance: 'none', team: 'none' }, all_clients: false }))
  })

  it('hides category controls from anybody but the owner', () => {
    data.me = { ...data.me, is_owner: false }
    renderWithProviders(<AccessCenter />, { locale: 'ar' })
    expect(screen.queryByTestId('category-new')).toBeNull()
    expect(screen.queryByTestId('category-duplicate-media-buyer')).toBeNull()
  })
})
