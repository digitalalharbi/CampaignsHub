import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, within } from '@testing-library/react'
import { AttentionBlocks } from './AttentionBlocks'
import type { AttentionItem } from './attention'

/**
 * REPORT-RECOMMENDATION-BLOCKS-001 — «what needs attention» is a block of figures, not a paragraph.
 *
 * The component draws what the server sent and nothing else: the client cut and the section switch are
 * the server's (tested in `ReportAttentionSurfacesTest`). What is proved here is the shape a reader
 * meets — severity, problem or opportunity, platform, before/now, one action, the drill-down — and
 * that an empty list leaves no trace on the page.
 */
const item = (over: Partial<AttentionItem> = {}): AttentionItem => ({
  key: 'a1b2c3d4e5f60718',
  code: 'cpl_rise',
  severity: 'critical',
  nature: 'problem',
  family: 'leads',
  family_label_ar: 'العملاء المحتملون',
  family_label_en: 'Leads',
  platform: 'meta',
  kpis: [
    { key: 'cpl', kind: 'money', before: 40, current: 60, change: 0.5, currency: 'SAR', higher_is_better: false, primary: true },
  ],
  action: 'review_cost_drivers',
  impact: { kind: 'extra_cost_vs_previous_rate', amount: 1000, currency: 'SAR' },
  evidence: { platform: 'meta', content: [{ name: 'Spring video', content_key: 'ck-1' }] },
  ...over,
})

describe('attention blocks', () => {
  it('renders nothing at all when no finding qualified', () => {
    const { container } = render(<AttentionBlocks items={[]} ar={false} />)
    expect(container).toBeEmptyDOMElement()

    const { container: nulled } = render(<AttentionBlocks items={null} ar={false} />)
    expect(nulled).toBeEmptyDOMElement()
  })

  it('shows severity, nature, platform, before and now, the impact and ONE action', () => {
    render(<AttentionBlocks items={[item()]} ar={false} />)
    const block = screen.getByTestId('attention-a1b2c3d4e5f60718')

    expect(within(block).getByTestId('attention-severity')).toHaveTextContent('Critical')
    expect(within(block).getByTestId('attention-nature')).toHaveTextContent('Problem')
    expect(within(block).getByTestId('attention-subject')).toHaveTextContent('Meta')
    expect(within(block).getByTestId('attention-subject')).toHaveTextContent('Leads')
    expect(within(block).getByTestId('kpi-current')).toHaveTextContent('60')
    expect(within(block).getByTestId('kpi-before')).toHaveTextContent('40')
    expect(within(block).getByTestId('attention-impact')).toHaveTextContent('1,000')
    expect(within(block).getAllByTestId('attention-action')).toHaveLength(1)
    // The client words never name a campaign or a bid.
    expect(block).not.toHaveTextContent(/campaign|bid/i)
  })

  it('omits the impact where the server stated none', () => {
    render(<AttentionBlocks items={[item({ impact: null, code: 'roas_drop' })]} ar={false} />)
    expect(screen.queryByTestId('attention-impact')).toBeNull()
  })

  it('drills down platform → content, and opens content the page already holds', () => {
    const open = vi.fn()
    render(<AttentionBlocks items={[item()]} ar={false} onOpenContent={open} />)

    fireEvent.click(screen.getByTestId('attention-evidence-toggle'))
    fireEvent.click(screen.getByRole('button', { name: 'Spring video' }))

    expect(open).toHaveBeenCalledWith('ck-1')
  })

  it('offers approve and hide only on the operator screen', () => {
    const { rerender } = render(<AttentionBlocks items={[item()]} ar={false} />)
    expect(screen.queryByTestId('attention-decision')).toBeNull()

    const decide = vi.fn()
    rerender(<AttentionBlocks items={[item({ audience: 'operator', action: 'review_bidding', decision: null })]} ar={false} onDecide={decide} />)
    expect(screen.getByTestId('attention-operator-only')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /Approve for client/ }))
    expect(decide).toHaveBeenCalledWith(expect.objectContaining({ key: 'a1b2c3d4e5f60718' }), 'approved')
  })
})
