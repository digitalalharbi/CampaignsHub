import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { TaxonomyManagerPage } from './TaxonomyManagerPage'
import type { TaxonomyDefinition, TaxonomyOption } from './taxonomyApi'

// Mock the HTTP client so the real taxonomyApi functions + react-query hooks run end-to-end.
vi.mock('@/lib/api/client', () => ({
  api: { get: vi.fn() },
  getData: vi.fn(),
  postData: vi.fn(),
  patchData: vi.fn(),
  deleteData: vi.fn(),
  toApiError: (e: unknown) => ({ message: e instanceof Error ? e.message : 'error', status: undefined, errors: null }),
}))

import { api, getData, postData, patchData } from '@/lib/api/client'

const DEF: TaxonomyDefinition = {
  key: 'client.status',
  module: 'clients',
  scope: 'tenant',
  field_type: 'single',
  label_ar: 'حالة العميل',
  label_en: 'Client status',
  description: null,
  is_system: true,
  is_active: true,
  allows_custom_options: true,
  allows_multiple: false,
  maximum_selections: null,
  sort_order: 0,
  tenant_id: null,
}

const opt = (over: Partial<TaxonomyOption>): TaxonomyOption => ({
  id: 'o1',
  key: 'active',
  taxonomy_definition_id: 'd1',
  label_ar: 'نشط',
  label_en: 'Active',
  description: null,
  color: '#0d8a6f',
  icon: null,
  parent_option_id: null,
  sort_order: 0,
  is_default: false,
  is_active: true,
  is_system: false,
  tenant_id: null,
  metadata: null,
  usage_count: 0,
  ...over,
})

const OPTIONS = [
  opt({ id: 'sys', key: 'active', label_en: 'Active', label_ar: 'نشط', is_system: true, is_default: true, sort_order: 0, usage_count: 12 }),
  opt({ id: 'churn', key: 'churned', label_en: 'Churned', label_ar: 'منسحب', is_system: false, sort_order: 1, usage_count: 3 }),
]

beforeEach(() => {
  // getData handles /taxonomies (definitions) and /options/{id}/usage.
  vi.mocked(getData).mockImplementation(async (url: string) => {
    if (url === '/taxonomies') return [DEF] as never
    if (url.endsWith('/usage')) return { option_id: 'churn', usage_count: 3, can_delete: false } as never
    return null as never
  })
  // getOptions uses api.get(...).data.data
  vi.mocked(api.get).mockResolvedValue({ data: { data: OPTIONS } } as never)
  vi.mocked(postData).mockResolvedValue(opt({ id: 'new', label_en: 'VIP', label_ar: 'مميز' }) as never)
  vi.mocked(patchData).mockResolvedValue(opt({ id: 'sys' }) as never)
})

afterEach(() => {
  vi.clearAllMocks()
  signOut()
})

describe('TaxonomyManagerPage', () => {
  it('renders definitions grouped by module and loads the first definition options', async () => {
    signInWith(['taxonomies.manage', 'options.create'])
    renderWithProviders(<TaxonomyManagerPage />, { locale: 'en' })

    expect(await screen.findByText('Client status')).toBeInTheDocument()
    expect(screen.getByText('clients')).toBeInTheDocument() // module group header
    // Options for the auto-selected first definition load.
    expect(await screen.findByText('Churned')).toBeInTheDocument()
  })

  it('is read-only without manage/options permissions (no add/reorder/actions)', async () => {
    signInWith([]) // signed in, but no taxonomy permissions
    renderWithProviders(<TaxonomyManagerPage />, { locale: 'en' })

    await screen.findByText('Churned')
    expect(screen.getByText(/Read-only/i)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Add option' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Move up' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Actions' })).not.toBeInTheDocument()
  })

  it('creates an option through the drawer (createOption called with the key)', async () => {
    signInWith(['taxonomies.manage', 'options.create'])
    renderWithProviders(<TaxonomyManagerPage />, { locale: 'en' })

    fireEvent.click(await screen.findByRole('button', { name: 'Add option' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Name (English)'), { target: { value: 'VIP' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))

    await waitFor(() =>
      expect(postData).toHaveBeenCalledWith('/taxonomies/client.status/options', expect.objectContaining({ label_en: 'VIP' })),
    )
  })

  it('merge flow surfaces the usage count before confirming', async () => {
    signInWith(['taxonomies.manage'])
    renderWithProviders(<TaxonomyManagerPage />, { locale: 'en' })

    await screen.findByText('Churned')
    // Open the action menu on the churned (non-default) row and pick Merge.
    const menus = screen.getAllByRole('button', { name: 'Actions' })
    fireEvent.click(menus[menus.length - 1])
    fireEvent.click(await screen.findByRole('button', { name: 'Merge' }))

    // Usage report is fetched and shown before a target is chosen.
    await waitFor(() =>
      expect(vi.mocked(getData).mock.calls.some(([u]) => String(u).endsWith('/usage'))).toBe(true),
    )
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText('3')).toBeInTheDocument()
  })
})
