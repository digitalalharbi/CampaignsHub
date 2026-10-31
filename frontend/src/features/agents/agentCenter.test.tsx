import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { AgentCenterPage } from './AgentCenterPage'
import type { AgentRole, AgentThread } from './api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('./api', () => ({
  listAgents: vi.fn(), listThreads: vi.fn(), getThread: vi.fn(), sendToAgent: vi.fn(),
  listAiCredentials: vi.fn(), addAiCredential: vi.fn(), listRoutines: vi.fn(), setRoutine: vi.fn(),
  getChatRetention: vi.fn(), setChatRetention: vi.fn(), deleteThread: vi.fn(), clearThreads: vi.fn(),
}))
import { addAiCredential, getChatRetention, getThread, listAgents, listAiCredentials, listRoutines, listThreads, sendToAgent, setRoutine, type AgentRoutineRow } from './api'

const role = (key: AgentRole['key'], over: Partial<AgentRole> = {}): AgentRole => ({ key, ar: key, en: key, enabled: true, why_disabled: null, can_propose: false, ...over })
const ROLES = [role('orchestrator'), role('campaign', { can_propose: true }), role('analyst'), role('creative'), role('leads', { enabled: false, why_disabled: 'needs_lead_sources' }), role('reports')]

const thread = (over: Partial<AgentThread> = {}): AgentThread => ({
  id: 't1', agent: 'orchestrator', title: 'Q', status: 'idle', error: null,
  messages: [
    { id: 'm1', role: 'user', text: 'Which campaign leads?', tools: null, at: null },
    { id: 'm2', role: 'tool', text: null, tools: [{ name: 'list_campaigns', ok: true }], at: null },
    { id: 'm3', role: 'assistant', text: 'Spring, at 20 SAR per purchase over the last 7 days.', tools: null, at: null },
  ],
  ...over,
})

const routine = (over: Partial<AgentRoutineRow> = {}): AgentRoutineRow => ({
  key: 'budget_guard', agent: 'campaign', ar: 'حارس الميزانية', en: 'Budget guard', when_ar: 'كل ساعتين', when_en: 'Every two hours',
  enabled: false, last_run_at: null, last_outcome: null, last_thread_id: null, ...over,
})

/** AGENTS-CENTER-001 — the screen shows what the server said and did; it never invents a reply. */
describe('the agent center', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'proj' })
    signInWith(['ai.view', 'ai.use'])
    vi.mocked(listAgents).mockResolvedValue({ agents: ROLES })
    vi.mocked(listThreads).mockResolvedValue({ threads: [] })
    vi.mocked(listAiCredentials).mockResolvedValue([])
    vi.mocked(getChatRetention).mockResolvedValue({ days: 90, min: 7, max: 730, default: 90 })
    vi.mocked(listRoutines).mockResolvedValue({ routines: [routine()] })
  })
  afterEach(() => signOut())

  it('lists the six agents and says why the leads agent cannot work yet', async () => {
    renderWithProviders(<AgentCenterPage />, { locale: 'en' })

    for (const key of ['orchestrator', 'campaign', 'analyst', 'creative', 'leads', 'reports']) {
      expect(await screen.findByTestId(`agent-${key}`)).toBeInTheDocument()
    }
    fireEvent.click(screen.getByTestId('agent-leads'))
    expect(await screen.findByTestId('agent-unavailable')).toHaveTextContent('lead source')
    expect(screen.getByTestId('agent-send')).toBeDisabled()
  })

  it('sends to the chosen agent and shows the reply with the tools it used', async () => {
    vi.mocked(sendToAgent).mockResolvedValue({ thread_id: 't1', status: 'thinking' })
    vi.mocked(getThread).mockResolvedValue(thread())

    renderWithProviders(<AgentCenterPage />, { locale: 'en' })
    fireEvent.click(await screen.findByTestId('agent-analyst'))
    fireEvent.change(screen.getByTestId('agent-input'), { target: { value: 'Which campaign leads?' } })
    fireEvent.click(screen.getByTestId('agent-send'))

    await waitFor(() => expect(sendToAgent).toHaveBeenCalledWith('proj', { agent: 'analyst', message: 'Which campaign leads?' }))
    expect(await screen.findByTestId('agent-message-assistant')).toHaveTextContent('Spring, at 20 SAR')
    expect(screen.getByTestId('agent-activity')).toHaveTextContent('Read the campaigns')
  })

  it('names a missing key instead of showing a reply', async () => {
    vi.mocked(listThreads).mockResolvedValue({ threads: [{ id: 't1', agent: 'orchestrator', title: 'Q', status: 'failed', updated_at: null }] })
    vi.mocked(getThread).mockResolvedValue(thread({ status: 'failed', error: 'no_ai_credential', messages: [] }))

    renderWithProviders(<AgentCenterPage />, { locale: 'en' })
    fireEvent.click(await screen.findByText('Q'))

    expect(await screen.findByTestId('agent-failed')).toHaveTextContent('no active AI key')
  })

  it('is read-only without ai.use and offers the key only to ai.manage', async () => {
    signInWith(['ai.view'])
    renderWithProviders(<AgentCenterPage />, { locale: 'ar' })

    expect(await screen.findByTestId('agent-read-only')).toBeInTheDocument()
    expect(screen.queryByTestId('agent-input')).not.toBeInTheDocument()
    expect(screen.queryByTestId('agent-ai-key')).not.toBeInTheDocument()
  })

  it('stores a key through the masked credential API and shows only its last four', async () => {
    signInWith(['ai.view', 'ai.use', 'ai.manage'])
    vi.mocked(addAiCredential).mockResolvedValue({ id: 'k', provider: 'anthropic', credential_scope: 'tenant', masked_key: '••••abcd', status: 'active', last_used_at: null })

    renderWithProviders(<AgentCenterPage />, { locale: 'en' })
    fireEvent.change(await screen.findByTestId('agent-ai-key-input'), { target: { value: 'sk-ant-test-abcd' } })
    vi.mocked(listAiCredentials).mockResolvedValue([{ id: 'k', provider: 'anthropic', credential_scope: 'tenant', masked_key: '••••abcd', status: 'active', last_used_at: null }])
    fireEvent.click(screen.getByTestId('agent-ai-key-save'))

    await waitFor(() => expect(addAiCredential).toHaveBeenCalledWith('anthropic', 'sk-ant-test-abcd', undefined))
    expect(await screen.findByTestId('agent-ai-key-present')).toHaveTextContent('••••abcd')
    expect(screen.getByTestId('agent-ai-key-input')).toHaveValue('')
  })

  it('links to the approvals queue', async () => {
    renderWithProviders(<AgentCenterPage />, { locale: 'en' })
    expect(await screen.findByTestId('agent-center-approvals')).toHaveAttribute('href', expect.stringContaining('/agents/approvals'))
  })

  it('switches a routine on for ai.manage and says why the last run was skipped', async () => {
    signInWith(['ai.view', 'ai.use', 'ai.manage'])
    vi.mocked(listRoutines).mockResolvedValue({ routines: [routine({ last_outcome: 'no_ai_credential' })] })
    vi.mocked(setRoutine).mockResolvedValue({ routines: [routine({ enabled: true })] })

    renderWithProviders(<AgentCenterPage />, { locale: 'en' })
    expect(await screen.findByTestId('routine-budget_guard')).toHaveTextContent('skipped — no AI key')
    fireEvent.click(screen.getByTestId('routine-budget_guard-toggle'))

    await waitFor(() => expect(setRoutine).toHaveBeenCalledWith('proj', 'budget_guard', true))
    await waitFor(() => expect(screen.getByTestId('routine-budget_guard-toggle')).toBeChecked())
  })

  it('shows routines read-only without ai.manage', async () => {
    renderWithProviders(<AgentCenterPage />, { locale: 'ar' })
    expect(await screen.findByTestId('routine-budget_guard-toggle')).toBeDisabled()
  })

  it('asks for a model before it stores an OpenAI key, and names it with the key', async () => {
    signInWith(['ai.view', 'ai.use', 'ai.manage'])
    vi.mocked(addAiCredential).mockResolvedValue({ id: 'k', provider: 'openai', credential_scope: 'tenant', masked_key: '••••wxyz', status: 'active', last_used_at: null, allowed_models: ['model-x'] })

    renderWithProviders(<AgentCenterPage />, { locale: 'en' })
    fireEvent.change(await screen.findByTestId('agent-ai-key-provider'), { target: { value: 'openai' } })
    fireEvent.change(screen.getByTestId('agent-ai-key-input'), { target: { value: 'sk-test-wxyz' } })
    expect(screen.getByTestId('agent-ai-key-save')).toBeDisabled()

    fireEvent.change(screen.getByTestId('agent-ai-key-model'), { target: { value: 'model-x' } })
    fireEvent.click(screen.getByTestId('agent-ai-key-save'))

    await waitFor(() => expect(addAiCredential).toHaveBeenCalledWith('openai', 'sk-test-wxyz', 'model-x'))
  })
})
