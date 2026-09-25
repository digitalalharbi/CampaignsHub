import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, within } from '@testing-library/react'
import { PortfolioPage } from './PortfolioPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

/**
 * PORTFOLIO-SCOPE-001 §13 §14 — «جميع المشاريع» on screen, and never mistakable for one project.
 *
 * ## What this page is for
 *
 * The agency question, asked deliberately: how many clients are running, which of them need
 * somebody today, and how much is being spent — as opposed to the project question, which is about
 * one client. The two are different scopes and the middle state, where a surface widens to every
 * project because nobody chose one, is the thing the backend already refuses to produce.
 *
 * ## What is pinned here
 *
 * The scope must be VISIBLE. A number on a screen that does not say which scope produced it is a
 * number a reader will attribute to whatever they had in mind, and on this page that is usually one
 * client. So the heading says «جميع المشاريع» and the test asserts it rather than trusting it.
 *
 * And the money must not be added up across currencies. The server refuses to offer a total — there
 * is no `total` key to read — so the page has nothing to print, and this test holds the page to the
 * same rule by checking each currency is stated on its own with its project count.
 */

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, fetchPortfolioOverview: vi.fn() }
})

import { fetchPortfolioOverview } from './api'

const overview = (over: Record<string, unknown> = {}) => ({
  scope: 'portfolio',
  period: { from: '2026-08-27', to: '2026-09-25' },
  projects: {
    total: 3,
    by_status: { active: 2, paused: 1 },
    items: [
      { id: 'p1', name: 'رزة أفينيو', status: 'active', client_workspace_id: 'w1', accounts: 2, providers: ['snapchat'], data_last_synced_at: new Date().toISOString(), attention: null },
      { id: 'p2', name: 'عميل ثانٍ', status: 'active', client_workspace_id: 'w2', accounts: 0, providers: [], data_last_synced_at: null, attention: 'no_accounts' },
      { id: 'p3', name: 'عميل ثالث', status: 'paused', client_workspace_id: 'w3', accounts: 1, providers: ['meta'], data_last_synced_at: null, attention: 'never_synced' },
    ],
  },
  spend: {
    by_currency: [
      { currency: 'SAR', spend: 12500, projects: 2 },
      { currency: 'USD', spend: 400, projects: 1 },
    ],
    comparable: false,
  },
  attention: { total: 2, by_state: { no_accounts: 1, never_synced: 1 } },
  ...over,
})

describe('the portfolio page', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['projects.view'])
    vi.mocked(fetchPortfolioOverview).mockResolvedValue(overview() as never)
  })
  afterEach(() => signOut())

  /** **The scope says itself.** A figure with no scope is a figure a reader misattributes. */
  it('names its scope on the page', async () => {
    renderWithProviders(<PortfolioPage />, { locale: 'ar' })

    expect(await screen.findByTestId('portfolio-scope')).toHaveTextContent('جميع المشاريع')
  })

  it('counts the projects and the ones needing somebody', async () => {
    renderWithProviders(<PortfolioPage />, { locale: 'ar' })

    expect(await screen.findByTestId('portfolio-projects-total')).toHaveTextContent('3')
    expect(screen.getByTestId('portfolio-attention-total')).toHaveTextContent('2')
  })

  /**
   * Two currencies are two figures. 12,500 SAR beside 400 USD is not 12,900 of anything, and the
   * page says so rather than leaving a reader to assume the first number is the total.
   */
  it('states each currency on its own and refuses a single total', async () => {
    renderWithProviders(<PortfolioPage />, { locale: 'ar' })

    const spend = await screen.findByTestId('portfolio-spend')
    expect(within(spend).getByTestId('portfolio-spend-SAR')).toHaveTextContent('12,500')
    expect(within(spend).getByTestId('portfolio-spend-USD')).toHaveTextContent('400')
    expect(spend.textContent).not.toContain('12,900')
    expect(screen.getByTestId('portfolio-not-comparable')).toBeInTheDocument()
  })

  /** One currency across the estate IS addable, and then the caveat has no business appearing. */
  it('drops the caveat when the estate reports in one currency', async () => {
    vi.mocked(fetchPortfolioOverview).mockResolvedValue(overview({
      spend: { by_currency: [{ currency: 'SAR', spend: 12500, projects: 3 }], comparable: true },
    }) as never)

    renderWithProviders(<PortfolioPage />, { locale: 'ar' })

    await screen.findByTestId('portfolio-spend')
    expect(screen.queryByTestId('portfolio-not-comparable')).not.toBeInTheDocument()
  })

  /** Each project is named with what it needs, and links into its own scope. */
  it('lists the projects with their attention state', async () => {
    renderWithProviders(<PortfolioPage />, { locale: 'ar', route: '/app/portfolio' })

    const row = await screen.findByTestId('portfolio-project-p2')
    expect(row.textContent).toContain('عميل ثانٍ')
    expect(row.textContent).toContain('لا حسابات مربوطة')
    expect(within(row).getByRole('link')).toHaveAttribute('href', '/app/projects/p2/integrations')
  })

  /**
   * An empty portfolio is a real answer and says which one it is.
   *
   * A reader who may reach nothing must not meet a blank page that looks like a loading failure —
   * the server distinguishes «no reachable projects» from «error», and so does this.
   */
  it('says the reader reaches no projects rather than drawing an empty shell', async () => {
    vi.mocked(fetchPortfolioOverview).mockResolvedValue(overview({
      projects: { total: 0, by_status: {}, items: [] },
      spend: { by_currency: [], comparable: true },
      attention: { total: 0, by_state: {} },
    }) as never)

    renderWithProviders(<PortfolioPage />, { locale: 'ar' })

    expect(await screen.findByTestId('portfolio-empty')).toBeInTheDocument()
  })
})
