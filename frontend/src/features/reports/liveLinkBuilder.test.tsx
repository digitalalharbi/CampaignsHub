import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { LiveLinkBuilder } from './LiveLinkBuilder'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./api')>()),
  liveBuilderOptions: vi.fn(),
  createLiveLink: vi.fn(),
}))

import { createLiveLink, liveBuilderOptions } from './api'

/**
 * REPORT-CREATION-UX-001 — the choices reach the link, and their consequence is visible first.
 *
 * Three settings this builder never sent, each of which the endpoint had accepted since it shipped:
 *
 *   `form`           — «the dashboard» and «the dashboard with every campaign and platform beneath
 *                      it» are two different documents to send a client, and which one they got was
 *                      decided by a default nobody chose;
 *   `hide_revenue`   — spend could be withheld and revenue could not;
 *   `allow_download` — every link was created non-downloadable.
 *
 * A control that does not reach the payload is worse than a missing one: the operator believes they
 * chose. So these tests assert what was SENT, not what was rendered.
 */
const OPTIONS = {
  campaigns: [{ id: 'c-1', name: 'Eid', status: 'active', last_active_on: '2026-08-20', objective: 'sales' }],
  providers: ['meta'],
  metrics: [{ key: 'spend', ar: 'الإنفاق', en: 'Spend' }],
}

describe('building a live client link', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['reports.view', 'reports.create'])
    vi.mocked(liveBuilderOptions).mockResolvedValue(OPTIONS as never)
    vi.mocked(createLiveLink).mockResolvedValue({
      report_id: 'r-1', share_id: 's-1', url: 'https://campaignshub.io/r/tok', token: 'tok',
    } as never)
  })
  afterEach(() => signOut())

  const build = async () => {
    renderWithProviders(<LiveLinkBuilder projectId="p-1" onClose={() => {}} />, { locale: 'en' })
    await screen.findByTestId('live-link-summary')
    fireEvent.change(screen.getByTestId("live-link-name"), { target: { value: "Client link" } })
  }

  const sent = () => vi.mocked(createLiveLink).mock.calls.at(-1)?.[1] as Record<string, unknown>

  it('sends the form the operator chose, not a default', async () => {
    await build()

    fireEvent.click(screen.getByTestId('live-link-form-detailed'))
    fireEvent.click(screen.getByTestId('live-link-create'))

    await waitFor(() => expect(createLiveLink).toHaveBeenCalled())
    expect(sent().form).toBe('detailed')
  })

  /** The smaller promise is the safe default: what is shown too little gets asked about; too much cannot be taken back. */
  it('defaults to the summary rather than to the fuller document', async () => {
    await build()

    fireEvent.click(screen.getByTestId('live-link-create'))

    await waitFor(() => expect(createLiveLink).toHaveBeenCalled())
    expect(sent().form).toBe('executive_summary')
  })

  it('sends revenue and download, which no control used to reach', async () => {
    await build()

    fireEvent.click(screen.getByTestId('live-link-hide-revenue'))
    fireEvent.click(screen.getByTestId('live-link-allow-download'))
    fireEvent.click(screen.getByTestId('live-link-create'))

    await waitFor(() => expect(createLiveLink).toHaveBeenCalled())
    expect(sent().hide_revenue).toBe(true)
    expect(sent().allow_download).toBe(true)
  })

  /**
   * The consequence, before saving.
   *
   * A summary that lists selections tells an operator what they clicked. What they are deciding is
   * what a client will be able to see, and «hide spend» ticked and forgotten is how a client is sent
   * a report with a hole in it.
   */
  it('says what the client will get, including what is withheld', async () => {
    await build()

    fireEvent.click(screen.getByTestId('live-link-form-detailed'))
    fireEvent.click(screen.getByTestId('live-link-hide-spend'))

    const summary = screen.getByTestId('live-link-summary')

    expect(summary).toHaveTextContent('Detailed report')
    expect(summary).toHaveTextContent('without spend')
  })
})
