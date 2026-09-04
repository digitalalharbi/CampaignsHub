import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { ClientAttention } from './ClientAttention'
import type { LivePayload } from './api'

/**
 * CLIENT-FACING-PRESENTATION-001 — the sixth question, which the client link never answered.
 *
 * The report told a client what was spent, what was achieved, at what cost, what moved and where —
 * and then stopped. «What needs attention» is the one a client ACTS on, and leaving it out leaves
 * them to work out which figure is a problem, which is the work they were paying somebody else to do.
 *
 * Everything asserted here is a restatement of a number already on the page. An action with no
 * figure behind it is advice, and advice a client cannot check is advice they are right to ignore.
 */
const row = (over: Partial<NonNullable<LivePayload['budget']>[number]> = {}) => ({
  provider: 'meta',
  budget: 10000,
  budget_currency: null,
  spent: 5000,
  spent_currency: null,
  remaining: 5000,
  consumed_pct: 0.5,
  pace: 1.0,
  projected_spend: 10000,
  ...over,
})

const payload = (budget: unknown[]): LivePayload => ({ budget } as unknown as LivePayload)

describe('what a client is told needs attention', () => {
  it('states the budget against the spend, per platform', () => {
    render(<ClientAttention payload={payload([row()])} currency="SAR" locale="en" />)

    const table = screen.getByRole('table')

    expect(table).toHaveTextContent('Meta')
    expect(table).toHaveTextContent('SAR')
  })

  /**
   * CLIENT-REPORT-ENTITY-BOUNDARY-001 — the block that used to name campaigns names platforms.
   *
   * The payload no longer carries a campaign name to print, so this asserts the shape a reader sees:
   * a «Platform» heading rather than a «Campaign» one, and a value that is the channel. A component
   * that fell back to printing an id would satisfy the heading and fail this.
   */
  it('heads the column with the platform, never the campaign', () => {
    render(<ClientAttention payload={payload([row()])} currency="SAR" locale="en" />)

    const table = screen.getByRole('table')

    expect(table).toHaveTextContent('Platform')
    expect(table).not.toHaveTextContent('Campaign')
  })

  /** Ahead of plan is the finding a client can act on before the money is gone. */
  it('names a platform spending ahead of its plan', () => {
    render(
      <ClientAttention payload={payload([row({ spent: 14000, pace: 1.4 })])} currency="SAR" locale="en" />,
    )

    const warning = screen.getByTestId('live-attention-warning')

    expect(warning).toHaveTextContent('Meta')
    expect(warning, 'the finding must carry the figure it came from').toHaveTextContent('1.40')
  })

  it('names a platform that will not use its budget', () => {
    render(
      <ClientAttention payload={payload([row({ spent: 4000, pace: 0.4 })])} currency="SAR" locale="en" />,
    )

    expect(screen.getByTestId('live-attention-muted')).toHaveTextContent('behind plan')
  })

  /**
   * «Nothing needs you» is a RESULT.
   *
   * A section that renders empty teaches a reader it is decorative, and they stop looking at it on
   * the day it has something to say.
   */
  it('says plainly when nothing needs a decision', () => {
    render(<ClientAttention payload={payload([row({ pace: 1.0 })])} currency="SAR" locale="en" />)

    expect(screen.getByTestId('live-attention-clear')).toBeInTheDocument()
    expect(screen.queryByTestId('live-attention-warning')).toBeNull()
  })

  /**
   * **A list that flags everything flags nothing.**
   *
   * Fifteen rows produced fourteen findings on the first build — every small one a few hundred off
   * plan got its own sentence, and the ones that mattered were buried in them. The bar is the MONEY
   * at stake against the whole plan, not the ratio: a platform at half its pace on a small budget is
   * noise beside one at ninety per cent of a large one, and the ratio cannot tell them apart.
   */
  it('leaves out a platform whose drift is not worth a decision', () => {
    render(
      <ClientAttention
        payload={payload([
          row({ provider: 'meta', budget: 100000, spent: 40000, pace: 0.4 }),
          row({ provider: 'snapchat', budget: 900, spent: 300, pace: 0.33 }),
        ])}
        currency="SAR"
        locale="en"
      />,
    )

    const findings = screen.getByTestId('live-attention-findings')

    expect(findings).toHaveTextContent('Meta')
    expect(findings, 'a 600-riyal drift is not a client decision').not.toHaveTextContent('Snapchat')
  })

  /** What does not fit the short list is COUNTED, never dropped silently. */
  it('counts the findings it did not print', () => {
    const off = ['meta', 'google', 'tiktok', 'snapchat', 'linkedin', 'x', 'pinterest', 'reddit'].map((p) =>
      row({ provider: p, budget: 10000, spent: 3000, pace: 0.3 }),
    )

    render(<ClientAttention payload={payload(off)} currency="SAR" locale="en" />)

    expect(screen.getAllByTestId('live-attention-muted')).toHaveLength(5)
    expect(screen.getByTestId('live-attention-rest')).toHaveTextContent('3 more')
  })

  /** The most money at stake is read FIRST — a client reads the top of a list. */
  it('puts the most money at stake first', () => {
    render(
      <ClientAttention
        payload={payload([
          row({ provider: 'snapchat', budget: 20000, spent: 8000, pace: 0.4 }),
          row({ provider: 'meta', budget: 60000, spent: 10000, pace: 0.17 }),
        ])}
        currency="SAR"
        locale="en"
      />,
    )

    expect(screen.getAllByTestId('live-attention-muted')[0]).toHaveTextContent('Meta')
  })

  /**
   * **The money contract reaches the findings.**
   *
   * `pace` is null wherever the product refused to compare the money — a withheld spend, a
   * mixed-currency scope. Producing «this platform is overspending» from a figure the product would
   * not print is the fabrication the contract exists to prevent, and a client has no way to catch it.
   */
  it('produces no finding from money the product would not state', () => {
    render(
      <ClientAttention
        payload={payload([row({ pace: null, spent: null, consumed_pct: null })])}
        currency="SAR"
        locale="en"
      />,
    )

    expect(screen.queryByTestId('live-attention-warning')).toBeNull()
    expect(screen.getByTestId('live-attention-clear')).toBeInTheDocument()
  })

  /** A link that hides spend gets no budget block at all, rather than a row of dashes. */
  it('renders nothing when the link carries no budget', () => {
    const { container } = render(<ClientAttention payload={payload([])} currency="SAR" locale="en" />)

    expect(container.textContent).toBe('')
  })

  /**
   * The row's OWN unit wins over the report's.
   *
   * A report record carries a currency that is not always the one the money was summed in — rows
   * normalised before the canonical basis changed still describe themselves as they were stored — and
   * taking the report's word for it labels a client's money in a currency it is not.
   */
  it('states the unit the figures are actually in', () => {
    render(
      <ClientAttention
        payload={payload([row({ budget_currency: 'SAR', spent_currency: 'SAR' })])}
        currency="USD"
        locale="en"
      />,
    )

    const table = screen.getByRole('table')

    expect(table).toHaveTextContent('SAR')
    expect(table, 'the report’s currency labelled figures that are not in it').not.toHaveTextContent('USD')
  })

  /** One column cannot be two units, and guessing which is the mislabel this avoids. */
  it('prints bare figures when the rows disagree on their unit', () => {
    render(
      <ClientAttention
        payload={payload([
          row({ provider: 'meta', budget_currency: 'SAR' }),
          row({ provider: 'snapchat', budget_currency: 'AED' }),
        ])}
        currency="SAR"
        locale="en"
      />,
    )

    const table = screen.getByRole('table')

    expect(table).not.toHaveTextContent('SAR')
    expect(table).not.toHaveTextContent('AED')
    expect(table, 'the figures themselves must survive').toHaveTextContent('Snapchat')
  })

  /** A platform with no budget set is not a platform at 0% of its plan. */
  it('leaves out a platform whose budget nobody set', () => {
    const { container } = render(
      <ClientAttention payload={payload([row({ budget: null })])} currency="SAR" locale="en" />,
    )

    expect(container.textContent).toBe('')
  })
})
