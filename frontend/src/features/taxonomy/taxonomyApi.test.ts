import { describe, expect, it, vi, beforeEach } from 'vitest'
import {
  flattenOptions, optionsForParent, slugifyKey, toFormOption, toFormOptions, type TaxonomyOption,
} from './taxonomyApi'

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

  it('maps a backend option row to the pure Option shape (value = backend key)', () => {
    const opt = toFormOption(row({ id: 'uuid-1', key: 'active', color: '#123456' }))
    expect(opt).toMatchObject({
      value: 'active', // the backend enum key, not the row id
      label_ar: 'نشط',
      label_en: 'Active',
      color: '#123456',
      disabled: false,
      isSystem: false,
    })
  })

  it('marks inactive options as disabled and wires parentValue from parent_key', () => {
    const opts = toFormOptions([
      row({ id: 'p', key: 'paid_media' }),
      row({ id: 'c', key: 'paid_media__new_campaign', parent_key: 'paid_media', is_active: false }),
    ])
    expect(opts[1]).toMatchObject({ value: 'paid_media__new_campaign', parentValue: 'paid_media', disabled: true })
  })

  it('flattenOptions walks nested children (roots first, then children)', () => {
    const flat = flattenOptions([
      row({ id: 'p', key: 'paid_media', children: [row({ id: 'c', key: 'paid_media__new', parent_key: 'paid_media' })] }),
    ])
    expect(flat.map((o) => o.key)).toEqual(['paid_media', 'paid_media__new'])
  })

  it('optionsForParent keeps only the selected parent\'s children (dependent selects)', () => {
    const options = toFormOptions([
      row({ id: 'c1', key: 'paid__new', parent_key: 'paid' }),
      row({ id: 'c2', key: 'paid__audit', parent_key: 'paid' }),
      row({ id: 'c3', key: 'analytics__audit', parent_key: 'analytics' }),
    ])
    expect(optionsForParent(options, 'paid').map((o) => o.value)).toEqual(['paid__new', 'paid__audit'])
    expect(optionsForParent(options, null)).toEqual([]) // no parent chosen → empty child set
  })

  it('slugifyKey produces a stable option key from a free-text label', () => {
    expect(slugifyKey('  New Campaign!! ')).toBe('new_campaign')
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

  it('createOptionFromDraft derives a key, writes it, and returns a key-valued Option', async () => {
    const { postData } = await import('@/lib/api/client')
    vi.mocked(postData).mockResolvedValue(row({ id: 'new-uuid', key: 'vip', label_ar: 'مميز', label_en: 'VIP' }))
    const { createOptionFromDraft } = await import('./taxonomyApi')
    const opt = await createOptionFromDraft('client.tags', { label_ar: 'مميز', label_en: 'VIP' })
    expect(postData).toHaveBeenCalledWith(
      '/taxonomies/client.tags/options',
      expect.objectContaining({ key: 'vip', label_ar: 'مميز', label_en: 'VIP' }),
    )
    expect(opt.value).toBe('vip') // the persisted value is a backend key, so a select submitting it never 422s
  })
})
