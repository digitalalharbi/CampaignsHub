import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { CampaignControlPanel } from './CampaignControlPanel'
import type { UnifiedCampaign } from './types'
import type { WriteEntity, WriteOptions } from './providerWrites'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('@/lib/api/client', async (orig) => ({
  ...(await orig<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
  api: { post: vi.fn() },
}))
import { api, getData } from '@/lib/api/client'

/**
 * CAMPAIGN-MGMT-WRITE-001 — the panel offers exactly what the platform allows, says why the rest is
 * withheld, sends the change, and reports the platform's own answer.
 */
const campaign = { id: 'u1', project_id: 'p1', name: 'Spring', objective: 'sales', status: 'active', total_budget: 1000, budget_currency: 'SAR' } as unknown as UnifiedCampaign

const all = (state: string) => ({ pause: state, resume: state, rename: state, budget: state, schedule: state, bid_strategy: state, archive: state, delete: state, duplicate: state, create_ad_set: state, create_ad: state, targeting: state, placements: state, creative: state, destination: state }) as WriteEntity['actions']

const entity = (over: Partial<WriteEntity> = {}): WriteEntity => ({
  id: 'e1', level: 'campaign', parent_id: null, provider: 'google', external_id: '9001', name: 'Spring — Search', status: 'active',
  daily_budget: 300, lifetime_budget: null, currency: 'SAR', bid_strategy: null,
  account: { id: 'a1', name: 'Store Ads', external_id: '1234567890' },
  actions: { ...all('available'), archive: 'provider_unsupported', duplicate: 'provider_unsupported', schedule: 'not_implemented' },
  budget_kinds: ['daily'], bid_strategies: ['MANUAL_CPC', 'MAXIMIZE_CONVERSIONS'],
  optimization_goals: [], placement_families: [], targeting: null, destination_url: null,
  ...over,
})

function serve(options: WriteOptions) {
  vi.mocked(getData).mockImplementation((path: string) => {
    if (path.endsWith('/provider-writes')) return Promise.resolve(options)
    return Promise.resolve([]) // activity
  })
}

describe('the campaign control panel', () => {
  beforeEach(() => { vi.clearAllMocks(); signInWith(['campaigns.view', 'campaigns.update', 'campaigns.pause']) })
  afterEach(() => signOut())

  it('offers the platform’s actions and names why the others are withheld', async () => {
    serve({ entities: [entity()], create: [] })
    renderWithProviders(<CampaignControlPanel campaign={campaign} projectId="p1" />, { locale: 'en' })

    expect(await screen.findByTestId('control-pause-e1')).toBeInTheDocument()
    expect(screen.getByTestId('control-budget-e1')).toBeInTheDocument()
    expect(screen.getByTestId('control-delete-e1')).toBeEnabled()
    expect(screen.queryByTestId('control-archive-e1')).not.toBeInTheDocument()
    const withheld = screen.getByTestId('control-withheld-e1')
    expect(withheld).toHaveTextContent('Not built yet: Schedule')
    expect(screen.getByTestId('control-schedule-e1')).toBeDisabled()
    expect(withheld).toHaveTextContent('Google does not allow: Duplicate, Archive')
  })

  it('pauses on the platform and reports its confirmation with the request id', async () => {
    serve({ entities: [entity()], create: [] })
    vi.mocked(api.post).mockResolvedValue({ data: { data: { ok: true, request_id: 'rq-77', mirror: { status: 'paused' } } } } as never)
    renderWithProviders(<CampaignControlPanel campaign={campaign} projectId="p1" />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('control-pause-e1'))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/projects/p1/campaigns/u1/provider-writes', { level: 'campaign', entity_id: 'e1', action: 'pause' }, expect.anything()))
    const banner = await screen.findByTestId('control-banner')
    expect(banner).toHaveAttribute('data-tone', 'success')
    expect(banner).toHaveTextContent('Google confirmed: Pause — Spring — Search')
    expect(banner).toHaveTextContent('rq-77')
  })

  it('shows the platform’s refusal in its own words', async () => {
    serve({ entities: [entity()], create: [] })
    vi.mocked(api.post).mockResolvedValue({ data: { data: { ok: false, refusal: 'provider_refused', message: 'BUDGET_AMOUNT_TOO_SMALL' } } } as never)
    renderWithProviders(<CampaignControlPanel campaign={campaign} projectId="p1" />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('control-budget-e1'))
    fireEvent.change(screen.getByTestId('control-input-amount'), { target: { value: '1' } })
    fireEvent.click(screen.getByTestId('control-dialog-submit'))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith(expect.any(String), expect.objectContaining({ action: 'budget', daily_budget: 1 }), expect.anything()))
    const banner = await screen.findByTestId('control-banner')
    expect(banner).toHaveAttribute('data-tone', 'danger')
    expect(banner).toHaveTextContent('Google refused: BUDGET_AMOUNT_TOO_SMALL')
  })

  it('states the platform’s consequence before a delete and sends only after it is acknowledged', async () => {
    serve({ entities: [entity()], create: [] })
    vi.mocked(api.post).mockResolvedValue({ data: { data: { ok: true, mirror: { status: 'deleted' } } } } as never)
    renderWithProviders(<CampaignControlPanel campaign={campaign} projectId="p1" />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('control-delete-e1'))
    expect(screen.getByTestId('control-consequence')).toHaveTextContent('Removed from Google Ads for good (REMOVED)')
    expect(screen.getByTestId('control-dialog-submit')).toBeDisabled()
    fireEvent.click(screen.getByTestId('control-understood'))
    fireEvent.click(screen.getByTestId('control-dialog-submit'))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith(expect.any(String), expect.objectContaining({ action: 'delete', confirm: true }), expect.anything()))
  })

  it('lists the project’s accounts for creation, with the reason an account cannot be used', async () => {
    serve({ entities: [], create: [
      { id: 'a1', provider: 'meta', name: 'Meta Main', currency: 'SAR', state: 'available', launch_permitted: true, objectives: ['OUTCOME_SALES', 'OUTCOME_TRAFFIC'] },
      { id: 'a2', provider: 'tiktok', name: 'TT', currency: 'SAR', state: 'awaiting_credentials', launch_permitted: true, objectives: ['TRAFFIC'] },
    ] })
    vi.mocked(api.post).mockResolvedValue({ data: { data: { ok: true, external_id: '120200', launched: false } } } as never)
    renderWithProviders(<CampaignControlPanel campaign={campaign} projectId="p1" />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('control-create'))
    const list = screen.getByTestId('control-create-accounts')
    expect(within(list).getByText('Awaiting platform credentials')).toBeInTheDocument()
    fireEvent.change(screen.getByTestId('control-create-budget'), { target: { value: '200' } })
    fireEvent.click(screen.getByTestId('control-create-submit'))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/projects/p1/campaigns/u1/provider-campaigns', { external_account_id: 'a1', objective: 'OUTCOME_SALES', daily_budget: 200, launch: false }, expect.anything()))
    expect(await screen.findByTestId('control-banner')).toHaveTextContent('Created on the platform (id 120200), paused')
  })

  it('opens creation even with no selected account, and says where to choose one', async () => {
    serve({ entities: [], create: [] })
    renderWithProviders(<CampaignControlPanel campaign={campaign} projectId="p1" />, { route: '/agency/campaigns/p1/u1', locale: 'en' })

    fireEvent.click(await screen.findByTestId('control-create'))
    const none = screen.getByTestId('control-create-none')
    expect(none).toHaveTextContent('No ad account is selected for this project')
    expect(within(none).getByRole('link')).toHaveAttribute('href', '/agency/projects/p1/integrations')
  })

  it('edits an ad set’s targeting with parsed country codes and the age range', async () => {
    serve({ entities: [entity(), entity({ id: 's1', level: 'ad_set', parent_id: 'e1', provider: 'meta', name: 'KSA set', targeting: { countries: ['SA'], age: '25-44' }, placement_families: ['facebook', 'instagram'] })], create: [] })
    vi.mocked(api.post).mockResolvedValue({ data: { data: { ok: true } } } as never)
    renderWithProviders(<CampaignControlPanel campaign={campaign} projectId="p1" />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('control-targeting-s1'))
    expect(screen.getByTestId('control-input-countries')).toHaveValue('SA')
    fireEvent.change(screen.getByTestId('control-input-countries'), { target: { value: 'sa, ae ,kw, xyz' } })
    fireEvent.click(screen.getByTestId('control-dialog-submit'))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith(expect.any(String), { level: 'ad_set', entity_id: 's1', action: 'targeting', countries: ['SA', 'AE', 'KW'], age_min: 25, age_max: 44, genders: 'all' }, expect.anything()))
  })

  it('offers Snapchat’s own placement positions by name and sends the chosen ones', async () => {
    serve({ entities: [entity(), entity({ id: 's2', level: 'ad_set', parent_id: 'e1', provider: 'snapchat', name: 'Snap set', placement_families: ['interstitial_user', 'feed', 'chat_feed'] })], create: [] })
    vi.mocked(api.post).mockResolvedValue({ data: { data: { ok: true } } } as never)
    renderWithProviders(<CampaignControlPanel campaign={campaign} projectId="p1" />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('control-placements-s2'))
    fireEvent.change(screen.getByTestId('control-input-placement-mode'), { target: { value: 'custom' } })
    const families = screen.getByTestId('control-input-families')
    expect(families).toHaveTextContent('Chat Feed')
    fireEvent.click(within(families).getByLabelText('Feed'))
    fireEvent.click(within(families).getByLabelText('Chat Feed'))
    fireEvent.click(screen.getByTestId('control-dialog-submit'))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith(expect.any(String), { level: 'ad_set', entity_id: 's2', action: 'placements', mode: 'custom', platforms: ['feed', 'chat_feed'] }, expect.anything()))
  })

  it('tells a TikTok targeting edit that age comes in fixed bands', async () => {
    serve({ entities: [entity(), entity({ id: 's3', level: 'ad_set', parent_id: 'e1', provider: 'tiktok', name: 'TT set' })], create: [] })
    renderWithProviders(<CampaignControlPanel campaign={campaign} projectId="p1" />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('control-targeting-s3'))
    expect(screen.getByTestId('control-tiktok-age-bands')).toHaveTextContent('fixed bands')
  })

  it('creates an ad bound to a creative picked from the project’s own', async () => {
    serve({
      entities: [entity(), entity({ id: 's1', level: 'ad_set', parent_id: 'e1', provider: 'meta', name: 'KSA set' })],
      create: [],
      creatives: { meta: [{ id: 'cr1', name: 'Hero video', format: 'video', thumbnail_url: null }, { id: 'cr2', name: 'Promo image', format: 'image', thumbnail_url: null }] },
    })
    vi.mocked(api.post).mockResolvedValue({ data: { data: { ok: true, new_external_id: '7700' } } } as never)
    renderWithProviders(<CampaignControlPanel campaign={campaign} projectId="p1" />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('control-create_ad-s1'))
    fireEvent.change(screen.getByTestId('control-input-child-name'), { target: { value: 'Promo — KSA' } })
    fireEvent.click(within(screen.getByTestId('control-creative-picker')).getByText('Promo image'))
    fireEvent.click(screen.getByTestId('control-dialog-submit'))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith(expect.any(String), { level: 'ad_set', entity_id: 's1', action: 'create_ad', name: 'Promo — KSA', creative_id: 'cr2' }, expect.anything()))
  })

  it('offers an action only on the rung it belongs to', async () => {
    serve({ entities: [entity(), entity({ id: 'a1', level: 'ad', parent_id: null, name: 'Loose ad' })], create: [] })
    renderWithProviders(<CampaignControlPanel campaign={campaign} projectId="p1" />, { locale: 'en' })

    expect(await screen.findByTestId('control-create_ad_set-e1')).toBeInTheDocument()
    expect(screen.queryByTestId('control-create_ad-e1')).not.toBeInTheDocument()
    expect(screen.queryByTestId('control-budget-a1')).not.toBeInTheDocument()
    expect(screen.getByTestId('control-destination-a1')).toBeInTheDocument()
  })
})
