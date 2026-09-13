import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import { ReportScopePicker } from './ReportScopePicker'
import type { ScopeOptions } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return {
    ...actual,
    scopeOptions: vi.fn(),
    listScopeTemplates: vi.fn(),
    explainScope: vi.fn(),
    searchScopeAxis: vi.fn(),
  }
})

import { explainScope, listScopeTemplates, scopeOptions, searchScopeAxis } from './api'

/**
 * REPORT-SCOPE-SELECTION-001 §B — the builder ORDERED by reportability and did not GROUP by it.
 *
 * ## The gap, once the stale clauses were cleared away
 *
 * Two of this row's three remaining clauses were checked and found stale: the scope IS stated in
 * words, and ad/ad-set selection DOES narrow on the server. What was genuinely left is smaller and
 * real. `orderByReportability` puts what ran in the report's own window first — deliberately not
 * through `orderByRelevance`, because a campaign completed in August may be the largest spender in
 * the July report being built — and a note above the control says the order means that.
 *
 * But nothing said where the split FELL. An operator scrolling 120 rows could not tell «did not run
 * in this period» from «further down the alphabet», which is the question the ordering exists to
 * answer. An order is only information if its boundary is visible.
 *
 * ## Three states, and the third is the one to get right
 *
 *   ran          `last_active_on` is a date in the asked window
 *   no activity  `last_active_on` is null AND a period was asked about
 *   no claim     no period was asked about, or the row came back from a SERVER SEARCH, which
 *                returns `{id, name}` and says nothing about activity
 *
 * The third must not be labelled «did not run». The server not having been asked is not an answer,
 * and printing one would be a claim the product cannot support — the same rule the ordering already
 * follows by returning the list untouched when no period is set.
 *
 * Grouping goes through `Option.group`, which `MultiSelectField` already renders as a header. A
 * second grouping mechanism in the picker would be a second opinion about what a heading is.
 */
const OPTIONS: ScopeOptions = {
  campaigns: [
    { id: 'c1', name: 'Ran Big', status: 'completed', objective: 'sales', last_active_on: '2026-07-20' },
    { id: 'c2', name: 'Ran Small', status: 'active', objective: 'sales', last_active_on: '2026-07-02' },
    { id: 'c3', name: 'Quiet One', status: 'active', objective: 'sales', last_active_on: null },
  ],
  providers: ['meta'],
  accounts: [{ id: 'a1', name: 'Meta Ads', provider: 'meta' }],
  ad_sets: [],
  ads: [],
  creatives: [],
  objectives: [{ key: 'sales', labels: { ar: 'المبيعات', en: 'Sales' }, path: 'conversion' }],
  paths: [{ key: 'conversion', labels: { ar: 'التحويل والمبيعات', en: 'Conversion & sales' }, headline_metrics: ['spend'] }],
  metrics: [{ key: 'spend', ar: 'الإنفاق', en: 'Spend' }],
  grain: { figures: ['providers'], resolved_to_campaign: ['ad_set_ids'], creatives_only: ['creative_ids'] },
} as unknown as ScopeOptions

const PERIOD = { from: '2026-07-01', to: '2026-07-31' }

/*
 * The control is addressed by its own testid, the way `scopeAxisSearch.test.tsx` addresses it.
 *
 * A first version of this helper hunted a combobox by `aria-label` across the whole picker, opened
 * nothing, and failed every case with «Unable to find Ran Big» — which reads exactly like the
 * product dropping the campaigns. A harness that cannot open the control cannot report anything
 * about what is inside it.
 */
const open = async (axis: string) => {
  const box = within(await screen.findByTestId(`scope-select-${axis}`))
  fireEvent.click(box.getAllByRole('combobox')[0])

  return box
}

const LOCALES = [
  // The control's testid carries its own LABEL, which is localized — so the axis name is per locale.
  { locale: 'ar' as const, axis: 'الحملات', ran: /عملت في هذه الفترة/, quiet: /لم تُسجّل نشاطًا في هذه الفترة/ },
  { locale: 'en' as const, axis: 'Campaigns', ran: /Ran in this period/i, quiet: /No activity recorded in this period/i },
]

describe.each(LOCALES)('the builder groups campaigns by what ran in the window ($locale)', ({ locale, axis, ran, quiet }) => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(scopeOptions).mockResolvedValue(OPTIONS)
    vi.mocked(listScopeTemplates).mockResolvedValue([] as never)
    vi.mocked(explainScope).mockResolvedValue({ scope: {}, bound_axes: [], explain: [] } as never)
    vi.mocked(searchScopeAxis).mockResolvedValue({} as never)
  })

  const render = (value: Record<string, unknown> = PERIOD) =>
    renderWithProviders(
      <ReportScopePicker projectId="p1" value={value as never} onChange={vi.fn()} audience="internal" />,
      { locale },
    )

  it('names the group that ran, and the group that did not', async () => {
    render()
    await open(axis)

    expect(await screen.findByText('Ran Big')).toBeInTheDocument()
    expect(screen.getByText(ran), 'no heading marks what ran in the report’s window').toBeInTheDocument()
    expect(screen.getByText(quiet), 'no heading marks what recorded no activity').toBeInTheDocument()
  })

  /** Membership is never decided by the heading — nothing is hidden, which the ordering promised too. */
  it('hides nothing: every campaign is still offered', async () => {
    render()
    await open(axis)

    for (const name of ['Ran Big', 'Ran Small', 'Quiet One']) {
      expect(await screen.findByText(name), `«${name}» was dropped by the grouping`).toBeInTheDocument()
    }
  })

  /**
   * No period asked about, so no claim made — the rule `orderByReportability` already follows by
   * returning the list untouched.
   */
  it('makes no claim when no period was chosen', async () => {
    render({})
    await open(axis)

    expect(await screen.findByText('Quiet One')).toBeInTheDocument()
    expect(screen.queryByText(ran), 'a heading claimed a window nobody asked about').toBeNull()
    expect(screen.queryByText(quiet), 'a heading claimed no activity in a window nobody asked about').toBeNull()
  })
})
