import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { IntakePanel } from './IntakePanel'
import { FollowUpPanel } from './FollowUpPanel'
import { DriveSyncPanel } from './DriveSyncPanel'
import type { IntakeItem } from './api'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return { ...actual, listIntake: vi.fn(), getIntake: vi.fn(), transitionIntake: vi.fn(), createIntake: vi.fn(), recheckIntake: vi.fn(), getFollowUp: vi.fn(), syncDriveLink: vi.fn() }
})
vi.mock('@/features/drive/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/features/drive/api')>()
  return { ...actual, listDriveLinks: vi.fn() }
})
vi.mock('@/features/projects/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/features/projects/api')>()
  return { ...actual, listProjects: vi.fn().mockResolvedValue([{ id: 'p1', name: 'Project One' }]) }
})

import { getFollowUp, getIntake, listIntake, syncDriveLink, transitionIntake } from './api'
import { listDriveLinks } from '@/features/drive/api'

function item(over: Partial<IntakeItem> = {}): IntakeItem {
  return {
    id: 'i1', project_id: 'p1', name: 'Spring offer', kind: 'image', provider: 'snapchat', objective: 'sales', placement: 'story', status: 'in_review',
    source: 'upload', uploader_name: 'Sara', uploaded_by: 7, mime: 'image/png', size_bytes: 1_000_000, width: 1080, height: 1920, duration_ms: null,
    headline: null, cta: null, copy_text: null, destination_url: null, drive_modified_time: null, source_changed_at: null,
    checks: [
      { key: 'aspect_ratio', status: 'pass', params: {}, reason_ar: 'النسبة مقبولة', reason_en: 'Ratio 0.563 is accepted' },
      { key: 'duration', status: 'not_measured', params: {}, reason_ar: 'المدة لم تُقس', reason_en: 'Duration not measured: header unread' },
    ],
    check_result: 'pass', readiness_score: 100,
    analysis: { facts: {}, history: { state: 'insufficient', peers: 2, needed: 5 }, recommendations: [{ key: 'history_insufficient', severity: 'info', fact_ar: 'a', fact_en: 'Only 2 measured items' }], coverage: { measured: 1, total: 2 } },
    ai: { state: 'awaiting_credentials', fields: { description: null, hook: null, cta: null, on_image_text: null, tone: null, brand_fit: null } },
    decided_at: null, decider_name: null, decision_reason: null, decision_notes: null, has_file: false, can_edit: false,
    available_actions: ['approve', 'reject', 'request_changes'], updated_at: '2026-11-19T10:00:00Z', events: [
      { id: 'e1', action: 'created', from: null, to: 'draft', actor: 'Sara', reason: null, notes: null, item_name: 'Spring offer', at: '2026-11-19T09:00:00Z' },
    ],
    ...over,
  }
}

const list = (items: IntakeItem[], counts: Record<string, number> = { in_review: items.length }) => ({
  items, total: items.length, withheld: 0, counts, aiState: 'awaiting_credentials' as const, objectives: ['sales'], platforms: ['snapchat'], maxUploadKb: 51200,
})

describe('IntakePanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listIntake).mockResolvedValue(list([item()]))
    vi.mocked(getIntake).mockResolvedValue(item())
  })
  afterEach(() => signOut())

  it('is closed without content.view', () => {
    signInWith([])
    renderWithProviders(<IntakePanel />)
    expect(screen.getByText(/do not have permission/i)).toBeInTheDocument()
  })

  it('lists items with status chips counted, a readiness bar and the honest AI state', async () => {
    signInWith(['content.view'])
    renderWithProviders(<IntakePanel />)
    expect(await screen.findByText('Spring offer')).toBeInTheDocument()
    expect(screen.getByTestId('intake-chip-in_review')).toHaveTextContent('In review (1)')
    expect(screen.getByTestId('intake-readiness')).toBeInTheDocument()
    expect(screen.getByTestId('intake-ai-state')).toHaveTextContent(/awaiting an AI key/i)
    expect(screen.queryByTestId('intake-upload')).not.toBeInTheDocument()
  })

  it('shows the upload button only with content.create', async () => {
    signInWith(['content.view', 'content.create'])
    renderWithProviders(<IntakePanel />)
    expect(await screen.findByTestId('intake-upload')).toBeInTheDocument()
  })

  it('clicking a status chip filters the list', async () => {
    signInWith(['content.view'])
    renderWithProviders(<IntakePanel />)
    await screen.findByText('Spring offer')
    fireEvent.click(screen.getByTestId('intake-chip-approved'))
    await waitFor(() => expect(listIntake).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'approved' })))
  })

  it('opens the detail: checks with exact reasons, not-measured never as a pass, recommendations, AI fields «Not enabled», the trail', async () => {
    signInWith(['content.view'])
    renderWithProviders(<IntakePanel />, { route: '/?item=i1' })
    expect(await screen.findByTestId('intake-detail')).toBeInTheDocument()
    expect(screen.getByTestId('intake-check-aspect_ratio')).toHaveTextContent('Ratio 0.563 is accepted')
    const unmeasured = screen.getByTestId('intake-check-duration')
    expect(unmeasured).toHaveTextContent('Not measured')
    expect(unmeasured).not.toHaveTextContent(/Pass/)
    expect(screen.getByTestId('intake-recs')).toHaveTextContent('Only 2 measured items')
    expect(screen.getByTestId('intake-history')).toHaveTextContent(/Only 2 measured items share this objective/)
    expect(screen.getByTestId('intake-ai')).toHaveTextContent('Not enabled')
    expect(screen.getByTestId('intake-trail')).toHaveTextContent('Created')
  })

  it('offers only the actions the server allows and refuses a rejection without a reason', async () => {
    signInWith(['content.view', 'content.reject'])
    vi.mocked(transitionIntake).mockResolvedValue(item({ status: 'rejected' }))
    renderWithProviders(<IntakePanel />, { route: '/?item=i1' })
    await screen.findByTestId('intake-detail')
    expect(screen.queryByTestId('intake-action-submit')).not.toBeInTheDocument()
    fireEvent.click(screen.getByTestId('intake-action-reject'))
    fireEvent.click(await screen.findByTestId('intake-confirm'))
    expect(await screen.findByTestId('intake-error')).toHaveTextContent(/reason/i)
    expect(transitionIntake).not.toHaveBeenCalled()
    fireEvent.change(screen.getByTestId('intake-reason'), { target: { value: 'Off brand' } })
    fireEvent.click(screen.getByTestId('intake-confirm'))
    await waitFor(() => expect(transitionIntake).toHaveBeenCalledWith('i1', 'reject', expect.objectContaining({ reason: 'Off brand' })))
  })

  it('renders in Arabic with translated statuses and the Arabic reason', async () => {
    signInWith(['content.view'])
    renderWithProviders(<IntakePanel />, { route: '/?item=i1', locale: 'ar' })
    expect(await screen.findByTestId('intake-chip-in_review')).toHaveTextContent('قيد المراجعة')
    expect(await screen.findByTestId('intake-check-aspect_ratio')).toHaveTextContent('النسبة مقبولة')
    expect(screen.getByTestId('intake-ai')).toHaveTextContent('غير مفعّل')
  })

  it('shows the after-launch comparison only for a live item and says when it is not linked', async () => {
    signInWith(['content.view'])
    vi.mocked(getIntake).mockResolvedValue(item({ status: 'live', available_actions: [], after_launch: { state: 'not_linked' } }))
    renderWithProviders(<IntakePanel />, { route: '/?item=i1' })
    expect(await screen.findByTestId('intake-after')).toHaveTextContent(/not linked to live content/i)
  })
})

describe('FollowUpPanel', () => {
  afterEach(() => signOut())
  it('lists the four queues and live fatigue as a view signal, with the rejection reason', async () => {
    signInWith(['content.view'])
    vi.mocked(getFollowUp).mockResolvedValue({
      awaiting_review: [item()], needs_changes: [], rejected: [item({ id: 'i2', name: 'Rejected one', status: 'rejected', decision_reason: 'Off brand' })],
      approved_not_launched: [], live_fatigue: [{ item: item({ id: 'i3', name: 'Tired ad', status: 'live' }), fatigue: { status: 'fatigued', reason_en: 'CTR fell and frequency rose' } }],
    })
    renderWithProviders(<FollowUpPanel />)
    expect(await screen.findByTestId('follow-review')).toHaveTextContent('Spring offer')
    expect(screen.getByTestId('follow-rejected')).toHaveTextContent('Off brand')
    expect(screen.getByTestId('follow-fatigue')).toHaveTextContent('CTR fell and frequency rose')
    expect(screen.getByTestId('follow-approved')).toHaveTextContent('Nothing here')
  })
})

describe('DriveSyncPanel', () => {
  afterEach(() => signOut())
  const link = { id: 'l1', tenant_id: 't', scope: 'project' as const, scope_id: 'p1', folder_id: 'F', folder_name: 'Assets', connection_id: null }

  it('says Drive is not connected, and shows nothing invented', async () => {
    signInWith(['drive.view'])
    vi.mocked(listDriveLinks).mockResolvedValue([{ ...link, sync_state: 'awaiting_credentials' }])
    renderWithProviders(<DriveSyncPanel />)
    expect(await screen.findByTestId('drive-awaiting')).toBeInTheDocument()
    expect(screen.queryByTestId('drive-sync-l1')).not.toBeInTheDocument()
  })

  it('syncs, reports the counts, and keeps the last error visible', async () => {
    signInWith(['drive.view', 'drive.manage', 'content.create'])
    vi.mocked(listDriveLinks).mockResolvedValue([{ ...link, sync_state: 'error', last_error: 'quota exceeded', last_synced_at: null }])
    vi.mocked(syncDriveLink).mockResolvedValue({ state: 'synced', created: 2, updated: 1, unchanged: 3, skipped: 0, truncated: false, last_synced_at: null, last_error: null })
    renderWithProviders(<DriveSyncPanel />)
    expect(await screen.findByTestId('drive-error')).toHaveTextContent('quota exceeded')
    fireEvent.click(screen.getByTestId('drive-sync-l1'))
    expect(await screen.findByTestId('drive-result')).toHaveTextContent('new 2')
  })
})
