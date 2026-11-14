import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import type { Proposal } from './api'
import { ProposalCard } from './ProposalCard'
import { MemoryManager } from './MemoryManager'
import { ConversationRail, groupOf } from './ConversationRail'
import { suggestionsFor, Composer } from './AssistantChat'
import { ProposalsTab } from './ProposalsTab'

vi.mock('./api', () => ({
  decideProposal: vi.fn(), amendProposal: vi.fn(), bulkDecide: vi.fn(), listProposals: vi.fn(),
  listMemories: vi.fn(), addMemory: vi.fn(), editMemory: vi.fn(), deleteMemory: vi.fn(), forgetAllMemories: vi.fn(), exportMemories: vi.fn(),
  listThreads: vi.fn(), updateThread: vi.fn(), deleteThread: vi.fn(),
}))
import { addMemory, amendProposal, bulkDecide, decideProposal, deleteMemory, deleteThread, forgetAllMemories, listMemories, listProposals, listThreads, updateThread } from './api'

const proposal = (over: Partial<Proposal> = {}): Proposal => ({
  id: 'p1', agent: 'campaign', provider: 'meta', external_account_id: 'a', target_type: 'campaign', target_external_id: 'cmp_1', action: 'set_daily_budget',
  payload: { daily_budget: 120 }, currency: 'SAR', reason: 'أداء أعلى من الهدف', status: 'pending', violations: [], decided_at: null, decision_note: null,
  expires_at: null, executed_at: null, created_at: null, current_daily_budget: 100, ...over,
})

describe('the proposal card', () => {
  beforeEach(() => { vi.clearAllMocks(); signInWith(['ai.view', 'campaigns.pause']) })
  afterEach(() => signOut())

  it('shows the exact change before → after with the currency mark, and approves through the gateway', async () => {
    vi.mocked(decideProposal).mockResolvedValue(proposal({ status: 'executed' }))
    renderWithProviders(<ProposalCard projectId="p" proposal={proposal()} ar canDecide />, { locale: 'ar' })

    const preview = screen.getByTestId('proposal-preview')
    expect(preview).toHaveTextContent('100.00 ر.س')
    expect(preview).toHaveTextContent('120.00 ر.س')
    fireEvent.click(screen.getByTestId('proposal-approve'))
    await waitFor(() => expect(decideProposal).toHaveBeenCalledWith('p', 'p1', 'approve'))
  })

  it('edits only the amount of a pending budget, and rejects', async () => {
    vi.mocked(amendProposal).mockResolvedValue(proposal({ payload: { daily_budget: 110 } }))
    vi.mocked(decideProposal).mockResolvedValue(proposal({ status: 'rejected' }))
    renderWithProviders(<ProposalCard projectId="p" proposal={proposal()} ar={false} canDecide />)

    fireEvent.click(screen.getByTestId('proposal-edit'))
    fireEvent.change(screen.getByTestId('proposal-amount'), { target: { value: '110' } })
    fireEvent.click(screen.getByTestId('proposal-amount-save'))
    await waitFor(() => expect(amendProposal).toHaveBeenCalledWith('p', 'p1', 110))
    fireEvent.click(await screen.findByTestId('proposal-reject'))
    await waitFor(() => expect(decideProposal).toHaveBeenCalledWith('p', 'p1', 'reject'))
  })

  it('offers no decision to someone who cannot decide, and no edit for a pause', () => {
    renderWithProviders(<ProposalCard projectId="p" proposal={proposal({ action: 'pause', payload: null })} ar={false} canDecide={false} />)
    expect(screen.queryByTestId('proposal-approve')).not.toBeInTheDocument()
    expect(screen.queryByTestId('proposal-edit')).not.toBeInTheDocument()
  })
})

describe('the proposals tab', () => {
  beforeEach(() => { vi.clearAllMocks(); signInWith(['ai.view', 'campaigns.pause']) })
  afterEach(() => signOut())

  it('lists every selected change again before a bulk approval and sends it once confirmed', async () => {
    vi.mocked(listProposals).mockResolvedValue({ proposals: [proposal(), proposal({ id: 'p2', action: 'pause', payload: null, target_external_id: 'cmp_2' })], limits: { max_increase_percent: 20, daily_budget_ceiling: null } })
    vi.mocked(bulkDecide).mockResolvedValue({ results: [{ id: 'p1', ok: true, status: 'executed' }, { id: 'p2', ok: false, error: 'forbidden' }] })
    renderWithProviders(<ProposalsTab projectId="p" ar={false} canDecide />)

    fireEvent.click(await screen.findByTestId('proposals-select-all'))
    fireEvent.click(screen.getByTestId('proposals-bulk-approve'))
    const preview = await screen.findByTestId('proposals-bulk-preview')
    expect(within(preview).getAllByRole('listitem')).toHaveLength(2)
    expect(bulkDecide).not.toHaveBeenCalled()
    fireEvent.click(screen.getByTestId('proposals-bulk-confirm'))
    await waitFor(() => expect(bulkDecide).toHaveBeenCalledWith('p', 'approve', ['p1', 'p2']))
    expect(await screen.findByTestId('proposals-bulk-failed')).toHaveTextContent('1 not decided')
  })
})

describe('the memory manager', () => {
  beforeEach(() => { vi.clearAllMocks(); signInWith(['ai.view', 'ai.use']) })
  afterEach(() => signOut())

  it('lists, adds, deletes, and forgets all only after a confirmation', async () => {
    vi.mocked(listMemories).mockResolvedValue({ memories: [{ id: 'm1', text: 'تقاريري بالريال', source: 'user', updated_at: null }], max: 30, max_chars: 280 })
    vi.mocked(addMemory).mockResolvedValue({ id: 'm2', text: 'اختصر دائمًا', source: 'user', updated_at: null })
    vi.mocked(deleteMemory).mockResolvedValue({} as never)
    vi.mocked(forgetAllMemories).mockResolvedValue({ deleted: 1 })
    renderWithProviders(<MemoryManager ar />, { locale: 'ar' })

    expect(await screen.findByTestId('memory-row')).toHaveTextContent('تقاريري بالريال')
    fireEvent.change(screen.getByTestId('memory-input'), { target: { value: 'اختصر دائمًا' } })
    fireEvent.click(screen.getByTestId('memory-add'))
    await waitFor(() => expect(addMemory).toHaveBeenCalledWith('اختصر دائمًا'))
    fireEvent.click(screen.getByTestId('memory-delete'))
    await waitFor(() => expect(deleteMemory).toHaveBeenCalledWith('m1'))
    fireEvent.click(screen.getByTestId('memory-forget-all'))
    expect(forgetAllMemories).not.toHaveBeenCalled()
    fireEvent.click(screen.getByTestId('memory-forget-yes'))
    await waitFor(() => expect(forgetAllMemories).toHaveBeenCalled())
  })

  it('says why a secret was refused', async () => {
    vi.mocked(listMemories).mockResolvedValue({ memories: [], max: 30, max_chars: 280 })
    vi.mocked(addMemory).mockRejectedValue(Object.assign(new Error('memory_rejected'), { isAxiosError: true, response: { status: 422, data: { message: 'memory_rejected' } } }))
    renderWithProviders(<MemoryManager ar />, { locale: 'ar' })

    fireEvent.change(await screen.findByTestId('memory-input'), { target: { value: 'كلمة المرور 123' } })
    fireEvent.click(screen.getByTestId('memory-add'))
    expect(await screen.findByRole('alert')).toHaveTextContent('لا كلمات مرور')
  })
})

describe('the conversations rail', () => {
  beforeEach(() => { vi.clearAllMocks(); signInWith(['ai.view', 'ai.use']) })
  afterEach(() => signOut())

  it('groups by day with pinned first', () => {
    const now = new Date('2026-11-15T12:00:00')
    expect(groupOf({ pinned: true, updated_at: '2026-01-01T00:00:00Z' }, now)).toBe('pinned')
    expect(groupOf({ pinned: false, updated_at: '2026-11-15T08:00:00' }, now)).toBe('today')
    expect(groupOf({ pinned: false, updated_at: '2026-11-14T08:00:00' }, now)).toBe('yesterday')
    expect(groupOf({ pinned: false, updated_at: '2026-11-11T08:00:00' }, now)).toBe('week')
    expect(groupOf({ pinned: false, updated_at: '2026-10-01T08:00:00' }, now)).toBe('older')
  })

  it('pins, renames, and deletes only after a confirmation', async () => {
    vi.mocked(listThreads).mockResolvedValue({ threads: [{ id: 't1', agent: 'assistant', title: 'الميزانية', status: 'idle', updated_at: new Date().toISOString(), pinned: false }] })
    vi.mocked(updateThread).mockResolvedValue({ id: 't1', title: 'ميزانية رمضان', pinned: true })
    vi.mocked(deleteThread).mockResolvedValue({} as never)
    const onGone = vi.fn()
    renderWithProviders(<ConversationRail projectId="p" ar activeId="t1" onOpen={vi.fn()} onNew={vi.fn()} onGone={onGone} />, { locale: 'ar' })

    expect(await screen.findByTestId('rail-group-today')).toHaveTextContent('الميزانية')
    fireEvent.click(screen.getByTestId('rail-pin-t1'))
    await waitFor(() => expect(updateThread).toHaveBeenCalledWith('p', 't1', { id: 't1', pinned: true }))
    fireEvent.click(screen.getByTestId('rail-rename-t1'))
    fireEvent.change(screen.getByTestId('rail-rename-input'), { target: { value: 'ميزانية رمضان' } })
    fireEvent.submit(screen.getByTestId('rail-rename-input').closest('form')!)
    await waitFor(() => expect(updateThread).toHaveBeenCalledWith('p', 't1', { id: 't1', title: 'ميزانية رمضان' }))
    fireEvent.click(await screen.findByTestId('rail-delete-t1'))
    expect(deleteThread).not.toHaveBeenCalled()
    fireEvent.click(screen.getByTestId('rail-confirm-yes'))
    await waitFor(() => expect(deleteThread).toHaveBeenCalledWith('p', 't1'))
    await waitFor(() => expect(onGone).toHaveBeenCalledWith('t1'))
  })
})

describe('the composer and suggestions', () => {
  it('grows with the text, sends on Enter, and never sends a word still being composed', () => {
    const onSubmit = vi.fn()
    renderWithProviders(<Composer value="سطر" onChange={vi.fn()} onSubmit={onSubmit} busy={false} ar />, { locale: 'ar' })
    const input = screen.getByTestId('agent-input')
    expect(input).toHaveAttribute('dir', 'auto')
    fireEvent.keyDown(input, { key: 'Enter', isComposing: true })
    fireEvent.keyDown(input, { key: 'Enter', shiftKey: true })
    expect(onSubmit).not.toHaveBeenCalled()
    fireEvent.keyDown(input, { key: 'Enter' })
    expect(onSubmit).toHaveBeenCalledTimes(1)
  })

  it('adapts the suggestions to the period, the campaign in focus and the last specialist', () => {
    expect(suggestionsFor({ days: 30 }, null, true)[0]).toContain('آخر 30 يومًا')
    expect(suggestionsFor({ days: 7, campaign_id: 'c' }, null, true)[0]).toContain('هذه الحملة')
    expect(suggestionsFor({ days: 7 }, 'creative', false)).toContain('Write 3 alternative copies')
  })
})
