import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { PlanChooser } from './PlanChooser'
import type { Plan } from './api'
import { renderWithProviders, signOut } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, fetchPlans: vi.fn() }
})

import { fetchPlans } from './api'

const plan = (over: Partial<Plan>): Plan => ({
  code: 'growth', name: 'Growth', name_ar: 'النمو',
  summary_ar: null, summary_en: null, currency: 'SAR',
  price_monthly: '499.00', price_annual: '4990.00',
  trial_days: 7, trial_fee: '9.00',
  features: null, limits: null, trial_limits: null,
  ...over,
})

/**
 * The plan chooser (PLAN-001).
 *
 * The claim throughout is that this renders the catalogue and never invents it: no price that is not
 * sold, no trial a plan does not offer, no annual term nobody offers.
 */
describe('PlanChooser', () => {
  const noop = () => {}

  beforeEach(() => vi.clearAllMocks())
  afterEach(() => signOut())

  it('shows each plan at the price for the chosen term', async () => {
    vi.mocked(fetchPlans).mockResolvedValue({
      plans: [plan({ code: 'starter', name: 'Starter', price_monthly: '0.00', price_annual: null, trial_days: 0 }), plan({})],
    })

    renderWithProviders(
      <PlanChooser value={null} interval="monthly" onChange={noop} onIntervalChange={noop} />,
      { locale: 'en' },
    )

    /*
      The amount and its currency are separate elements — the figure is the headline and the code
      sits beside it at a smaller weight — so they are asserted separately rather than as one
      string. A test matching «499.00 SAR» would be asserting that they share a text node, which is
      a fact about the markup and not about the price.
    */
    expect(await screen.findByTestId('plan-growth')).toHaveTextContent('499.00')
    expect(screen.getByTestId('plan-growth')).toHaveTextContent('SAR')
    expect(screen.getByTestId('plan-starter')).toHaveTextContent('0.00')
  })

  /**
   * A plan not sold on the chosen term shows no price and cannot be picked.
   *
   * Falling back to the monthly figure would quote something nobody can buy — which is the same
   * defect on the server, where `priceFor('annual')` returns null rather than the monthly price.
   */
  it('will not quote a term a plan is not sold on', async () => {
    vi.mocked(fetchPlans).mockResolvedValue({
      plans: [plan({ code: 'starter', name: 'Starter', price_monthly: '0.00', price_annual: null, trial_days: 0 })],
    })

    renderWithProviders(
      <PlanChooser value={null} interval="annual" onChange={noop} onIntervalChange={noop} />,
      { locale: 'en' },
    )

    const card = await screen.findByTestId('plan-starter')
    expect(card).toBeDisabled()
    expect(card).toHaveTextContent(/Not sold annually/i)
    expect(card).not.toHaveTextContent('0.00')
  })

  /** The introductory month is announced only where the plan offers one, with its own price and length. */
  it('announces the introductory month only where the plan has one', async () => {
    vi.mocked(fetchPlans).mockResolvedValue({
      plans: [plan({}), plan({ code: 'starter', name: 'Starter', trial_days: 0, price_annual: null })],
    })

    renderWithProviders(
      <PlanChooser value={null} interval="monthly" onChange={noop} onIntervalChange={noop} />,
      { locale: 'en' },
    )

    // The offer states what it becomes, not only what it costs — SUB-COMMIT-001.
    expect(await screen.findByTestId('plan-growth-intro'))
      .toHaveTextContent('First 7 days for 9.00 SAR, then 499.00 SAR a month')
    expect(screen.queryByTestId('plan-starter-intro')).not.toBeInTheDocument()
  })

  /**
   * And never beside an ANNUAL price — PAY-AUDIT-003.
   *
   * The annual term is bought outright, so advertising an introductory month next to it would
   * promise a charge the checkout will not make. The chooser used to show it on both terms.
   */
  it('does not announce an introductory month on the annual term', async () => {
    vi.mocked(fetchPlans).mockResolvedValue({ plans: [plan({})] })

    renderWithProviders(
      <PlanChooser value={null} interval="annual" onChange={noop} onIntervalChange={noop} />,
      { locale: 'en' },
    )

    expect(await screen.findByTestId('plan-growth')).toBeInTheDocument()
    expect(screen.queryByTestId('plan-growth-intro')).not.toBeInTheDocument()
  })

  /** Arabic counts 3–10 with the plural and 11+ with the singular accusative: «30 يومًا», not «30 أيام». */
  it('gets the Arabic number agreement right for a thirty-day month', async () => {
    vi.mocked(fetchPlans).mockResolvedValue({ plans: [plan({ trial_days: 30 })] })

    renderWithProviders(
      <PlanChooser value={null} interval="monthly" onChange={noop} onIntervalChange={noop} />,
      { locale: 'ar' },
    )

    const badge = await screen.findByTestId('plan-growth-intro')
    expect(badge).toHaveTextContent('30 يومًا')
    expect(badge).not.toHaveTextContent('30 أيام')
  })

  /** No annual toggle when nothing is sold annually — an empty choice is not a choice. */
  it('hides the annual term when no plan offers one', async () => {
    vi.mocked(fetchPlans).mockResolvedValue({
      plans: [plan({ code: 'starter', name: 'Starter', price_annual: null, trial_days: 0 })],
    })

    renderWithProviders(
      <PlanChooser value={null} interval="monthly" onChange={noop} onIntervalChange={noop} />,
      { locale: 'en' },
    )

    await screen.findByTestId('plan-starter')
    expect(screen.queryByTestId('plan-interval-annual')).not.toBeInTheDocument()
  })

  it('reports the chosen plan', async () => {
    vi.mocked(fetchPlans).mockResolvedValue({ plans: [plan({})] })
    const onChange = vi.fn()

    renderWithProviders(
      <PlanChooser value={null} interval="monthly" onChange={onChange} onIntervalChange={noop} />,
      { locale: 'en' },
    )

    fireEvent.click(await screen.findByTestId('plan-growth'))
    expect(onChange).toHaveBeenCalledWith('growth')
  })

  // ── The path decides the plans — PLAN-FIT-001 / LAUNCH-PRICING-001 ─────────────────────────────

  /** «For my own campaigns» is sold Starter and Growth, and is never shown Agency. */
  it('offers the self-service path the two plans that fit it', async () => {
    vi.mocked(fetchPlans).mockResolvedValue({
      plans: [
        plan({ code: 'starter', name: 'Starter', trial_days: 0 }),
        plan({}),
        plan({ code: 'agency', name: 'Agency', trial_days: 0 }),
      ],
    })

    renderWithProviders(
      <PlanChooser value={null} interval="monthly" onChange={noop} onIntervalChange={noop} journey="self-service" />,
      { locale: 'en' },
    )

    await screen.findByTestId('plan-starter')
    expect(screen.getByTestId('plan-growth')).toBeInTheDocument()
    expect(screen.queryByTestId('plan-agency')).not.toBeInTheDocument()
  })

  /**
   * **«For my clients» is sold Agency, and only Agency.**
   *
   * Growth was briefly offered here too, and it does not fit: client workspaces, per-client team
   * scopes and white-label reports are Agency's. Offering Growth to somebody who has just said they
   * manage several clients sells them a plan they must leave.
   */
  it('offers the agency path one plan and nothing to compare it against', async () => {
    vi.mocked(fetchPlans).mockResolvedValue({
      plans: [
        plan({ code: 'starter', name: 'Starter', trial_days: 0 }),
        plan({}),
        plan({ code: 'agency', name: 'Agency', trial_days: 0 }),
      ],
    })

    renderWithProviders(
      <PlanChooser value={null} interval="monthly" onChange={noop} onIntervalChange={noop} journey="multi-client" />,
      { locale: 'en' },
    )

    expect(await screen.findByTestId('plan-agency')).toBeInTheDocument()
    expect(screen.queryByTestId('plan-starter')).not.toBeInTheDocument()
    expect(screen.queryByTestId('plan-growth')).not.toBeInTheDocument()

    // One card has nothing to be compared with, so the comparison is not offered.
    expect(screen.queryByTestId('plan-compare-open')).not.toBeInTheDocument()
  })

  /**
   * The full table lives behind a press, not on the cards.
   *
   * Seven axes on a card is a specification the reader has to hold in their head; side by side it is
   * one glance. The cards carry at most four differences and this carries the rest.
   */
  it('puts the whole comparison behind one press', async () => {
    vi.mocked(fetchPlans).mockResolvedValue({
      plans: [
        plan({ code: 'starter', name: 'Starter', trial_days: 0, limits: { projects: 3 } }),
        plan({ limits: { projects: 25 } }),
      ],
    })

    renderWithProviders(
      <PlanChooser value={null} interval="monthly" onChange={noop} onIntervalChange={noop} journey="self-service" />,
      { locale: 'en' },
    )

    expect(screen.queryByTestId('plan-comparison')).not.toBeInTheDocument()

    fireEvent.click(await screen.findByTestId('plan-compare-open'))

    expect(await screen.findByTestId('plan-comparison')).toBeInTheDocument()
    expect(screen.getByTestId('compare-projects-starter')).toHaveTextContent('3')
    expect(screen.getByTestId('compare-projects-growth')).toHaveTextContent('25')
  })

  /**
   * **The introductory price is not the headline.** The regular price is.
   *
   * Leading with 9 sells a number nobody pays for more than a month, and the surprise arrives on the
   * second charge. The card states the regular price large, and the offer — with the commitment
   * behind it — beneath.
   */
  it('leads with the regular price and states the offer beneath it', async () => {
    vi.mocked(fetchPlans).mockResolvedValue({
      plans: [plan({ trial_days: 30, trial_fee: '9.00', price_monthly: '49.00', minimum_commitment_months: 3 })],
    })

    renderWithProviders(
      <PlanChooser value={null} interval="monthly" onChange={noop} onIntervalChange={noop} journey="self-service" />,
      { locale: 'en' },
    )

    const card = await screen.findByTestId('plan-growth')
    expect(card).toHaveTextContent('49.00')

    const offer = screen.getByTestId('plan-growth-intro')
    expect(offer).toHaveTextContent('First 30 days for 9.00 SAR, then 49.00 SAR a month')
    expect(screen.getByTestId('plan-growth-commitment')).toHaveTextContent('3-month minimum commitment')
  })

  /**
   * A catalogue that failed to load is said out loud and does not block the application.
   *
   * The plan decides which policy applies and what a checkout later charges — both server-side
   * questions. Refusing to let someone sign up because a price list timed out would be the wrong
   * trade.
   */
  it('does not block signing up when the catalogue cannot be read', async () => {
    vi.mocked(fetchPlans).mockRejectedValue(new Error('offline'))

    renderWithProviders(
      <PlanChooser value={null} interval="monthly" onChange={noop} onIntervalChange={noop} />,
      { locale: 'en' },
    )

    expect(await screen.findByTestId('plans-unavailable')).toBeInTheDocument()
  })

  // ── The four outcomes are four different things — SIGNUP-CAT-001 ──────────────────────────────

  /**
   * **A successful load that offers nothing must SAY so.**
   *
   * This rendered the heading and an empty space: no plans, no explanation, no way forward. An
   * applicant sat on a step that could not be completed with nothing on screen to explain it, and
   * nothing in the suite noticed — which is how a signup blocker survives a green run.
   *
   * It must not borrow the «could not be loaded» sentence either: the load SUCCEEDED, and sending
   * somebody to check their connection over a fault that is ours is the wrong instruction.
   */
  it('says the catalogue is empty rather than rendering nothing', async () => {
    vi.mocked(fetchPlans).mockResolvedValue({ plans: [] })

    renderWithProviders(
      <PlanChooser value={null} interval="monthly" onChange={noop} onIntervalChange={noop} journey="self-service" />,
      { locale: 'en' },
    )

    const blocked = await screen.findByTestId('plans-empty')
    expect(blocked).toHaveTextContent(/No plan is on sale/i)
    // Named as a configuration fault, not as a failure to load.
    expect(blocked).toHaveTextContent(/configuration fault/i)
    expect(screen.queryByTestId('plans-unavailable')).not.toBeInTheDocument()
  })

  /**
   * …and so must a catalogue that has plans but none for THIS path.
   *
   * Reachable whenever the offered codes and the codes on sale drift apart — precisely what a rename
   * like `scale` → `agency` does if the catalogue is not migrated with it. Silence would strand the
   * agency applicants alone, which is the kind of half-broken nobody notices.
   */
  it('says when the catalogue is fine but this path is offered nothing', async () => {
    vi.mocked(fetchPlans).mockResolvedValue({
      plans: [plan({ code: 'starter', name: 'Starter', trial_days: 0 })],
    })

    renderWithProviders(
      <PlanChooser value={null} interval="monthly" onChange={noop} onIntervalChange={noop} journey="multi-client" />,
      { locale: 'en' },
    )

    const blocked = await screen.findByTestId('plans-none-for-path')
    expect(blocked).toHaveTextContent(/No plan is offered for this path/i)
    // Retrying a correct answer is a loop with no exit, so none is offered.
    expect(screen.queryByTestId('plans-none-for-path-retry')).not.toBeInTheDocument()
  })

  /** The retry re-issues the REAL request — it does not clear the message and hope. */
  it('retries by asking the server again', async () => {
    vi.mocked(fetchPlans).mockRejectedValueOnce(new Error('offline'))
      .mockResolvedValueOnce({ plans: [plan({ code: 'starter', name: 'Starter', trial_days: 0 }), plan({})] })

    renderWithProviders(
      <PlanChooser value={null} interval="monthly" onChange={noop} onIntervalChange={noop} journey="self-service" />,
      { locale: 'en' },
    )

    await screen.findByTestId('plans-unavailable')
    expect(fetchPlans).toHaveBeenCalledTimes(1)

    fireEvent.click(screen.getByTestId('plans-unavailable-retry'))

    expect(await screen.findByTestId('plan-starter')).toBeInTheDocument()
    expect(fetchPlans).toHaveBeenCalledTimes(2)
  })

  /**
   * **A successful load can never be rendered as a failure.** The other direction of the same rule.
   *
   * Asserted explicitly because the four states are now decided by three conditions in a row, and an
   * ordering mistake there would put a working catalogue behind an error message.
   */
  it('never shows a failure state when the catalogue loaded and fits the path', async () => {
    vi.mocked(fetchPlans).mockResolvedValue({
      plans: [plan({ code: 'starter', name: 'Starter', trial_days: 0 }), plan({})],
    })

    renderWithProviders(
      <PlanChooser value={null} interval="monthly" onChange={noop} onIntervalChange={noop} journey="self-service" />,
      { locale: 'en' },
    )

    await screen.findByTestId('plan-starter')
    for (const state of ['plans-loading', 'plans-unavailable', 'plans-empty', 'plans-none-for-path']) {
      expect(screen.queryByTestId(state), `${state} was shown over a working catalogue`).not.toBeInTheDocument()
    }
  })
})
