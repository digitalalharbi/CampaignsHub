import { AgentHistoryCard } from './AgentHistoryCard'
import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Bot, KeyRound, Send, ShieldCheck, Wrench } from 'lucide-react'
import {
  addAiCredential, getThread, listAgents, listAiCredentials, sendToAgent,
  type AgentMessage, type AgentRole,
} from './api'
import { RoutinesCard } from './RoutinesCard'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { PageIntro } from '@/components/ui/PageIntro'
import { ProjectChooser } from '@/features/projects/ProjectChooser'
import { toApiError } from '@/lib/api/client'
import { usePortalPath } from '@/app/portalPath'
import { useAuth } from '@/stores/auth'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'

/** Codes the server answers with, in words. Unknown codes are shown as codes, never invented. */
const REASONS: Record<string, { ar: string; en: string }> = {
  no_ai_credential: { ar: 'لا يوجد مفتاح ذكاء اصطناعي فعّال لهذه المساحة — أضِف مفتاح Anthropic أدناه.', en: 'There is no active AI key for this workspace — add an Anthropic key below.' },
  ai_token_limit_reached: { ar: 'بلغ استخدام الوكلاء حدّ الرموز الشهري لمفتاح هذه المساحة.', en: "The agents reached this workspace key's monthly token limit." },
  model_not_configured: { ar: 'لم يُحدَّد نموذج لهذا المفتاح — اذكر النموذج في إعدادات المفتاح.', en: 'No model is set for this key — name the model in the key settings.' },
  model_request_failed_401: { ar: 'رفض مزوّد الذكاء الاصطناعي المفتاح (401) — تحقّق من صحته.', en: 'The AI provider refused the key (401) — check it is valid.' },
  model_request_failed_429: { ar: 'تجاوز المفتاح حدّ الاستخدام لدى المزوّد (429) — حاول لاحقًا.', en: 'The key hit the provider rate limit (429) — try again later.' },
  agent_turn_failed: { ar: 'تعذّر إكمال الرد. حاول مرة أخرى بعد قليل.', en: 'The reply could not be completed. Try again shortly.' },
  needs_lead_sources: { ar: 'يحتاج إلى ربط مصدر العملاء المحتملين (جدول أو واتساب) أولًا.', en: 'Needs a lead source (a sheet or WhatsApp) connected first.' },
}

const reason = (code: string | null, ar: boolean) => {
  if (code === null) return ''
  if (REASONS[code]) return ar ? REASONS[code].ar : REASONS[code].en
  // Any other provider status is named as such rather than shown as a bare code.
  const status = /^model_request_failed_(\d{3})$/.exec(code)?.[1]
  if (status) return ar ? `رفض مزوّد الذكاء الاصطناعي الطلب (${status}).` : `The AI provider refused the request (${status}).`
  return code
}

const TOOL_WORDS: Record<string, { ar: string; en: string }> = {
  list_campaigns: { ar: 'قرأ الحملات', en: 'Read the campaigns' },
  get_history: { ar: 'قرأ السجل اليومي', en: 'Read the daily history' },
  compare_targets: { ar: 'قارن بالأهداف', en: 'Compared with the targets' },
  get_rules: { ar: 'قرأ قواعد العميل', en: "Read the client's rules" },
  list_creatives: { ar: 'قرأ الإعلانات وإجهادها', en: 'Read the ads and their fatigue' },
  propose_change: { ar: 'اقترح تغييرًا (بانتظار الموافقة)', en: 'Proposed a change (awaiting approval)' },
  ask_agent: { ar: 'سأل وكيلًا متخصصًا', en: 'Asked a specialist agent' },
}

/**
 * AGENTS-CENTER-001 — the six agents, one conversation at a time, inside the selected project.
 *
 * Every reply is computed on the server from the project's own figures; this screen only shows what was
 * said and which tools were used. An agent cannot change a campaign: the most it can do is put a request
 * in the approvals queue, which is linked here.
 */
export function AgentCenterPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const { currentProjectId } = useProject()
  const portalTo = usePortalPath()
  const canUse = useAuth((s) => s.hasPermission('ai.use'))
  const canManage = useAuth((s) => s.hasPermission('ai.manage'))
  const qc = useQueryClient()

  const [agent, setAgent] = useState<AgentRole['key']>('orchestrator')
  const [threadId, setThreadId] = useState<string | null>(null)
  const [draft, setDraft] = useState('')

  const agents = useQuery({ queryKey: ['agents', currentProjectId], queryFn: () => listAgents(currentProjectId!), enabled: Boolean(currentProjectId), retry: false })
  const thread = useQuery({
    queryKey: ['agent-thread', currentProjectId, threadId],
    queryFn: () => getThread(currentProjectId!, threadId!),
    enabled: Boolean(currentProjectId && threadId),
    retry: false,
    // Read again while the agent is still answering; stop as soon as it is idle or failed.
    refetchInterval: (q) => (q.state.data?.status === 'thinking' ? 2000 : false),
  })

  const send = useMutation({
    mutationFn: (message: string) => sendToAgent(currentProjectId!, threadId ? { thread_id: threadId, message } : { agent, message }),
    onSuccess: (r) => {
      setDraft('')
      setThreadId(r.thread_id)
      void qc.invalidateQueries({ queryKey: ['agent-thread', currentProjectId, r.thread_id] })
      void qc.invalidateQueries({ queryKey: ['agent-threads', currentProjectId] })
    },
  })

  const title = ar ? 'مركز الوكلاء' : 'Agent Center'
  const purpose = ar
    ? 'ستة وكلاء يقرؤون أرقام المشروع الفعلية ويجيبون عنها — ولا يغيّرون أي حملة: أقصى ما يفعلونه اقتراح يذهب إلى طلبات الموافقة.'
    : "Six agents that read the project's actual figures and answer from them — none of them changes a campaign: the most they do is a proposal sent to Approvals."

  if (!currentProjectId) {
    return (
      <div className="space-y-6">
        <PageIntro title={title} purpose={purpose} />
        <ProjectChooser purpose="recommendations" />
      </div>
    )
  }

  const roles = agents.data?.agents ?? []
  const current = roles.find((r) => r.key === (thread.data?.agent ?? agent))
  const busy = thread.data?.status === 'thinking' || send.isPending

  return (
    <div className="space-y-4" data-testid="agent-center">
      <PageIntro
        title={title}
        purpose={purpose}
        badges={<Link to={portalTo('/agents/approvals')} data-testid="agent-center-approvals" className="text-xs font-semibold text-brand-700 hover:underline"><ShieldCheck size={13} className="me-1 inline" aria-hidden />{ar ? 'طلبات الموافقة' : 'Approvals'}</Link>}
      />

      {agents.isLoading && <Skeleton className="h-16" />}
      <div role="tablist" aria-label={ar ? 'الوكلاء' : 'Agents'} className="flex flex-wrap gap-2" data-testid="agent-strip">
        {roles.map((r) => {
          const active = (thread.data?.agent ?? agent) === r.key
          return (
            <button
              key={r.key}
              type="button"
              role="tab"
              aria-selected={active}
              data-testid={`agent-${r.key}`}
              data-enabled={r.enabled}
              onClick={() => { setAgent(r.key); setThreadId(null) }}
              className={`flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-brand-600 ${
                active ? 'border-brand-600 bg-brand-600 text-white' : 'border-border bg-surface text-text-primary hover:border-brand-400'
              } ${r.enabled ? '' : 'opacity-60'}`}
            >
              <Bot size={13} aria-hidden /> {ar ? r.ar : r.en}
              {!r.enabled && <span className="sr-only">{ar ? '(غير متاح)' : '(unavailable)'}</span>}
            </button>
          )
        })}
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_16rem]">
        <Card className="flex min-w-0 flex-col gap-3" data-testid="agent-chat">
          {current && !current.enabled && (
            <p className="rounded-lg bg-[var(--warning-background)] px-3 py-2 text-xs text-warning" data-testid="agent-unavailable">{reason(current.why_disabled, ar)}</p>
          )}

          <ol className="flex max-h-[28rem] min-h-40 flex-col gap-2 overflow-y-auto" aria-live="polite" data-testid="agent-messages">
            {!threadId && (
              <li className="text-sm text-text-muted">
                {ar ? 'اسأل مثلًا: «ما أكثر حملة كلفةً هذا الأسبوع مقارنةً بالهدف؟»' : 'Ask, for example: "Which campaign cost most against its target this week?"'}
              </li>
            )}
            {thread.data?.messages.map((m) => <MessageRow key={m.id} m={m} ar={ar} />)}
            {thread.data?.status === 'thinking' && <li className="text-xs text-text-muted" data-testid="agent-thinking">{ar ? 'الوكيل يقرأ الأرقام ويُعِدّ الرد…' : 'The agent is reading the figures and preparing a reply…'}</li>}
          </ol>

          {thread.data?.status === 'failed' && (
            <p role="alert" className="rounded-lg bg-[var(--warning-background)] px-3 py-2 text-xs text-warning" data-testid="agent-failed">{reason(thread.data.error, ar)}</p>
          )}
          {send.isError && <p role="alert" className="text-xs text-danger" data-testid="agent-send-error">{toApiError(send.error).message}</p>}

          {canUse ? (
            <form
              className="flex items-end gap-2"
              onSubmit={(e) => { e.preventDefault(); if (draft.trim() !== '') send.mutate(draft.trim()) }}
            >
              <label className="min-w-0 flex-1">
                <span className="sr-only">{ar ? 'رسالتك' : 'Your message'}</span>
                <textarea
                  value={draft}
                  onChange={(e) => setDraft(e.target.value)}
                  rows={2}
                  maxLength={4000}
                  disabled={busy || current?.enabled === false}
                  data-testid="agent-input"
                  className="w-full resize-y rounded-lg border border-border bg-surface px-3 py-2 text-sm text-text-primary focus-visible:outline-2 focus-visible:outline-brand-600"
                />
              </label>
              <Button type="submit" size="sm" disabled={busy || draft.trim() === '' || current?.enabled === false} data-testid="agent-send">
                <Send size={14} /> {ar ? 'إرسال' : 'Send'}
              </Button>
            </form>
          ) : (
            <p className="text-xs text-text-muted" data-testid="agent-read-only">{ar ? 'يمكنك قراءة المحادثات فقط.' : 'You can read conversations only.'}</p>
          )}
        </Card>

        <aside className="min-w-0 space-y-3">
          <AgentHistoryCard
            projectId={currentProjectId}
            ar={ar}
            roles={roles}
            current={thread.data?.agent ?? agent}
            activeId={threadId}
            onOpen={setThreadId}
            onNew={() => setThreadId(null)}
            onGone={(ids) => { if (ids === 'all' || (threadId !== null && ids.includes(threadId))) setThreadId(null) }}
          />
          <RoutinesCard projectId={currentProjectId} ar={ar} canManage={canManage} onOpen={(id) => setThreadId(id)} />
          {canManage && <AiKeyCard ar={ar} />}
        </aside>
      </div>

      {agents.isError && <EmptyState title={ar ? 'تعذّر تحميل الوكلاء' : 'The agents could not be loaded'} />}
    </div>
  )
}

function MessageRow({ m, ar }: { m: AgentMessage; ar: boolean }) {
  if (m.role === 'tool') {
    return (
      <li className="flex flex-wrap gap-1" data-testid="agent-activity">
        {(m.tools ?? []).map((t, i) => (
          <Badge key={`${t.name}-${i}`} tone={t.ok === false ? 'warning' : 'neutral'}>
            <Wrench size={11} className="me-1 inline" aria-hidden />{TOOL_WORDS[t.name] ? (ar ? TOOL_WORDS[t.name].ar : TOOL_WORDS[t.name].en) : t.name}
          </Badge>
        ))}
      </li>
    )
  }
  if (!m.text) return null
  const mine = m.role === 'user'
  return (
    <li className={`flex ${mine ? 'justify-end' : 'justify-start'}`} data-testid={`agent-message-${m.role}`}>
      <p dir="auto" className={`max-w-[85%] whitespace-pre-wrap rounded-2xl px-3 py-2 text-sm ${mine ? 'bg-brand-600 text-white' : m.role === 'system' ? 'bg-[var(--warning-background)] text-warning' : 'bg-surface-secondary text-text-primary'}`}>
        {m.text}
      </p>
    </li>
  )
}

/** The tenant's AI key: entered once by the Owner, stored encrypted, only its last four shown. */
function AiKeyCard({ ar }: { ar: boolean }) {
  const qc = useQueryClient()
  const [provider, setProvider] = useState<'anthropic' | 'openai' | 'gemini'>('anthropic')
  const [secret, setSecret] = useState('')
  const [model, setModel] = useState('')
  const keys = useQuery({ queryKey: ['ai-credentials'], queryFn: listAiCredentials, retry: false })
  const add = useMutation({
    mutationFn: () => addAiCredential(provider, secret, model.trim() || undefined),
    onSuccess: () => { setSecret(''); setModel(''); void qc.invalidateQueries({ queryKey: ['ai-credentials'] }) },
  })
  const active = (keys.data ?? []).filter((k) => k.status === 'active' && ['anthropic', 'openai', 'gemini'].includes(k.provider))
  // Only Anthropic has a model per role configured by default; OpenAI and Gemini name theirs.
  const needsModel = provider !== 'anthropic' && model.trim() === ''

  return (
    <Card className="space-y-2" data-testid="agent-ai-key">
      <p className="flex items-center gap-1 text-xs font-bold text-text-primary"><KeyRound size={13} aria-hidden />{ar ? 'مفتاح الذكاء الاصطناعي' : 'AI key'}</p>
      {active.length > 0 ? (
        <ul className="space-y-0.5" data-testid="agent-ai-key-present">
          {active.map((k) => (
            <li key={k.id} className="text-xs text-text-secondary" dir="ltr">{k.provider === 'openai' ? 'OpenAI' : k.provider === 'gemini' ? 'Gemini' : 'Anthropic'} · {k.masked_key}{k.allowed_models?.length ? ` · ${k.allowed_models[0]}` : ''}</li>
          ))}
        </ul>
      ) : (
        <p className="text-xs text-text-muted">{ar ? 'لا مفتاح فعّال. الوكلاء لا يعملون بدونه.' : 'No active key. The agents do not work without one.'}</p>
      )}
      <form className="space-y-1.5" onSubmit={(e) => { e.preventDefault(); if (secret.length >= 8 && !needsModel) add.mutate() }}>
        <label className="block">
          <span className="sr-only">{ar ? 'المزوّد' : 'Provider'}</span>
          <select value={provider} onChange={(e) => setProvider(e.target.value as 'anthropic' | 'openai' | 'gemini')} data-testid="agent-ai-key-provider" className="w-full rounded border border-border bg-surface px-2 py-1 text-xs">
            <option value="anthropic">Anthropic</option>
            <option value="openai">OpenAI</option>
            <option value="gemini">Gemini</option>
          </select>
        </label>
        <label className="block">
          <span className="sr-only">{ar ? 'المفتاح' : 'Key'}</span>
          <input type="password" autoComplete="off" value={secret} onChange={(e) => setSecret(e.target.value)} placeholder={provider === 'openai' ? 'sk-…' : provider === 'gemini' ? 'AIza…' : 'sk-ant-…'} dir="ltr" data-testid="agent-ai-key-input" className="w-full rounded border border-border bg-surface px-2 py-1 text-xs" />
        </label>
        <label className="block">
          <span className="sr-only">{ar ? 'النموذج' : 'Model'}</span>
          <input value={model} onChange={(e) => setModel(e.target.value)} placeholder={provider !== 'anthropic' ? (ar ? 'اسم النموذج (مطلوب)' : 'Model name (required)') : (ar ? 'اسم النموذج (اختياري)' : 'Model name (optional)')} dir="ltr" data-testid="agent-ai-key-model" className="w-full rounded border border-border bg-surface px-2 py-1 text-xs" />
        </label>
        <Button type="submit" size="sm" variant="secondary" disabled={secret.length < 8 || needsModel || add.isPending} data-testid="agent-ai-key-save">{ar ? 'حفظ' : 'Save'}</Button>
      </form>
      {add.isError && <p role="alert" className="text-xs text-danger">{toApiError(add.error).message}</p>}
    </Card>
  )
}
