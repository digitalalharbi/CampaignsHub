import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { ReportScopePicker } from './ReportScopePicker'
import type { ScopeOptions } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return { ...actual, scopeOptions: vi.fn(), listScopeTemplates: vi.fn(), createScopeTemplate: vi.fn(), deleteScopeTemplate: vi.fn() }
})

import { listScopeTemplates, scopeOptions } from './api'

/**
 * CLIENT-REPORT-AUDIENCE — the builder for a client's report does not name the agency's own work.
 *
 * «اسم واختيار الحملة احذفه من التقارير لاحظت اختيار كل الحملات! وظهور اسماءها وهذا غير مناسبة
 * ممكن استبداله باختيار المنصات وهكذا.»
 *
 * The picker offered every internal entity by name — ad accounts, campaigns, ad sets, ads and
 * creatives — with a select-all across them, on the screen that produces a client's report. A
 * campaign called «Meta — Prospecting Broad KSA 3.2» is the agency's working vocabulary; a client
 * reads it as a leak, and the requirement is explicit that a client report may name platforms as
 * aggregate channels and must not name the hierarchy underneath.
 *
 * Both directions are tested, because the other half of the requirement is that INTERNAL surfaces
 * keep the hierarchy: a media buyer scoping an internal report to two ad sets is doing the job the
 * hierarchy exists for, and removing it globally would break the operator surface to fix the client
 * one.
 */
const OPTIONS: ScopeOptions = {
  campaigns: [{ id: 'c1', name: 'Meta — Prospecting Broad KSA 3.2', status: 'active', objective: 'sales' }],
  providers: ['meta', 'tiktok'],
  accounts: [{ id: 'a1', name: 'Nakheel Main Ad Account', provider: 'meta' }],
  ad_sets: [{ id: 's1', name: 'LAL 3% — Riyadh', provider: 'meta', campaign_id: 'c1' }],
  ads: [{ id: 'ad1', name: 'AD-0042 hero cut B', provider: 'meta', campaign_id: 'c1' }],
  creatives: [{ id: 'cr1', name: 'hero_video_v3_final', provider: 'meta', format: 'video', campaign_id: 'c1' }],
  objectives: [{ key: 'sales', labels: { ar: 'المبيعات', en: 'Sales' }, path: 'conversion' }],
  paths: [{ key: 'conversion', labels: { ar: 'التحويل والمبيعات', en: 'Conversion & sales' }, headline_metrics: ['spend'] }],
  metrics: [{ key: 'spend', ar: 'الإنفاق', en: 'Spend' }],
  grain: { figures: ['providers'], resolved_to_campaign: ['ad_set_ids'], creatives_only: ['creative_ids'] },
}

/** Every internal name in the fixture above — none may reach a client-audience builder. */
const INTERNAL_NAMES = [
  'Meta — Prospecting Broad KSA 3.2',
  'Nakheel Main Ad Account',
  'LAL 3% — Riyadh',
  'AD-0042 hero cut B',
  'hero_video_v3_final',
]

const render = (audience: string) =>
  renderWithProviders(
    <ReportScopePicker projectId="p1" value={{}} onChange={vi.fn()} audience={audience} />,
    { locale: 'en' },
  )

describe('what a report builder may name, by audience', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(scopeOptions).mockResolvedValue(OPTIONS)
    vi.mocked(listScopeTemplates).mockResolvedValue({ templates: [] })
  })

  it.each(['client', 'executive'])('a %s report names no internal entity', async (audience) => {
    render(audience)
    await screen.findByText('Platforms')

    for (const name of INTERNAL_NAMES) {
      expect(
        document.body.textContent ?? '',
        `«${name}» reached a client-facing builder`,
      ).not.toContain(name)
    }
  })

  /** What it offers INSTEAD — the channel the client already knows they bought. */
  it.each(['client', 'executive'])('a %s report is still scoped, by platform and by purpose', async (audience) => {
    render(audience)

    expect(await screen.findByText('Platforms')).toBeInTheDocument()
    expect(screen.getByText('meta')).toBeInTheDocument()
    expect(screen.getByText('Sales')).toBeInTheDocument()
  })

  /** The other half: an internal report keeps every rung of the hierarchy. */
  it('an internal report keeps the whole hierarchy', async () => {
    render('internal')
    await screen.findByText('Platforms')

    // Read from the rendered text, because the account chip renders «name · provider» in one node.
    for (const name of INTERNAL_NAMES) {
      expect(
        document.body.textContent ?? '',
        `«${name}» was removed from an internal builder`,
      ).toContain(name)
    }
  })

  /**
   * An unknown or missing audience is treated as CLIENT.
   *
   * It is the builder's own default and the safer direction to be wrong in: an operator who cannot
   * narrow by campaign asks why, while a client who reads a campaign name never knows it happened.
   */
  it('treats an unstated audience as client-facing', async () => {
    renderWithProviders(
      <ReportScopePicker projectId="p1" value={{}} onChange={vi.fn()} />, { locale: 'en' },
    )
    await screen.findByText('Platforms')

    expect(screen.queryByText('Meta — Prospecting Broad KSA 3.2')).not.toBeInTheDocument()
  })
})
