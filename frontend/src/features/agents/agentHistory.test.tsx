import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { AgentHistoryCard } from './AgentHistoryCard'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', () => ({ listThreads: vi.fn(), deleteThread: vi.fn(), clearThreads: vi.fn() }))
import { clearThreads, deleteThread, listThreads } from './api'

/**
 * AGENT-CHAT-PRIVACY-001 — the history card: says the history is private and how long it is kept, searches and filters on the server,
 * and deletes only after an explicit yes that names what it removes.
 */
const roles = [{ key: 'orchestrator', ar: 'المنسّق', en: 'Orchestrator', enabled: true, why_disabled: null, can_propose: false }] as never
const rows = [
  { id: 't1', agent: 'orchestrator', title: 'Budget question', status: 'idle', updated_at: null, shared: false },
  { id: 't2', agent: 'orchestrator', title: 'Weekly run', status: 'idle', updated_at: null, shared: true },
]
function mount(onGone = vi.fn(), onOpen = vi.fn()) {
  renderWithProviders(<AgentHistoryCard projectId="p1" ar={false} roles={roles} current="orchestrator" activeId={null} onOpen={onOpen} onNew={vi.fn()} onGone={onGone} />, { locale: 'en' })

  return { onGone, onOpen }
}

beforeEach(() => {
  vi.clearAllMocks()
  vi.mocked(listThreads).mockResolvedValue({ threads: rows, total: 2, retention_days: 45 } as never)
})

describe('AgentHistoryCard', () => {
  it('says the history is private and states the retention the server reports', async () => {
    mount()
    const note = await screen.findByTestId('agent-privacy')
    expect(note.textContent).toMatch(/private to you/)
    await waitFor(() => expect(screen.getByTestId('agent-privacy').textContent).toMatch(/45 days/))
  })

  it('searches on the server after a short pause and filters by status', async () => {
    mount()
    await screen.findByText('Budget question')
    fireEvent.change(screen.getByTestId('agent-search'), { target: { value: 'budget' } })
    await waitFor(() => expect(listThreads).toHaveBeenLastCalledWith('p1', { q: 'budget', agent: '', status: '' }))
    fireEvent.change(screen.getByTestId('agent-filter-status'), { target: { value: 'failed' } })
    await waitFor(() => expect(listThreads).toHaveBeenLastCalledWith('p1', { q: 'budget', agent: '', status: 'failed' }))
  })

  it('says «nothing matches» for a filtered empty list, not «no conversations yet»', async () => {
    vi.mocked(listThreads).mockResolvedValue({ threads: [], total: 0 } as never)
    mount()
    await screen.findByText('No conversations yet.')
    fireEvent.change(screen.getByTestId('agent-filter-status'), { target: { value: 'failed' } })
    expect(await screen.findByText('Nothing matches.')).toBeInTheDocument()
  })

  it('deletes one conversation only after an explicit yes, and tells the screen it is gone', async () => {
    vi.mocked(deleteThread).mockResolvedValue({} as never)
    const { onGone } = mount()
    fireEvent.click(await screen.findByTestId('thread-delete-t1'))
    expect(screen.getByTestId('agent-delete-confirm').textContent).toMatch(/deleted permanently/)
    expect(deleteThread).not.toHaveBeenCalled()

    fireEvent.click(screen.getByTestId('agent-delete-yes'))
    await waitFor(() => expect(deleteThread).toHaveBeenCalledWith('p1', 't1'))
    await waitFor(() => expect(onGone).toHaveBeenCalledWith(['t1']))
  })

  it('clears my history only after confirming, and says colleagues are not touched', async () => {
    vi.mocked(clearThreads).mockResolvedValue({ deleted: 1 })
    const { onGone } = mount()
    fireEvent.click(await screen.findByTestId('agent-clear'))
    expect(screen.getByTestId('agent-delete-confirm').textContent).toMatch(/colleagues/)
    expect(clearThreads).not.toHaveBeenCalled()
    fireEvent.click(screen.getByTestId('agent-delete-yes'))
    await waitFor(() => expect(clearThreads).toHaveBeenCalledWith('p1'))
    await waitFor(() => expect(onGone).toHaveBeenCalledWith('all'))
  })

  it('marks a scheduled run as shared and offers no per-row delete while a conversation is being answered', async () => {
    vi.mocked(listThreads).mockResolvedValue({ threads: [...rows, { id: 't3', agent: 'orchestrator', title: 'Busy', status: 'thinking', updated_at: null, shared: false }], total: 3 } as never)
    mount()
    expect(await screen.findByText('Scheduled run')).toBeInTheDocument()
    expect(screen.queryByTestId('thread-delete-t3')).toBeNull()
    expect(screen.getByTestId('thread-delete-t1')).toBeInTheDocument()
  })

  it('cancelling the confirmation deletes nothing', async () => {
    mount()
    fireEvent.click(await screen.findByTestId('thread-delete-t1'))
    fireEvent.click(screen.getByText('Cancel'))
    expect(screen.queryByTestId('agent-delete-confirm')).toBeNull()
    expect(deleteThread).not.toHaveBeenCalled()
  })
})
