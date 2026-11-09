import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { AttentionBoard } from './AttentionBoard'
import { AlertDecisionDrawer } from './AlertDecisionDrawer'
import { renderWithProviders } from '@/test/utils'
import { useAuth } from '@/stores/auth'

vi.mock('./api', async (orig) => ({
  ...(await orig<Record<string, unknown>>()),
  getAttentionBoard: vi.fn(), getAlertDetail: vi.fn(), decideAlert: vi.fn(), assignAlert: vi.fn(), noteAlert: vi.fn(),
}))

import { assignAlert, decideAlert, getAlertDetail, getAttentionBoard } from './api'

const bi = (ar: string, en: string) => ({ ar, en })
const item = (over: Record<string, unknown> = {}) => ({
  id: 'e1', priority: 'P1', severity: 'warning', type: 'cpa_increase', problem: bi('تكلفة النتيجة ارتفعت', 'CPA rose'), numbers: bi('قبل 30 ← الآن 50', 'Before 30 → Now 50'),
  impact: bi('تدفع أكثر لكل نتيجة', 'Pays more per result'), action: bi('راجع المحتوى', 'Review content'), insufficient: false, owner: null, evidence: { metric: 'cpa', before: 30, now: 50, threshold: 25, threshold_unit: '%' }, automation: { state: 'awaiting_approval', proposals: [] },
  decision_status: 'new', project_id: 'p1', campaign_id: 'c1', campaign_name: 'Ramadan', platforms: ['meta'], level: 'campaign', path: '/app/campaigns/p1/c1', ...over,
})
const board = (over: Record<string, unknown> = {}) => ({
  platforms: ['meta', 'tiktok'],
  rows: [
    { campaign_id: 'c1', project_id: 'p1', name: 'Ramadan', cells: { meta: { status: 'problem', last_data: '2026-11-08', alert_ids: ['e1'] }, tiktok: { status: 'no_data', last_data: null, alert_ids: [] } } },
    { campaign_id: 'c2', project_id: 'p1', name: 'Fine', cells: { meta: { status: 'healthy', last_data: '2026-11-08', alert_ids: [] }, tiktok: { status: 'healthy', last_data: '2026-11-08', alert_ids: [] } } },
  ],
  counts: { problem: 1, no_data: 1, watch: 0, healthy: 2 }, cells_total: 4, coverage: { total: 3, checked: 2 }, unchecked: [{ id: 'p9', name: 'Quiet project', reason: 'no_active_campaign_with_platform' }], projects: [], rows_total: 2, unplaced: 0, truncated: false,
  needs_decision: [item()], needs_decision_total: 1, healthy_count: 2, ...over,
})

describe('the exception-first board', () => {
  beforeEach(() => { vi.clearAllMocks() })

  it('lists only exceptions, collapses healthy rows into a count and never paints no-data green', async () => {
    vi.mocked(getAttentionBoard).mockResolvedValue(board() as never)
    const onOpen = vi.fn()
    renderWithProviders(<AttentionBoard locale="ar" projectId={null} onOpen={onOpen} />, { locale: 'ar' })

    const row = await screen.findByTestId('alert-board-item-e1')
    expect(row).toHaveAttribute('data-priority', 'P1')
    expect(row).toHaveTextContent('تكلفة النتيجة ارتفعت')
    expect(row).toHaveTextContent('راجع المحتوى')
    expect(screen.getByTestId('board-cell-c1-meta')).toHaveAttribute('data-status', 'problem')
    expect(screen.getByTestId('board-cell-c1-tiktok')).toHaveAttribute('aria-label', expect.stringContaining('لا بيانات'))
    expect(screen.getByTestId('board-cell-c1-tiktok').className).not.toMatch(/success/)
    // The all-healthy campaign is collapsed, with its count, until asked for.
    expect(screen.queryByTestId('board-cell-c2-meta')).toBeNull()
    expect(screen.getByTestId('alert-matrix-toggle')).toHaveTextContent('+1')
    fireEvent.click(screen.getByTestId('alert-matrix-toggle'))
    expect(screen.getByTestId('board-cell-c2-meta')).toHaveAttribute('data-status', 'healthy')
    // The inventory: coverage as a count, and a project nobody could check is NAMED, not silently absent.
    expect(screen.getByTestId('alert-board-coverage')).toHaveTextContent('2/3')
    expect(screen.getByTestId('alert-board-unchecked')).toHaveTextContent('Quiet project')
    expect(row).toHaveAttribute('data-automation', 'awaiting_approval')
    expect(screen.getByTestId('alert-auto-e1')).toHaveTextContent('ينتظر موافقتك')

    fireEvent.click(screen.getByTestId('alert-open-drawer-e1'))
    expect(onOpen).toHaveBeenCalledWith('e1')
  })

  it('says «insufficient data» instead of recommending when the evidence is thin', async () => {
    vi.mocked(getAttentionBoard).mockResolvedValue(board({ needs_decision: [item({ insufficient: true, priority: 'P3', action: null, impact: null })] }) as never)
    renderWithProviders(<AttentionBoard locale="ar" projectId={null} onOpen={vi.fn()} />, { locale: 'ar' })

    expect(await screen.findByTestId('alert-board-thin-e1')).toHaveAttribute('title', 'بيانات غير كافية')
    expect(screen.queryByText('راجع المحتوى')).toBeNull()
  })

  it('filters by priority and platform', async () => {
    vi.mocked(getAttentionBoard).mockResolvedValue(board({ needs_decision: [item(), item({ id: 'e2', priority: 'P3', platforms: ['tiktok'] })], needs_decision_total: 2 }) as never)
    renderWithProviders(<AttentionBoard locale="ar" projectId={null} onOpen={vi.fn()} />, { locale: 'ar' })
    await screen.findByTestId('alert-board-item-e2')
    fireEvent.click(screen.getByTestId('alert-filter-P1'))
    expect(screen.queryByTestId('alert-board-item-e2')).toBeNull()
    expect(screen.getByTestId('alert-board-item-e1')).toBeInTheDocument()
  })

  it('opens the alert from a tapped cell and starts work in one tap', async () => {
    vi.mocked(getAttentionBoard).mockResolvedValue(board() as never)
    vi.mocked(decideAlert).mockResolvedValue({} as never)
    const onOpen = vi.fn()
    renderWithProviders(<AttentionBoard locale="ar" projectId={null} onOpen={onOpen} />, { locale: 'ar' })
    fireEvent.click(await screen.findByTestId('board-cell-c1-meta'))
    expect(onOpen).toHaveBeenCalledWith('e1')
    fireEvent.click(screen.getByTestId('alert-start-e1'))
    await waitFor(() => expect(decideAlert).toHaveBeenCalledWith('e1', { status: 'in_progress' }))
  })

  it('renders nothing when the board cannot be read', async () => {
    vi.mocked(getAttentionBoard).mockRejectedValue(new Error('down'))
    const { container } = renderWithProviders(<AttentionBoard locale="en" projectId={null} onOpen={vi.fn()} />, { locale: 'en' })
    await waitFor(() => expect(getAttentionBoard).toHaveBeenCalled())
    expect(container.querySelector('[data-testid="alert-board"]')).toBeNull()
  })
})

const detail = (over: Record<string, unknown> = {}) => ({
  event: { id: 'e1', priority: 'P1', decision_status: 'new', anatomy: { problem: bi('تكلفة النتيجة ارتفعت', 'CPA rose'), numbers: bi('قبل 30 ← الآن 50', ''), impact: bi('تدفع أكثر', ''), action: bi('راجع المحتوى', ''), insufficient_note: null },
    evidence: { metric: 'cpa', window_days: 7, before: 30, now: 50, threshold: 25, threshold_unit: '%', completeness: 'complete', sufficient: true, missing: [] } },
  owner: null, log: [], impact: { state: 'none' }, assignees: [{ id: 7, name: 'Sara' }], action_tags: ['pause_ad', 'other'], ...over,
})

describe('the decision drawer', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useAuth.setState({ hasPermission: () => true } as never)
  })

  it('shows the five fields, then records «done» with an action tag', async () => {
    vi.mocked(getAlertDetail).mockResolvedValue(detail() as never)
    vi.mocked(decideAlert).mockResolvedValue({} as never)
    renderWithProviders(<AlertDecisionDrawer id="e1" locale="ar" onClose={vi.fn()} />, { locale: 'ar' })

    expect(await screen.findByTestId('drawer-field-evidence')).toHaveTextContent('قبل 30')
    for (const f of ['impact', 'action', 'owner']) expect(screen.getByTestId(`drawer-field-${f}`)).toBeInTheDocument()
    expect(screen.getByTestId('drawer-priority')).toHaveTextContent('P1')

    fireEvent.click(screen.getByTestId('drawer-status-done'))
    fireEvent.change(screen.getByTestId('drawer-action-tag'), { target: { value: 'pause_ad' } })
    fireEvent.click(screen.getByTestId('drawer-save'))
    await waitFor(() => expect(decideAlert).toHaveBeenCalledWith('e1', { status: 'done', reason: undefined, action_tag: 'pause_ad' }))
  })

  it('will not save a dismissal without a reason', async () => {
    vi.mocked(getAlertDetail).mockResolvedValue(detail() as never)
    renderWithProviders(<AlertDecisionDrawer id="e1" locale="ar" onClose={vi.fn()} />, { locale: 'ar' })
    fireEvent.click(await screen.findByTestId('drawer-status-dismissed'))
    expect(screen.getByTestId('drawer-save')).toBeDisabled()
    fireEvent.change(screen.getByTestId('drawer-reason'), { target: { value: 'موسم متوقع' } })
    expect(screen.getByTestId('drawer-save')).toBeEnabled()
  })

  it('assigns an owner from the members the server offered', async () => {
    vi.mocked(getAlertDetail).mockResolvedValue(detail() as never)
    vi.mocked(assignAlert).mockResolvedValue({} as never)
    renderWithProviders(<AlertDecisionDrawer id="e1" locale="en" onClose={vi.fn()} />, { locale: 'en' })
    fireEvent.change(await screen.findByTestId('drawer-owner'), { target: { value: '7' } })
    await waitFor(() => expect(assignAlert).toHaveBeenCalledWith('e1', 7))
  })

  it('states an incomplete impact window honestly and shows no recommendation for thin evidence', async () => {
    const d = detail({ impact: { state: 'insufficient_data', reason: 'window_incomplete', days_remaining: 3, before: 100, action_tag: 'pause_ad' } })
    ;(d.event.evidence as { sufficient: boolean }).sufficient = false
    vi.mocked(getAlertDetail).mockResolvedValue(d as never)
    renderWithProviders(<AlertDecisionDrawer id="e1" locale="ar" onClose={vi.fn()} />, { locale: 'ar' })

    const impact = await screen.findByTestId('drawer-impact')
    expect(impact).toHaveAttribute('data-state', 'insufficient_data')
    expect(impact).toHaveTextContent('لم تكتمل فترة القياس بعد')
    expect(screen.getByTestId('drawer-field-thin')).toHaveTextContent('بيانات غير كافية')
    expect(screen.queryByTestId('drawer-field-action')).toBeNull()
  })

  it('shows the measured change once the window has completed', async () => {
    vi.mocked(getAlertDetail).mockResolvedValue(detail({ impact: { state: 'computed', before: 100, after: 35, change_pct: -65, verdict: 'improved', action_tag: 'pause_ad' } }) as never)
    renderWithProviders(<AlertDecisionDrawer id="e1" locale="ar" onClose={vi.fn()} />, { locale: 'ar' })
    const impact = await screen.findByTestId('drawer-impact')
    expect(impact).toHaveTextContent('-65%')
    expect(impact).toHaveTextContent('تحسّن')
  })
})
