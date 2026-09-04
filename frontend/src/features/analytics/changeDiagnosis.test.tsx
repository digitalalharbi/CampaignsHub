import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { ChangeDiagnosis } from './ChangeDiagnosis'
import { renderWithProviders } from '@/test/utils'
import type { Decomposition, DriversPayload } from './api'

/**
 * ANALYTICS-DIFFERENTIATION-001 — «a test that each analytical block declines when its evidence is
 * absent», which is the requirement's own acceptance criterion.
 *
 * Most of this file is about refusals, and that is the point. Analytics is a diagnostic surface: one
 * that always produces a finding teaches its reader to ignore it, and one that renders an empty
 * frame teaches them it is broken. Each block has to say WHICH absence it met, in words.
 */
const decomposition = (over: Partial<Decomposition> = {}): Decomposition => ({
  metric: 'spend',
  by: 'provider',
  decomposable: true,
  reason: null,
  current: 48_000,
  previous: 42_000,
  change: 6_000,
  change_pct: 0.1428,
  drivers: [
    { key: 'meta', name: 'meta', current: 30_000, previous: 24_000, change: 6_000, share: 0.75, direction: 'up' },
    { key: 'google', name: 'google', current: 18_000, previous: 20_000, change: -2_000, share: 0.25, direction: 'down' },
  ],
  unquantifiable: [],
  ...over,
})

const payload = (over: Partial<DriversPayload> = {}): DriversPayload => ({
  window: { from: '2026-08-01', to: '2026-08-30', days: 30 },
  previous: { from: '2026-07-02', to: '2026-07-31' },
  drivers: decomposition(),
  also: [],
  timeline: { points: [], reason: 'no_day_departed_from_its_own_baseline', days: 30 },
  ...over,
})

const render = (data: DriversPayload | undefined, locale: 'ar' | 'en' = 'en') =>
  renderWithProviders(<ChangeDiagnosis data={data} currency="SAR" />, { locale })

describe('the change diagnosis', () => {
  it('names the platform that moved the account, and by how much', () => {
    render(payload())

    const drivers = screen.getByTestId('drivers-spend')

    expect(drivers).toHaveTextContent('Meta')
    // Compact on the card, exact one hover away — NUMBER-PRESENTATION-001.
    expect(drivers).toHaveTextContent('+6K SAR')
    expect(screen.getByTitle('6,000 SAR')).toBeInTheDocument()
    // The one moving the other way is shown as such rather than dropped.
    expect(drivers).toHaveTextContent('−2K SAR')
  })

  /** The square signals answer one question each — «who», and «who is going the other way». */
  it('separates the biggest mover from the one moving against the account', () => {
    render(payload())

    expect(screen.getByTestId('signal-biggest-mover')).toHaveTextContent('Meta')
    expect(screen.getByTestId('signal-against')).toHaveTextContent('Google Ads')
  })

  /**
   * **A ratio has no parts that add to it**, and the card says so rather than showing an empty list.
   *
   * This is the refusal most likely to be quietly dropped in a redesign, because the arithmetic runs
   * happily and produces something that looks like an insight.
   */
  it('explains that a ratio cannot be decomposed', () => {
    render(payload({ drivers: decomposition({ decomposable: false, reason: 'metric_is_not_additive', drivers: [] }) }))

    expect(screen.getByTestId('drivers-declined-spend')).toHaveTextContent(/parts do not add up/i)
    expect(screen.queryByTestId('drivers-spend')).not.toBeInTheDocument()
  })

  it('explains that there is no previous period to have changed from', () => {
    render(payload({ drivers: decomposition({ reason: 'no_previous_period', drivers: [] }) }))

    expect(screen.getByTestId('drivers-declined-spend')).toHaveTextContent(/no previous period/i)
  })

  /**
   * A refusal stands alone — no headline figure above it.
   *
   * «Spend 0 SAR» printed over «there is no previous period» is a confident zero about an account
   * whose figure the product declined to state, arriving from a fallback rather than from a sum.
   */
  it('prints no figure beside a refusal', () => {
    const { container } = render(payload({ drivers: decomposition({ reason: 'no_previous_period', drivers: [] }) }))

    expect(container.textContent).not.toContain('0 SAR')
  })

  it('says when no day departed from the period’s own behaviour', () => {
    render(payload())

    expect(screen.getByTestId('timeline-declined')).toHaveTextContent(/nothing here to investigate/i)
    expect(screen.queryByTestId('change-timeline')).not.toBeInTheDocument()
  })

  it('says when the window is too short to have a baseline', () => {
    render(payload({ timeline: { points: [], reason: 'window_too_short_to_have_a_baseline', days: 3 } }))

    expect(screen.getByTestId('timeline-declined')).toHaveTextContent(/too short/i)
  })

  /** The timeline names the day, what it was, and what the days before it had been. */
  it('states each notable day against its own baseline', () => {
    render(
      payload({
        timeline: {
          points: [{ date: '2026-08-14', metric: 'spend', value: 9_000, baseline: 1_500, deviation: 7.2, direction: 'up' }],
          reason: null,
          days: 30,
        },
      }),
    )

    const timeline = screen.getByTestId('change-timeline')

    expect(timeline).toHaveTextContent('2026-08-14')
    expect(timeline).toHaveTextContent('9K SAR')
    expect(timeline).toHaveTextContent('1.5K SAR')
    // The day's own figure carries its full form.
    expect(screen.getByTitle('9,000 SAR')).toBeInTheDocument()
  })

  /**
   * The «nothing moved against it» card names the axis it counted.
   *
   * The same component renders on more than one dimension now, and it said «every PLATFORM moved the
   * same way» underneath a decomposition by OBJECTIVE — a sentence about the wrong thing, on the one
   * card whose whole job is to say what it looked at.
   */
  it('names the dimension it compared when nothing moved against the account', () => {
    const allUp = decomposition({
      by: 'objective',
      drivers: [
        { key: 'sales', name: 'sales', current: 30, previous: 24, change: 6, share: 0.6, direction: 'up' },
        { key: 'leads', name: 'leads', current: 18, previous: 14, change: 4, share: 0.4, direction: 'up' },
      ],
    })

    render(payload({ drivers: allUp }))

    expect(screen.getByTestId('signal-against')).toHaveTextContent('Every objective moved the same way')
    expect(screen.getByTestId('signal-against')).not.toHaveTextContent(/platform/i)
    // …and the drivers are named as objectives rather than by their database key.
    expect(screen.getByTestId('drivers-spend')).toHaveTextContent('Sales')
  })

  /**
   * A withheld platform is NAMED, never counted as zero — FX-001.
   *
   * Silently excluding it would hand its share of the movement to whichever platform happened to be
   * measurable, which is a false attribution rather than a missing one.
   */
  it('names the platforms whose figures could not be compared', () => {
    render(payload({ drivers: decomposition({ unquantifiable: ['snapchat'] }) }))

    expect(screen.getByTestId('drivers-unquantifiable')).toHaveTextContent(/snapchat/i)
    expect(screen.getByTestId('drivers-unquantifiable')).toHaveTextContent(/not zeros/i)
  })

  /**
   * A partial answer declines; it does not white-screen the page.
   *
   * An older server, a narrowed response or a mocked one carries some keys and not others, and a
   * diagnostic block that throws when its evidence is incomplete is the opposite of this requirement.
   */
  it('survives a payload missing everything but its window', () => {
    render({ window: { from: 'a', to: 'b', days: 1 } } as unknown as DriversPayload)

    expect(screen.getByTestId('change-diagnosis')).toBeInTheDocument()
    expect(screen.getByTestId('timeline-declined')).toBeInTheDocument()
  })

  it('shows a request that has not answered as loading, not as an absence', () => {
    renderWithProviders(<ChangeDiagnosis data={undefined} currency="SAR" loading />, { locale: 'en' })

    expect(screen.getByTestId('change-diagnosis')).toBeInTheDocument()
    expect(screen.queryByTestId('timeline-declined')).not.toBeInTheDocument()
  })

  /** A failed request is a failed request — never «nothing changed». */
  it('says the analysis could not be loaded when the request failed', () => {
    renderWithProviders(<ChangeDiagnosis data={undefined} currency="SAR" error />, { locale: 'en' })

    expect(screen.getByTestId('drivers-error')).toHaveTextContent(/could not be loaded/i)
  })

  /** Arabic reads in Arabic — the refusals are the copy most likely to be left in English. */
  it('states its refusals in Arabic', () => {
    render(payload({ drivers: decomposition({ reason: 'no_previous_period', drivers: [] }) }), 'ar')

    expect(screen.getByTestId('drivers-declined-spend')).toHaveTextContent(/لا توجد فترة سابقة/)
    expect(screen.getByTestId('timeline-declined')).toHaveTextContent(/لم يخرج أي يوم/)
  })
})

/**
 * ANALYTICS-DIFFERENTIATION-001 — the refusal names the axis it is actually about.
 *
 * «No platform reported this metric» was printed under a decomposition by ad set, by campaign, by
 * account and by objective as well: a statement about the wrong axis, on the one card whose entire
 * job is to explain an absence. Found by opening the ad-set tab, not by reading the file — the
 * string is keyed by the server's reason, so nothing tied it to the dimension.
 */
describe('a refusal names the dimension it refused about', () => {
  const declining = (by: string) => ({
    window: { from: '2026-08-06', to: '2026-09-04', days: 30 },
    previous: { from: '2026-07-07', to: '2026-08-05' },
    drivers: {
      metric: 'spend', by, decomposable: true, reason: 'no_entity_reported_this_metric',
      current: 0, previous: 0, change: 0, change_pct: null, drivers: [], unquantifiable: [],
    },
    also: [],
    timeline: { points: [], reason: null, days: 30 },
  })

  it('says «ad set» under a decomposition by ad set', () => {
    renderWithProviders(
      <ChangeDiagnosis data={declining('ad_set') as never} currency="SAR" loading={false} error={false} />,
      { locale: 'en' },
    )

    const note = screen.getByTestId('drivers-declined-spend')
    expect(note).toHaveTextContent(/No ad set reported this metric/)
    expect(note).not.toHaveTextContent(/platform/)
  })

  it('still says «platform» under a decomposition by platform', () => {
    renderWithProviders(
      <ChangeDiagnosis data={declining('provider') as never} currency="SAR" loading={false} error={false} />,
      { locale: 'en' },
    )

    expect(screen.getByTestId('drivers-declined-spend')).toHaveTextContent(/No platform reported this metric/)
  })

  it('names the axis in Arabic too, without leaving the placeholder behind', () => {
    renderWithProviders(
      <ChangeDiagnosis data={declining('campaign') as never} currency="SAR" loading={false} error={false} />,
      { locale: 'ar' },
    )

    const note = screen.getByTestId('drivers-declined-spend')
    expect(note).toHaveTextContent(/حملة/)
    expect(note).not.toHaveTextContent(/\{\{axis\}\}/)
  })
})
