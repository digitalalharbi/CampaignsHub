import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { RequestIntakePage } from './RequestIntakePage'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return { ...actual, getRequestMeta: vi.fn(), submitRequest: vi.fn() }
})

// Auto-verifying stub so the review step's Submit is reachable in tests (real OTP is exercised elsewhere).
vi.mock('./ContactVerification', () => ({
  ContactVerification: ({ onChange }: { onChange: (ids: { phone?: string; email?: string }) => void }) => (
    <button type="button" onClick={() => onChange({ phone: 'pv', email: 'ev' })}>mock-verify</button>
  ),
}))

// Mock ONLY the catalog hook; the pure helpers (servicesForKeys / mergedRequiredFields / …) stay real.
vi.mock('@/features/paid-media/publicCatalog', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/features/paid-media/publicCatalog')>()
  return { ...actual, usePaidMediaCatalog: vi.fn() }
})

import { getRequestMeta, submitRequest } from './api'
import { usePaidMediaCatalog } from '@/features/paid-media/publicCatalog'

const TYPES = [
  { key: 'paid_media_basic', module: 'paid_media', name_ar: 'إدارة إعلانات مدفوعة', name_en: 'Paid advertising management' },
  { key: 'influencer_ugc', module: 'influencer_marketing', name_ar: 'مؤثرين وUGC', name_en: 'Influencers & UGC' },
]

// Two categories whose services carry DISTINCT required_field_rules, with `platforms` shared (dedupe target).
const CATALOG = {
  version: 'v1',
  categories: [
    { key: 'launch_manage', label_ar: 'إدارة الحملات', label_en: 'Launch & manage', icon: null, sort_order: 1 },
    { key: 'measurement_tracking', label_ar: 'التتبع والقياس', label_en: 'Measurement & tracking', icon: null, sort_order: 2 },
    { key: 'consulting_training', label_ar: 'الاستشارات', label_en: 'Consulting', icon: null, sort_order: 3 },
  ],
  services: [
    { key: 'new_campaign', category_key: 'launch_manage', label_ar: 'إطلاق حملة جديدة', label_en: 'New campaign', description_ar: null, description_en: null, icon: null, sort_order: 1, required_field_rules: ['objective', 'platforms', 'budget', 'period'] },
    { key: 'ga4', category_key: 'measurement_tracking', label_ar: 'إعداد GA4', label_en: 'GA4 setup', description_ar: null, description_en: null, icon: null, sort_order: 1, required_field_rules: ['site_url', 'gtm', 'events', 'platforms'] },
    { key: 'custom_request', category_key: 'consulting_training', label_ar: 'طلب مخصص', label_en: 'Custom request', description_ar: null, description_en: null, icon: null, sort_order: 1, required_field_rules: [] },
  ],
}

function mockCatalog(over: Partial<ReturnType<typeof usePaidMediaCatalog>> = {}) {
  vi.mocked(usePaidMediaCatalog).mockReturnValue({
    data: CATALOG, categories: CATALOG.categories, featured: [],
    isLoading: false, isError: false, refetch: vi.fn(),
    ...over,
  } as unknown as ReturnType<typeof usePaidMediaCatalog>)
}

function fillApplicant() {
  // Required-field labels carry a trailing " *", so match by prefix.
  fireEvent.change(screen.getByLabelText(/^Name/), { target: { value: 'Tester' } })
  fireEvent.change(screen.getByLabelText(/^Email/), { target: { value: 't@e.com' } })
  fireEvent.change(screen.getByLabelText(/^Phone/), { target: { value: '+96650000000' } })
  fireEvent.change(screen.getByLabelText(/^Company/), { target: { value: 'Acme' } })
}
const clickNext = () => fireEvent.click(screen.getByRole('button', { name: /^Next$/i }))
const clickBack = () => fireEvent.click(screen.getByRole('button', { name: /^Back$/i }))

describe('RequestIntakePage — service handoff (default flow, unchanged)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    try { localStorage.clear() } catch { /* noop */ }
    vi.mocked(getRequestMeta).mockResolvedValue({ types: TYPES })
    mockCatalog()
  })
  afterEach(() => { try { localStorage.clear() } catch { /* noop */ } })

  it('preselects the influencer service from ?service=influencer-marketing and skips the service step', async () => {
    renderWithProviders(<RequestIntakePage />, { route: '/requests/new?service=influencer-marketing', locale: 'en' })
    expect(await screen.findByText('Applicant information')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /Back/i }))
    expect(await screen.findByText('Choose a service')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Influencers & UGC/i })).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByRole('button', { name: /Paid advertising management/i })).toHaveAttribute('aria-pressed', 'false')
  })

  it('preselects the influencer service from ?module=influencer-marketing and skips the service step', async () => {
    renderWithProviders(<RequestIntakePage />, { route: '/requests/new?module=influencer-marketing', locale: 'en' })
    expect(await screen.findByText('Applicant information')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /Back/i }))
    expect(await screen.findByText('Choose a service')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Influencers & UGC/i })).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByRole('button', { name: /Paid advertising management/i })).toHaveAttribute('aria-pressed', 'false')
  })

  it('preselects the paid-media service from ?service=paid-media and skips the service step', async () => {
    renderWithProviders(<RequestIntakePage />, { route: '/requests/new?service=paid-media', locale: 'en' })
    expect(await screen.findByText('Applicant information')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /Back/i }))
    expect(await screen.findByText('Choose a service')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Paid advertising management/i })).toHaveAttribute('aria-pressed', 'true')
  })

  it('behaves as today when no ?service param is present (starts on the service step)', async () => {
    renderWithProviders(<RequestIntakePage />, { route: '/requests/new', locale: 'en' })
    expect(await screen.findByText('Choose a service')).toBeInTheDocument()
    expect(await screen.findByRole('button', { name: /Influencers & UGC/i })).toHaveAttribute('aria-pressed', 'false')
    expect(screen.getByRole('button', { name: /Paid advertising management/i })).toHaveAttribute('aria-pressed', 'false')
  })
})

/**
 * INFL-SOON-001 — «علاقات المؤثرين وUGC» is NAMED on the form and cannot be ordered.
 *
 * Absent from the list entirely, it read as «this product does not do influencer work» to the one
 * visitor who came looking for it. Announcing it answers that — but an announcement that could be
 * submitted would be worse than the silence it replaced, so these tests are about the refusal as
 * much as the presence.
 */
describe('RequestIntakePage — a service that is announced but not openable', () => {
  const SOON = [{
    key: 'influencer_ugc', module: 'influencer_marketing',
    name_ar: 'علاقات المؤثرين وUGC', name_en: 'Influencer relations & UGC',
    note_ar: 'هذه الخدمة ستتوفر قريبًا، ولا يمكن إرسال طلب لها حاليًا.',
    note_en: 'This service is coming soon. A request cannot be submitted for it yet.',
  }]

  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(getRequestMeta).mockResolvedValue({ types: [TYPES[0]], coming_soon: SOON })
  })

  it('names it with a coming-soon badge, in Arabic', async () => {
    renderWithProviders(<RequestIntakePage />, { route: '/requests/new', locale: 'ar' })

    const tile = await screen.findByTestId('service-soon-influencer_ugc')
    expect(tile.textContent).toContain('علاقات المؤثرين وUGC')
    expect(tile.textContent).toContain('قريبًا')
  })

  it('names it with a coming-soon badge, in English', async () => {
    renderWithProviders(<RequestIntakePage />, { route: '/requests/new', locale: 'en' })

    const tile = await screen.findByTestId('service-soon-influencer_ugc')
    expect(tile.textContent).toContain('Influencer relations & UGC')
    expect(tile.textContent).toContain('Coming soon')
  })

  /** Pressing it explains, rather than doing nothing — silence reads as a broken button. */
  it('explains that no request can be submitted, when pressed', async () => {
    renderWithProviders(<RequestIntakePage />, { route: '/requests/new', locale: 'ar' })

    fireEvent.click(await screen.findByTestId('service-soon-influencer_ugc'))

    const notice = await screen.findByTestId('service-soon-notice')
    expect(notice.textContent).toBe('هذه الخدمة ستتوفر قريبًا، ولا يمكن إرسال طلب لها حاليًا.')
  })

  /**
   * The claim that matters: pressing it does NOT select it.
   *
   * A tile that looked inert but quietly set the form's type would carry the visitor to step 1 of a
   * service nobody can fulfil, and the refusal would arrive at submit — after they had typed their
   * name, email, phone and company.
   */
  it('does not become the chosen service when pressed', async () => {
    renderWithProviders(<RequestIntakePage />, { route: '/requests/new', locale: 'ar' })

    const tile = await screen.findByTestId('service-soon-influencer_ugc')
    fireEvent.click(tile)

    expect(tile).toHaveAttribute('aria-disabled', 'true')
    // Still on the service step, and nothing is selected.
    expect(screen.getByText('اختر نوع الخدمة')).toBeInTheDocument()
    for (const b of screen.getAllByRole('button')) {
      expect(b.getAttribute('aria-pressed')).not.toBe('true')
    }
  })

  /** With nothing withheld, no announcement appears — the grid is exactly the openable services. */
  it('shows no announcement when every service is openable', async () => {
    vi.mocked(getRequestMeta).mockResolvedValue({ types: [TYPES[0]], coming_soon: [] })

    renderWithProviders(<RequestIntakePage />, { route: '/requests/new', locale: 'ar' })

    await screen.findByText('اختر نوع الخدمة')
    expect(screen.queryByTestId('service-soon-influencer_ugc')).toBeNull()
  })
})

describe('RequestIntakePage — paid-media dynamic intake (?module=paid-media)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    try { localStorage.clear() } catch { /* noop */ }
    vi.mocked(getRequestMeta).mockResolvedValue({ types: TYPES })
    mockCatalog()
  })
  afterEach(() => { try { localStorage.clear() } catch { /* noop */ } })

  it('preselects services from ?services= and shows them as editable chips', async () => {
    renderWithProviders(<RequestIntakePage />, { route: '/requests/new?module=paid-media&services=new_campaign,ga4', locale: 'en' })
    expect(await screen.findByText('New campaign')).toBeInTheDocument()
    expect(screen.getByText('GA4 setup')).toBeInTheDocument()
    // A per-service note input + remove control confirm they are editable chips, not a fresh picker.
    expect(screen.getByRole('button', { name: /Remove New campaign/i })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Remove GA4 setup/i })).toBeInTheDocument()
  })

  it('renders the deduped merged fields once each across steps (budget + site_url/gtm/events, single platforms)', async () => {
    renderWithProviders(<RequestIntakePage />, { route: '/requests/new?module=paid-media&services=new_campaign,ga4', locale: 'en' })
    await screen.findByText('New campaign')
    clickNext() // → applicant
    fillApplicant()
    clickNext() // → brief
    // Brief-group fields (from new_campaign) render, platforms appears exactly ONCE despite both services needing it.
    expect(await screen.findByLabelText('Budget')).toBeInTheDocument()
    expect(screen.getByText('Campaign objective')).toBeInTheDocument()
    expect(screen.getByText('Period')).toBeInTheDocument()
    expect(screen.getAllByText('Platforms')).toHaveLength(1)
    clickNext() // → tracking
    expect(await screen.findByLabelText('Website URL')).toBeInTheDocument()
    expect(screen.getByLabelText('Google Tag Manager')).toBeInTheDocument()
    expect(screen.getByText('Required conversion events')).toBeInTheDocument()
    // platforms is not duplicated into the tracking step.
    expect(screen.queryByText('Platforms')).not.toBeInTheDocument()
  })

  it('removing a service removes its now-unneeded fields (its step disappears)', async () => {
    renderWithProviders(<RequestIntakePage />, { route: '/requests/new?module=paid-media&services=new_campaign,ga4', locale: 'en' })
    await screen.findByText('GA4 setup')
    // A tracking step exists (in the stepper) while ga4 is selected, since ga4 needs site_url/gtm/events.
    expect(screen.getByText('Tracking & integrations')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /Remove GA4 setup/i }))
    // ga4's tracking fields are gone: the whole Tracking step is dropped from the flow.
    expect(screen.queryByText('Tracking & integrations')).not.toBeInTheDocument()
  })

  it('preserves answers when navigating back then forward between steps', async () => {
    renderWithProviders(<RequestIntakePage />, { route: '/requests/new?module=paid-media&services=new_campaign,ga4', locale: 'en' })
    await screen.findByText('New campaign')
    clickNext() // → applicant
    fillApplicant()
    clickNext() // → brief
    fireEvent.change(await screen.findByLabelText('Budget'), { target: { value: '5000' } })
    clickBack() // → applicant
    expect(screen.getByLabelText(/^Name/)).toHaveValue('Tester')
    clickNext() // → brief again
    expect(await screen.findByLabelText('Budget')).toHaveValue('5000')
  })

  it('submits services[] and service_details in the create payload', async () => {
    vi.mocked(submitRequest).mockResolvedValue({
      reference: 'REQ-1', type: 'paid_media_basic', status: 'submitted', submitted_at: null,
      tracking_token: 'tok', tracking_url: '/t', email_delivery: 'pending', next_step: '',
    })
    renderWithProviders(<RequestIntakePage />, { route: '/requests/new?module=paid-media&services=new_campaign,ga4', locale: 'en' })
    await screen.findByText('New campaign')
    clickNext() // applicant
    fillApplicant()
    clickNext() // brief
    fireEvent.change(await screen.findByLabelText('Budget'), { target: { value: '5000' } })
    clickNext() // tracking
    fireEvent.change(await screen.findByLabelText('Website URL'), { target: { value: 'https://x.co' } })
    clickNext() // content
    clickNext() // review
    fireEvent.click(await screen.findByRole('button', { name: /mock-verify/i })) // verify phone+email
    fireEvent.click(screen.getByRole('button', { name: /Submit request/i }))

    await waitFor(() => expect(submitRequest).toHaveBeenCalledTimes(1))
    const payload = vi.mocked(submitRequest).mock.calls[0][0]
    expect(payload.services).toEqual(['new_campaign', 'ga4'])
    expect(payload.service_details).toMatchObject({ budget: '5000', site_url: 'https://x.co' })
    expect(payload.type).toBe('paid_media_basic')
  })

  it('shows an error state + retry (and NO fields) when the catalog fails to load', async () => {
    const refetch = vi.fn()
    mockCatalog({ data: undefined, isError: true, isLoading: false, refetch } as never)
    renderWithProviders(<RequestIntakePage />, { route: '/requests/new?module=paid-media&services=new_campaign', locale: 'en' })
    expect(await screen.findByText('Could not load services, please refresh.')).toBeInTheDocument()
    // No dynamic fields / service steps render on failure.
    expect(screen.queryByText('Budget')).not.toBeInTheDocument()
    expect(screen.queryByText('Tracking & integrations')).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /Retry/i }))
    expect(refetch).toHaveBeenCalled()
  })

  it('shows a required free-text field when custom_request is selected', async () => {
    renderWithProviders(<RequestIntakePage />, { route: '/requests/new?module=paid-media&services=custom_request', locale: 'en' })
    expect(await screen.findByText('Custom request')).toBeInTheDocument() // the selected service chip
    expect(screen.getByLabelText(/Explain what you need in detail/i)).toBeInTheDocument()
    // It is required: advancing without text surfaces the error and blocks navigation.
    clickNext()
    expect(await screen.findByText('Describe your custom request')).toBeInTheDocument()
  })
})
