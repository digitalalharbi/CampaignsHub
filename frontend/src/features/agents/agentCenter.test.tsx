import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, fireEvent, screen, waitFor, within } from '@testing-library/react'
import { AgentCenterPage } from './AgentCenterPage'
import type { AgentThread, AgentRoutineRow, LiveReply, TeamMember } from './api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('./api', () => ({
  listThreads: vi.fn(), getThread: vi.fn(), sendToAgent: vi.fn(), getLive: vi.fn(), stopReply: vi.fn(), updateThread: vi.fn(), deleteThread: vi.fn(),
  listAiCredentials: vi.fn(), addAiCredential: vi.fn(), testAiCredential: vi.fn(), listRoutines: vi.fn(), setRoutine: vi.fn(), runRoutineNow: vi.fn(),
  getChatRetention: vi.fn(), setChatRetention: vi.fn(), getAssistantSettings: vi.fn(), saveAssistantSettings: vi.fn(),
  listMemories: vi.fn(), addMemory: vi.fn(), editMemory: vi.fn(), deleteMemory: vi.fn(), forgetAllMemories: vi.fn(), exportMemories: vi.fn(),
  listProposals: vi.fn(), decideProposal: vi.fn(), amendProposal: vi.fn(), bulkDecide: vi.fn(), getTeam: vi.fn(),
}))
vi.mock('@/features/projects/api', () => ({ listProjects: vi.fn().mockResolvedValue([{ id: 'proj', name: 'متجر الورد' }]) }))
vi.mock('@/features/funding/fundingApi', () => ({ useFinancialDisclaimer: () => ({ data: { ar: 'الأرقام تقديرية من المنصات.', en: 'Figures come from the platforms.' } }), useBudgets: () => ({ data: undefined }) }))

import {
  addMemory, getAssistantSettings, getLive, getTeam, getThread, listAiCredentials, listMemories, listProposals, listRoutines, listThreads,
  runRoutineNow, sendToAgent, stopReply, testAiCredential, getChatRetention,
} from './api'

const settings = { model: 'claude-sonnet-5-5', models: ['claude-sonnet-5-5', 'claude-opus-5-5', 'claude-haiku-5-5'], default_model: 'claude-sonnet-5-5', daily_token_cap: 200000, cap_min: 10000, cap_max: 5000000, used_today: 12000, max_tool_rounds: 6 }
const live = (over: Partial<LiveReply> = {}): LiveReply => ({ status: 'thinking', error: null, phase: 'writing', specialist: 'analyst', text: '', tools: [], seq: 1, ...over })
const answered = (over: Partial<AgentThread> = {}): AgentThread => ({
  id: 't1', agent: 'assistant', title: 'Q', status: 'idle', error: null, context: { days: 7 },
  messages: [
    { id: 'm1', role: 'user', text: 'كيف الأداء؟', tools: null, at: null },
    {
      id: 'm2', role: 'assistant', text: 'الربيع أنفق 80 ر.س آخر 7 أيام.', tools: null, at: null, specialist: 'analyst',
      meta: {
        cards: [{ type: 'campaigns', window: { from: '2026-11-08', to: '2026-11-14' }, currency: 'SAR', count: 1, rows: [{ id: 'c1', name: 'الربيع', objective: 'sales', spend: 80, results: 4, cpa: 20, roas: null }] }],
        sources: [{ tool: 'list_campaigns', from: '2026-11-08', to: '2026-11-14' }],
      },
    },
  ],
  ...over,
})
const routine = (over: Partial<AgentRoutineRow> = {}): AgentRoutineRow => ({
  key: 'daily_analysis', agent: 'analyst', ar: 'التحليل اليومي', en: 'Daily analysis', when_ar: 'كل يوم 08:45 بتوقيت الرياض', when_en: 'Every day 08:45 Riyadh time',
  enabled: true, last_run_at: null, last_outcome: null, last_thread_id: null, next_run_at: '2026-11-16T05:45:00+00:00', ...over,
})
const member = (key: TeamMember['key'], over: Partial<TeamMember> = {}): TeamMember => ({
  key, ar: key, en: key, job: 'analyse', autonomy: 'suggests', job_ar: 'يقرأ الأداء', job_en: 'Reads performance', enabled: true, why_disabled: null,
  last_activity_at: null, pending_proposals: 0, schedule: 'none', ...over,
})

/** AGENTS-ASSISTANT-001 — the screen shows what the server stored or is writing; it never invents a reply. */
describe('the agent center', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'proj' })
    signInWith(['ai.view', 'ai.use'])
    vi.mocked(listThreads).mockResolvedValue({ threads: [] })
    vi.mocked(getAssistantSettings).mockResolvedValue(settings)
    vi.mocked(listProposals).mockResolvedValue({ proposals: [], limits: { max_increase_percent: 20, daily_budget_ceiling: null } })
    vi.mocked(listAiCredentials).mockResolvedValue([])
    vi.mocked(listRoutines).mockResolvedValue({ routines: [routine()] })
    vi.mocked(listMemories).mockResolvedValue({ memories: [], max: 30, max_chars: 280 })
    vi.mocked(getChatRetention).mockResolvedValue({ days: 90, min: 7, max: 730, default: 90 })
  })
  afterEach(() => { signOut(); vi.useRealTimers() })

  it('opens on one assistant with four smart prompts, the context it can see, and today’s usage', async () => {
    renderWithProviders(<AgentCenterPage />, { locale: 'ar' })

    expect(await screen.findByTestId('assistant-empty')).toBeInTheDocument()
    expect(screen.getAllByTestId('assistant-prompt')).toHaveLength(4)
    expect(await screen.findByTestId('context-project')).toHaveTextContent('متجر الورد')
    expect(screen.getByTestId('context-period')).toHaveTextContent('آخر 7 أيام')
    expect(await screen.findByTestId('usage-chip')).toHaveTextContent('12k / 200k')
    expect(screen.getAllByRole('tab').map((t) => t.textContent)).toEqual(['المساعد', 'الفريق', 'الاقتراحات'])
  })

  it('sends with Enter, keeps Shift+Enter for a new line, streams the reply and ends on the stored answer with its specialist, cards and source', async () => {
    vi.mocked(sendToAgent).mockResolvedValue({ thread_id: 't1', status: 'thinking' })
    let thinking = true
    vi.mocked(getThread).mockImplementation(async () => (thinking ? answered({ status: 'thinking', messages: [answered().messages[0]] }) : answered()))
    vi.mocked(getLive).mockResolvedValue(live({ text: 'الربيع أنفق', seq: 3 }))

    renderWithProviders(<AgentCenterPage />, { locale: 'ar' })
    const input = await screen.findByTestId('agent-input')
    fireEvent.change(input, { target: { value: 'كيف الأداء؟' } })
    fireEvent.keyDown(input, { key: 'Enter', shiftKey: true })
    expect(sendToAgent).not.toHaveBeenCalled()
    fireEvent.keyDown(input, { key: 'Enter' })

    await waitFor(() => expect(sendToAgent).toHaveBeenCalledWith('proj', { agent: 'assistant', message: 'كيف الأداء؟', context: { days: 7 } }))
    expect(await screen.findByTestId('agent-stream-text')).toHaveTextContent('الربيع أنفق')
    expect(within(screen.getByTestId('agent-streaming')).getByTestId('specialist-chip')).toHaveTextContent('تحليل')

    thinking = false
    vi.mocked(getLive).mockResolvedValue(live({ status: 'idle', text: '', seq: 4 }))
    await waitFor(() => expect(screen.getByTestId('agent-message-assistant')).toHaveTextContent('الربيع أنفق 80'), { timeout: 4000 })
    const bubble = screen.getByTestId('agent-message-assistant')
    expect(within(bubble).getByTestId('specialist-chip')).toHaveTextContent('تحليل')
    expect(within(bubble).getByTestId('card-campaigns')).toHaveTextContent('ر.س')
    expect(within(bubble).getByTestId('answer-sources')).toHaveTextContent('8 نوفمبر – 14 نوفمبر 2026')
    expect(within(bubble).getByTestId('financial-disclaimer')).toBeInTheDocument()
    expect(within(bubble).getByTestId('agent-retry')).toBeInTheDocument()
  })

  it('stops a reply in progress', async () => {
    vi.mocked(listThreads).mockResolvedValue({ threads: [{ id: 't1', agent: 'assistant', title: 'Q', status: 'thinking', updated_at: new Date().toISOString() }] })
    vi.mocked(getThread).mockResolvedValue(answered({ status: 'thinking', messages: [answered().messages[0]] }))
    vi.mocked(getLive).mockResolvedValue(live({ text: 'جزء', seq: 2 }))
    vi.mocked(stopReply).mockResolvedValue({ status: 'thinking', stopping: true })

    renderWithProviders(<AgentCenterPage />, { locale: 'en' })
    fireEvent.click(await screen.findByTestId('rail-open-t1'))
    fireEvent.click(await screen.findByTestId('agent-stop'))

    await waitFor(() => expect(stopReply).toHaveBeenCalledWith('proj', 't1'))
  })

  it('offers an «always» instruction as a memory and saves it only when accepted', async () => {
    vi.mocked(sendToAgent).mockResolvedValue({ thread_id: 't1', status: 'thinking', suggested_memory: 'اختصر دائمًا' })
    vi.mocked(getThread).mockResolvedValue(answered())
    vi.mocked(getLive).mockResolvedValue(live({ status: 'idle' }))
    vi.mocked(addMemory).mockResolvedValue({ id: 'm', text: 'اختصر دائمًا', source: 'suggested', updated_at: null })

    renderWithProviders(<AgentCenterPage />, { locale: 'ar' })
    fireEvent.change(await screen.findByTestId('agent-input'), { target: { value: 'اختصر دائمًا' } })
    fireEvent.click(screen.getByTestId('agent-send'))

    expect(await screen.findByTestId('memory-suggestion')).toHaveTextContent('اختصر دائمًا')
    expect(addMemory).not.toHaveBeenCalled()
    fireEvent.click(screen.getByTestId('memory-accept'))
    await waitFor(() => expect(addMemory).toHaveBeenCalledWith('اختصر دائمًا', 'suggested'))
  })

  it('names the daily limit instead of a reply', async () => {
    vi.mocked(listThreads).mockResolvedValue({ threads: [{ id: 't1', agent: 'assistant', title: 'Q', status: 'failed', updated_at: null }] })
    vi.mocked(getThread).mockResolvedValue(answered({ status: 'failed', error: 'daily_token_cap_reached', messages: [] }))

    renderWithProviders(<AgentCenterPage />, { locale: 'ar' })
    fireEvent.click(await screen.findByTestId('rail-open-t1'))
    expect(await screen.findByTestId('agent-failed')).toHaveTextContent('حدّك اليومي')
  })

  it('is read-only without ai.use', async () => {
    signInWith(['ai.view'])
    renderWithProviders(<AgentCenterPage />, { locale: 'ar' })

    expect(await screen.findByTestId('agent-read-only')).toBeInTheDocument()
    expect(screen.queryByTestId('agent-input')).not.toBeInTheDocument()
  })

  it('shows the team by job and autonomy as cards', async () => {
    vi.mocked(getTeam).mockResolvedValue({
      jobs: ['watch', 'analyse', 'content', 'reports', 'build'],
      members: [
        member('campaign', { job: 'watch', autonomy: 'acts_after_approval', pending_proposals: 2, schedule: 'off' }),
        member('analyst', { schedule: 'on', last_activity_at: '2026-11-15T07:00:00Z' }),
        member('builder', { job: 'build', autonomy: 'acts_after_approval' }),
      ],
    })
    renderWithProviders(<AgentCenterPage />, { locale: 'ar' })
    fireEvent.click(await screen.findByRole('tab', { name: 'الفريق' }))

    const watch = await screen.findByTestId('team-job-watch')
    expect(within(watch).getByTestId('team-autonomy')).toHaveTextContent('ينفّذ بعد موافقتك')
    expect(within(watch).getByTestId('team-pending')).toHaveTextContent('2')
    expect(within(screen.getByTestId('team-member-analyst')).getByTestId('team-autonomy')).toHaveTextContent('يقترح فقط')
    expect(within(screen.getByTestId('team-member-analyst')).getByTestId('team-schedule')).toHaveTextContent('تعمل')
    expect(screen.queryByTestId('team-job-content')).not.toBeInTheDocument()
  })

  it('opens one settings drawer: masked key with a connection test, model, routines with the next run and run now', async () => {
    signInWith(['ai.view', 'ai.use', 'ai.manage'])
    vi.mocked(listAiCredentials).mockResolvedValue([{ id: 'k', provider: 'anthropic', credential_scope: 'tenant', masked_key: '••••9z9z', status: 'active', last_used_at: null }])
    vi.mocked(testAiCredential).mockResolvedValue({ ok: true, code: 'ok', masked_key: '••••9z9z' })
    vi.mocked(runRoutineNow).mockResolvedValue({ outcome: 'queued', thread_id: 'r1', routines: [routine({ last_outcome: 'queued' })] })
    vi.mocked(getThread).mockResolvedValue(answered({ id: 'r1' }))

    renderWithProviders(<AgentCenterPage />, { locale: 'ar' })
    fireEvent.click(await screen.findByTestId('open-settings'))
    const drawer = await screen.findByTestId('settings-drawer')

    expect(await within(drawer).findByTestId('settings-key-row')).toHaveTextContent('••••9z9z')
    expect(drawer).not.toHaveTextContent('sk-ant')
    fireEvent.click(within(drawer).getByTestId('settings-key-test'))
    expect(await within(drawer).findByTestId('settings-key-test-result')).toHaveTextContent('متصل')
    expect(await within(drawer).findByTestId('settings-model-claude-sonnet-5-5')).toHaveAttribute('aria-checked', 'true')
    const r = within(drawer).getByTestId('routine-daily_analysis')
    expect(r).toHaveTextContent('التحليل اليومي — كل يوم 08:45 بتوقيت الرياض')
    expect(r).toHaveTextContent('2026-11-16 08:45')
    fireEvent.click(within(drawer).getByTestId('routine-run-daily_analysis'))
    await waitFor(() => expect(runRoutineNow).toHaveBeenCalledWith('proj', 'daily_analysis'))
    await waitFor(() => expect(screen.queryByTestId('settings-drawer')).not.toBeInTheDocument())
  })

  it('keeps the drawer’s switches read-only for someone who does not manage the agents', async () => {
    renderWithProviders(<AgentCenterPage />, { locale: 'ar' })
    fireEvent.click(await screen.findByTestId('open-settings'))

    expect(await screen.findByTestId('routine-toggle-daily_analysis')).toBeDisabled()
    expect(screen.queryByTestId('routine-run-daily_analysis')).not.toBeInTheDocument()
    expect(screen.queryByTestId('agent-ai-key-input')).not.toBeInTheDocument()
    await act(async () => { fireEvent.keyDown(document, { key: 'Escape' }) })
    expect(screen.queryByTestId('settings-drawer')).not.toBeInTheDocument()
  })
})
