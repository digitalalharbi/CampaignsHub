import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useLocation } from 'react-router-dom'
import { AgencyFit, FitPanel } from '@/components/fit/AgencyFit'
import { Gauge, MessagesSquare, Settings2 } from 'lucide-react'
import { PageIntro } from '@/components/ui/PageIntro'
import { OpsHubBar } from '@/features/ops/OpsHubBar'
import { Tabs } from '@/components/ui/Tabs'
import { ProjectChooser } from '@/features/projects/ProjectChooser'
import { listProjects } from '@/features/projects/api'
import { useAuth } from '@/stores/auth'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'
import { getAssistantSettings, listProposals } from './api'
import { AssistantChat } from './AssistantChat'
import { ConversationRail } from './ConversationRail'
import { ProposalsTab } from './ProposalsTab'
import { SettingsDrawer } from './SettingsDrawer'
import { TeamTab } from './TeamTab'

/**
 * AGENTS-CENTER-001 / AGENTS-ASSISTANT-001 — «مركز الوكلاء»: one assistant, its team, and the changes waiting for you.
 *
 * - «المساعد»: one chat that routes each question to the right specialist (the chip on the answer says which), beside a slim rail
 *   of your conversations.
 * - «الفريق»: the specialists as cards, by job and by how far they may go.
 * - «الاقتراحات»: every pending change across specialists, each with its exact preview.
 * One gear opens «الإعدادات». Every reply is computed on the server from the project's own figures; no specialist changes a
 * campaign — the most any does is a proposal a person approves.
 */
type Tab = 'assistant' | 'team' | 'proposals'

const compactTokens = (n: number) => (n >= 1000 ? `${Math.round(n / 1000)}k` : String(n))

export function AgentCenterPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const { currentProjectId } = useProject()
  const canUse = useAuth((s) => s.hasPermission('ai.use'))
  const canManage = useAuth((s) => s.hasPermission('ai.manage'))
  // The same rule as the approvals queue: whoever may pause or change a budget decides; the server re-checks each one.
  const canDecide = useAuth((s) => s.hasPermission('campaigns.pause') || s.hasPermission('campaigns.budget.change'))
  const [tab, setTab] = useState<Tab>('assistant')
  /* AF2 — under /agency the chat fits the screen and takes what the header leaves; Team and Proposals are long lists of their own and flow. */
  const fit = useLocation().pathname.startsWith('/agency')
  const [threadId, setThreadId] = useState<string | null>(null)
  const [settings, setSettings] = useState(false)
  const [railOpen, setRailOpen] = useState(false)

  const projects = useQuery({ queryKey: ['projects', 'list'], queryFn: () => listProjects(false), retry: false, enabled: Boolean(currentProjectId) })
  const usage = useQuery({ queryKey: ['assistant-settings'], queryFn: getAssistantSettings, retry: false, enabled: Boolean(currentProjectId) })
  const proposals = useQuery({ queryKey: ['agent-proposals', currentProjectId], queryFn: () => listProposals(currentProjectId!), retry: false, enabled: Boolean(currentProjectId) })

  const title = ar ? 'مركز الوكلاء' : 'Agent Center'
  const purpose = ar ? 'مساعد واحد يقرأ أرقام مشروعك ويجيب، ويقترح التغيير ولا ينفّذه إلا بموافقتك.' : "One assistant that reads your project's figures and answers — it proposes changes and runs none without your approval."

  if (!currentProjectId) {
    return (
      <div className="space-y-6">
        <PageIntro title={title} purpose={purpose} />
        {/* OPS-TABS-001 — the agents open under the operations tab «الأتمتة». */}
        <OpsHubBar />
        <ProjectChooser purpose="recommendations" />
      </div>
    )
  }

  const projectName = (projects.data ?? []).find((p) => p.id === currentProjectId)?.name ?? '—'
  const pending = (proposals.data?.proposals ?? []).filter((p) => p.status === 'pending').length
  const used = usage.data?.used_today ?? 0
  const cap = usage.data?.daily_token_cap ?? 0
  const near = cap > 0 && used / cap >= 0.9
  const open = (id: string | null) => { setThreadId(id); setTab('assistant'); setRailOpen(false) }

  return (
    <AgencyFit className="space-y-4" testid="agent-center" active={tab === 'assistant'}>
      <PageIntro
        title={title}
        purpose={purpose}
        badges={(
          <span className="flex items-center gap-2">
            {cap > 0 && (
              <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold ${near ? 'bg-[var(--warning-background)] text-warning' : 'bg-surface-secondary text-text-secondary'}`} data-testid="usage-chip" title={ar ? 'استخدامك اليوم من حدك اليومي' : 'Your use today of your daily limit'}>
                <Gauge size={12} aria-hidden />{ar ? 'اليوم' : 'Today'} <bdi dir="ltr" className="tnum">{compactTokens(used)} / {compactTokens(cap)}</bdi>
              </span>
            )}
            <button type="button" onClick={() => setSettings(true)} aria-label={ar ? 'الإعدادات' : 'Settings'} data-testid="open-settings" className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-text-secondary hover:text-text-primary">
              <Settings2 size={16} />
            </button>
          </span>
        )}
      />
      <OpsHubBar />

      <Tabs
        items={[
          { key: 'assistant', label: ar ? 'المساعد' : 'Assistant' },
          { key: 'team', label: ar ? 'الفريق' : 'Team' },
          { key: 'proposals', label: pending > 0 ? `${ar ? 'الاقتراحات' : 'Proposals'} (${pending})` : (ar ? 'الاقتراحات' : 'Proposals') },
        ]}
        active={tab}
        onChange={(k) => setTab(k as Tab)}
      />

      <FitPanel className={fit ? '' : 'space-y-4'}>
      {tab === 'assistant' && (
        <div className={`grid min-w-0 grid-cols-1 gap-4 lg:grid-cols-[15rem_minmax(0,1fr)] ${fit ? 'h-full min-h-0 lg:grid-rows-[minmax(0,1fr)]' : ''}`}>
          <button type="button" onClick={() => setRailOpen((v) => !v)} aria-expanded={railOpen} data-testid="rail-toggle" className="inline-flex items-center gap-1.5 justify-self-start rounded-lg border border-border px-2.5 py-1 text-xs font-semibold text-text-secondary lg:hidden">
            <MessagesSquare size={13} aria-hidden />{ar ? 'المحادثات' : 'Conversations'}
          </button>
          <aside className={`${railOpen ? 'block' : 'hidden'} max-h-[34rem] min-w-0 lg:block ${fit ? 'lg:max-h-full lg:min-h-0 lg:overflow-y-auto' : 'lg:max-h-[calc(100vh-14rem)]'}`}>
            <ConversationRail projectId={currentProjectId} ar={ar} activeId={threadId} onOpen={open} onNew={() => open(null)} onGone={(id) => { if (id === threadId) setThreadId(null) }} />
          </aside>
          <div className={`flex min-w-0 flex-col rounded-2xl border border-border bg-surface p-3 ${fit ? 'lg:min-h-0' : 'lg:h-[calc(100vh-14rem)] lg:min-h-[32rem]'}`}>
            <AssistantChat projectId={currentProjectId} projectName={projectName} threadId={threadId} onThread={setThreadId} ar={ar} canUse={canUse} canDecide={canDecide} />
          </div>
        </div>
      )}
      {tab === 'team' && <TeamTab projectId={currentProjectId} ar={ar} onProposals={() => setTab('proposals')} />}
      {tab === 'proposals' && <ProposalsTab projectId={currentProjectId} ar={ar} canDecide={canDecide} />}

      </FitPanel>
      <SettingsDrawer open={settings} onClose={() => setSettings(false)} ar={ar} projectId={currentProjectId} canManage={canManage} onOpenThread={(id) => { setSettings(false); open(id) }} />
    </AgencyFit>
  )
}
