import { describe, expect, it, vi, beforeEach } from 'vitest'
import { toFormOption, toFormOptions, type TaxonomyOption } from './taxonomyApi'

vi.mock('@/lib/api/client', () => ({
  api: { get: vi.fn() },
  getData: vi.fn(),
  postData: vi.fn(),
  patchData: vi.fn(),
  deleteData: vi.fn(),
}))

const row = (over: Partial<TaxonomyOption>): TaxonomyOption => ({
  id: 'o1',
  key: 'client.status',
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
  ...over,
})

describe('taxonomyApi mapping', () => {
  beforeEach(() => vi.clearAllMocks())

  it('maps a backend option row to the pure Option shape', () => {
    const opt = toFormOption(row({ id: 'active', color: '#123456' }))
    expect(opt).toMatchObject({
      value: 'active',
      label_ar: 'نشط',
      label_en: 'Active',
      color: '#123456',
      disabled: false,
      isSystem: false,
    })
  })

  it('marks inactive options as disabled and wires parentValue', () => {
    const opts = toFormOptions([
      row({ id: 'parent' }),
      row({ id: 'child', parent_option_id: 'parent', is_active: false }),
    ])
    expect(opts[1]).toMatchObject({ value: 'child', parentValue: 'parent', disabled: true })
  })

  it('getOptions calls the scoped endpoint and unwraps the envelope', async () => {
    const { api } = await import('@/lib/api/client')
    vi.mocked(api.get).mockResolvedValue({ data: { data: [row({ id: 'x' })] } })
    const { getOptions } = await import('./taxonomyApi')
    const result = await getOptions('client.status', 'tenant')
    expect(api.get).toHaveBeenCalledWith('/taxonomies/client.status/options', { params: { scope: 'tenant' } })
    expect(result).toHaveLength(1)
  })

  it('createOption posts to the key endpoint', async () => {
    const { postData } = await import('@/lib/api/client')
    const { createOption } = await import('./taxonomyApi')
    await createOption('client.tags', { label_ar: 'مميز', label_en: 'VIP' })
    expect(postData).toHaveBeenCalledWith('/taxonomies/client.tags/options', { label_ar: 'مميز', label_en: 'VIP' })
  })
})
