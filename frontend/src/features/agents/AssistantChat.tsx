import { useEffect, useLayoutEffect, useReducer, useRef, useState, type KeyboardEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowUp, Bookmark, Check, Copy, Loader2, RotateCcw, Sparkles, Square, X } from 'lucide-react'
import { toApiError } from '@/lib/api/client'
import { days } from '@/lib/counted'
import { addMemory, getLive, getThread, listProposals, sendToAgent, stopReply, type AgentMessage, type SpecialistKey, type ThreadContext } from './api'
import { AnswerCards } from './AnswerCards'
import { ProposalCard } from './ProposalCard'
import { initialStream, streamReducer } from './streamReducer'
import { reasonText, SPECIALIST, STEP, toolText } from './agentCopy'

/**
 * AGENTS-ASSISTANT-001 — «المساعد»: one chat, the specialists behind it.
 *
 * Bubbles, a composer that grows with the text (Enter sends, Shift+Enter breaks the line, RTL by the text's own direction), the
 * reply streaming in as it is written with Stop, Copy and Retry, suggestion chips that follow the context, the context chips the
 * assistant can see right now (each removable), the data cards and «المصدر» the server cut from the tools, and the proposal
 * cards to decide in place. Everything shown is what the server stored or is writing — the screen invents no reply.
 */
const PERIODS = [7, 30] as const

export function suggestionsFor(context: ThreadContext, last: SpecialistKey | null, ar: boolean): string[] {
  const d = context.days ?? 7
  const period = ar ? `آخر ${days(d, 'ar')}` : `the last ${days(d, 'en')}`
  if (context.campaign_id) {
    return ar
      ? [`كيف أداء هذه الحملة ${period}؟`, 'هل تحتاج تعديل ميزانية؟', 'ما الإعلانات المُجهدة فيها؟']
      : [`How did this campaign do over ${period}?`, 'Does it need a budget change?', 'Which of its ads are tired?']
  }
  if (last === 'campaign') return ar ? ['ما الحملات التي تحتاج قرارًا اليوم؟', 'اقترح إيقاف الأضعف', 'قارن بالأهداف'] : ['Which campaigns need a decision today?', 'Propose pausing the weakest', 'Compare with targets']
  if (last === 'creative') return ar ? ['اكتب 3 نصوص بديلة', 'ما الإعلانات المُجهدة؟', 'أفكار فيديو قصيرة'] : ['Write 3 alternative copies', 'Which ads are tired?', 'Short video ideas']
  return ar
    ? [`ملخص ${period}`, 'ما الحملات خارج الهدف؟', 'ما الإعلانات المُجهدة؟', 'لخّص للعميل']
    : [`Summary of ${period}`, 'Which campaigns are off target?', 'Which ads are tired?', 'Summarise for the client']
}

export function AssistantChat({ projectId, projectName, threadId, onThread, ar, canUse, canDecide }: {
  projectId: string
  projectName: string
  threadId: string | null
  onThread: (id: string | null) => void
  ar: boolean
  canUse: boolean
  canDecide: boolean
}) {
  const qc = useQueryClient()
  const [draft, setDraft] = useState('')
  const [context, setContext] = useState<ThreadContext>({ days: 7 })
  const [suggestedMemory, setSuggestedMemory] = useState<string | null>(null)
  const [stream, dispatch] = useReducer(streamReducer, initialStream)
  const listRef = useRef<HTMLOListElement>(null)

  const thread = useQuery({
    queryKey: ['agent-thread', projectId, threadId],
    queryFn: () => getThread(projectId, threadId!),
    enabled: Boolean(threadId),
    retry: false,
    refetchInterval: (q) => (q.state.data?.status === 'thinking' ? 2000 : false),
  })
  const thinking = thread.data?.status === 'thinking'
  const live = useQuery({
    queryKey: ['agent-live', projectId, threadId],
    queryFn: () => getLive(projectId, threadId!),
    enabled: Boolean(threadId) && (thinking || stream.phase !== 'idle'),
    retry: false,
    refetchInterval: 500,
  })
  const proposals = useQuery({ queryKey: ['agent-proposals', projectId], queryFn: () => listProposals(projectId), retry: false, enabled: Boolean(threadId) })

  // A conversation opened from the rail brings its own context chips.
  useEffect(() => { if (thread.data?.context) setContext({ days: thread.data.context.days, campaign_id: thread.data.context.campaign_id }) }, [thread.data?.id]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { if (live.data) dispatch({ type: 'snapshot', live: live.data }) }, [live.data])
  useEffect(() => {
    if (thread.data && thread.data.status !== 'thinking') {
      dispatch({ type: 'done' })
      void qc.invalidateQueries({ queryKey: ['agent-proposals', projectId] })
      void qc.invalidateQueries({ queryKey: ['assistant-settings'] })
    }
  }, [thread.data?.status]) // eslint-disable-line react-hooks/exhaustive-deps
  // Opening ANOTHER conversation resets the stream; the conversation this chat just started keeps it.
  const started = useRef<string | null>(null)
  useEffect(() => {
    if (threadId !== null && threadId === started.current) return
    dispatch({ type: 'done' })
    setSuggestedMemory(null)
  }, [threadId])
  useEffect(() => { const el = listRef.current; if (el) el.scrollTop = el.scrollHeight }, [thread.data?.messages.length, stream.text])

  const send = useMutation({
    meta: { feedback: 'none' },
    mutationFn: (message: string) => sendToAgent(projectId, threadId ? { thread_id: threadId, message, context } : { agent: 'assistant', message, context }),
    onMutate: () => dispatch({ type: 'sent' }),
    onSuccess: (r) => {
      setDraft('')
      setSuggestedMemory(r.suggested_memory ?? null)
      if (r.status !== 'thinking') dispatch({ type: 'done' })
      started.current = r.thread_id
      onThread(r.thread_id)
      void qc.invalidateQueries({ queryKey: ['agent-thread', projectId, r.thread_id] })
      void qc.invalidateQueries({ queryKey: ['agent-threads', projectId] })
      if (r.memory) void qc.invalidateQueries({ queryKey: ['ai-memories'] })
    },
    onError: () => dispatch({ type: 'done' }),
  })
  const stop = useMutation({ meta: { feedback: 'none' }, mutationFn: () => stopReply(projectId, threadId!), onMutate: () => dispatch({ type: 'stop' }) })
  const remember = useMutation({ meta: { feedback: 'none' }, mutationFn: (text: string) => addMemory(text, 'suggested'), onSuccess: () => { setSuggestedMemory(null); void qc.invalidateQueries({ queryKey: ['ai-memories'] }) } })

  // A proposal raised in this conversation that the loaded list does not hold yet: read the list again (once per new id set).
  const wanted = (thread.data?.messages ?? []).flatMap((m) => m.meta?.proposals ?? [])
  const missing = wanted.filter((id) => !(proposals.data?.proposals ?? []).some((p) => p.id === id)).join(',')
  useEffect(() => {
    if (missing !== '' && !proposals.isFetching) {
      void qc.invalidateQueries({ queryKey: ['agent-proposals', projectId] })
      void qc.invalidateQueries({ queryKey: ['assistant-settings'] })
    }
  }, [missing]) // eslint-disable-line react-hooks/exhaustive-deps

  const busy = thinking || send.isPending
  const messages = (thread.data?.messages ?? []).filter((m) => m.role !== 'tool')
  const lastSpecialist = [...messages].reverse().find((m) => m.specialist)?.specialist ?? null
  const lastUser = [...messages].reverse().find((m) => m.role === 'user')?.text ?? null
  const submit = (text: string) => { const t = text.trim(); if (t !== '' && !busy && canUse) send.mutate(t) }
  const suggestions = suggestionsFor(context, lastSpecialist, ar)
  const allProposals = proposals.data?.proposals ?? []

  return (
    <section className="flex min-h-[32rem] min-w-0 flex-1 flex-col" data-testid="assistant-chat" aria-label={ar ? 'المساعد' : 'Assistant'}>
      <ol ref={listRef} className="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto px-1 pb-3" aria-live="polite" data-testid="agent-messages">
        {!threadId && <EmptyState ar={ar} prompts={suggestions} onPick={submit} disabled={!canUse} />}
        {messages.map((m, i) => (
          <Bubble
            key={m.id}
            m={m}
            ar={ar}
            isLast={i === messages.length - 1}
            onRetry={lastUser && !busy && canUse ? () => submit(lastUser) : undefined}
            proposals={allProposals.filter((p) => (m.meta?.proposals ?? []).includes(p.id))}
            projectId={projectId}
            canDecide={canDecide}
          />
        ))}
        {(busy || stream.phase !== 'idle') && (
          <li className="flex justify-start" data-testid="agent-streaming">
            <div className="max-w-[88%] min-w-0 rounded-2xl rounded-ss-sm bg-surface-secondary px-3 py-2">
              {stream.specialist && <SpecialistChip k={stream.specialist} ar={ar} />}
              {stream.text !== ''
                ? <p dir="auto" className="whitespace-pre-wrap text-sm text-text-primary" data-testid="agent-stream-text">{stream.text}<span className="ms-0.5 inline-block h-3.5 w-1.5 animate-pulse rounded-sm bg-text-muted align-middle" aria-hidden /></p>
                : <p className="flex items-center gap-1.5 text-xs text-text-muted" data-testid="agent-thinking"><Loader2 size={12} className="animate-spin" />{stream.phase === 'stopping' ? (ar ? 'يتوقف…' : 'Stopping…') : stream.step ? (ar ? STEP[stream.step].ar : STEP[stream.step].en) : (ar ? 'يقرأ الأرقام…' : 'Reading the figures…')}</p>}
              {stream.tools.length > 0 && <p className="mt-1 flex flex-wrap gap-1">{stream.tools.map((t, i) => <span key={`${t}-${i}`} className="rounded-full border border-border px-1.5 text-xs text-text-muted">{toolText(t, ar)}</span>)}</p>}
            </div>
          </li>
        )}
        {thread.data?.status === 'failed' && (
          <li role="alert" className="rounded-xl bg-[var(--warning-background)] px-3 py-2 text-xs text-warning" data-testid="agent-failed">{reasonText(thread.data.error, ar)}</li>
        )}
      </ol>

      {send.isError && <p role="alert" className="px-1 pb-1 text-xs text-danger" data-testid="agent-send-error">{toApiError(send.error).message}</p>}

      {suggestedMemory && (
        <div className="mb-2 flex min-w-0 items-center gap-2 rounded-xl border border-dashed border-brand-400 px-2.5 py-1.5 text-xs" data-testid="memory-suggestion">
          <Bookmark size={13} className="shrink-0 text-brand-600" aria-hidden />
          <span className="min-w-0 flex-1 truncate" dir="auto">{ar ? 'أتذكّر هذا دائمًا؟' : 'Remember this?'} «{suggestedMemory}»</span>
          <button type="button" onClick={() => remember.mutate(suggestedMemory)} className="font-semibold text-brand-700" data-testid="memory-accept">{ar ? 'تذكّر' : 'Remember'}</button>
          <button type="button" onClick={() => setSuggestedMemory(null)} aria-label={ar ? 'تجاهل' : 'Dismiss'} className="text-text-muted"><X size={13} /></button>
        </div>
      )}

      <ContextChips projectName={projectName} context={context} onChange={setContext} ar={ar} />
      {threadId && !busy && canUse && (
        <div className="mb-1.5 flex gap-1.5 overflow-x-auto pb-0.5" data-testid="agent-suggestions">
          {suggestions.slice(0, 3).map((s) => (
            <button key={s} type="button" onClick={() => submit(s)} className="shrink-0 rounded-full border border-border px-2.5 py-1 text-xs text-text-secondary hover:border-brand-400 hover:text-text-primary">{s}</button>
          ))}
        </div>
      )}

      {canUse
        ? <Composer value={draft} onChange={setDraft} onSubmit={() => submit(draft)} busy={busy} onStop={threadId && thinking ? () => stop.mutate() : undefined} ar={ar} />
        : <p className="text-xs text-text-muted" data-testid="agent-read-only">{ar ? 'يمكنك قراءة المحادثات فقط.' : 'You can read conversations only.'}</p>}
    </section>
  )
}

function EmptyState({ ar, prompts, onPick, disabled }: { ar: boolean; prompts: string[]; onPick: (s: string) => void; disabled: boolean }) {
  return (
    <li className="m-auto w-full max-w-xl py-6 text-center" data-testid="assistant-empty">
      <Sparkles className="mx-auto mb-2 text-brand-600" size={22} aria-hidden />
      <p className="text-base font-bold text-text-primary">{ar ? 'كيف أساعدك اليوم؟' : 'How can I help today?'}</p>
      <div className="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2">
        {prompts.slice(0, 4).map((p) => (
          <button key={p} type="button" disabled={disabled} onClick={() => onPick(p)} data-testid="assistant-prompt" className="rounded-xl border border-border bg-surface px-3 py-2.5 text-start text-sm text-text-primary hover:border-brand-400 disabled:opacity-50">{p}</button>
        ))}
      </div>
    </li>
  )
}

function SpecialistChip({ k, ar }: { k: SpecialistKey; ar: boolean }) {
  return <span className="mb-1 inline-flex items-center gap-1 rounded-full bg-[var(--brand-background)] px-2 py-0.5 text-xs font-semibold text-brand-700" data-testid="specialist-chip">{ar ? SPECIALIST[k].ar : SPECIALIST[k].en}</span>
}

function Bubble({ m, ar, isLast, onRetry, proposals, projectId, canDecide }: {
  m: AgentMessage
  ar: boolean
  isLast: boolean
  onRetry?: () => void
  proposals: import('./api').Proposal[]
  projectId: string
  canDecide: boolean
}) {
  const [copied, setCopied] = useState(false)
  const mine = m.role === 'user'
  const code = m.meta?.code
  if (!m.text && !code && (m.meta?.cards ?? []).length === 0) return null
  const system = m.role === 'system'
  const text = system && code ? reasonText(code, ar) : m.text

  if (mine) {
    return (
      <li className="flex justify-end" data-testid="agent-message-user">
        <p dir="auto" className="max-w-[85%] whitespace-pre-wrap rounded-2xl rounded-se-sm bg-brand-600 px-3 py-2 text-sm text-white">{m.text}</p>
      </li>
    )
  }

  return (
    <li className="flex justify-start" data-testid={`agent-message-${m.role}`}>
      <div className={`max-w-[88%] min-w-0 rounded-2xl rounded-ss-sm px-3 py-2 ${system ? 'bg-[var(--warning-background)] text-warning' : 'bg-surface-secondary text-text-primary'}`}>
        {m.specialist && <SpecialistChip k={m.specialist} ar={ar} />}
        {text && <p dir="auto" className="whitespace-pre-wrap text-sm">{text}</p>}
        {m.meta && <AnswerCards meta={m.meta} ar={ar} />}
        {proposals.length > 0 && <div className="mt-2 space-y-2">{proposals.map((p) => <ProposalCard key={p.id} projectId={projectId} proposal={p} ar={ar} canDecide={canDecide} />)}</div>}
        {!system && m.text && (
          <div className="mt-1 flex gap-2 text-xs text-text-muted">
            <button type="button" onClick={() => { void navigator.clipboard?.writeText(m.text ?? ''); setCopied(true); setTimeout(() => setCopied(false), 1500) }} className="inline-flex items-center gap-0.5 hover:text-text-primary" data-testid="agent-copy">
              {copied ? <Check size={12} /> : <Copy size={12} />}{copied ? (ar ? 'نُسخ' : 'Copied') : (ar ? 'نسخ' : 'Copy')}
            </button>
            {isLast && onRetry && (
              <button type="button" onClick={onRetry} className="inline-flex items-center gap-0.5 hover:text-text-primary" data-testid="agent-retry"><RotateCcw size={12} />{ar ? 'إعادة' : 'Retry'}</button>
            )}
          </div>
        )}
      </div>
    </li>
  )
}

function ContextChips({ projectName, context, onChange, ar }: { projectName: string; context: ThreadContext; onChange: (c: ThreadContext) => void; ar: boolean }) {
  return (
    <div className="mb-1.5 flex flex-wrap items-center gap-1.5 text-xs" data-testid="context-chips" aria-label={ar ? 'ما يراه المساعد الآن' : 'What the assistant sees now'}>
      <span className="rounded-full bg-surface-secondary px-2 py-0.5 text-text-secondary" data-testid="context-project">{ar ? 'المشروع:' : 'Project:'} {projectName}</span>
      {context.days
        ? (
          <span className="inline-flex items-center gap-1 rounded-full bg-surface-secondary px-2 py-0.5 text-text-secondary" data-testid="context-period">
            {ar ? `آخر ${days(context.days, 'ar')}` : `Last ${days(context.days, 'en')}`}
            <button type="button" aria-label={ar ? 'إزالة الفترة' : 'Remove period'} onClick={() => onChange({ ...context, days: undefined })} className="hover:text-text-primary"><X size={11} /></button>
          </span>
        )
        : PERIODS.map((d) => (
          <button key={d} type="button" onClick={() => onChange({ ...context, days: d })} className="rounded-full border border-dashed border-border px-2 py-0.5 text-text-muted hover:text-text-primary" data-testid={`context-add-${d}`}>+ {ar ? `آخر ${days(d, 'ar')}` : `Last ${days(d, 'en')}`}</button>
        ))}
      {context.days === 7 && <button type="button" onClick={() => onChange({ ...context, days: 30 })} className="text-text-muted hover:text-text-primary" data-testid="context-30">{days(30, ar ? 'ar' : 'en')}</button>}
      {context.days === 30 && <button type="button" onClick={() => onChange({ ...context, days: 7 })} className="text-text-muted hover:text-text-primary" data-testid="context-7">{days(7, ar ? 'ar' : 'en')}</button>}
      {context.campaign_id && (
        <span className="inline-flex items-center gap-1 rounded-full bg-surface-secondary px-2 py-0.5 text-text-secondary" data-testid="context-campaign">
          {ar ? 'حملة محددة' : 'One campaign'}
          <button type="button" aria-label={ar ? 'إزالة الحملة' : 'Remove campaign'} onClick={() => onChange({ ...context, campaign_id: undefined })}><X size={11} /></button>
        </span>
      )}
    </div>
  )
}

export function Composer({ value, onChange, onSubmit, busy, onStop, ar }: { value: string; onChange: (v: string) => void; onSubmit: () => void; busy: boolean; onStop?: () => void; ar: boolean }) {
  const ref = useRef<HTMLTextAreaElement>(null)
  // Grows with the text up to a ceiling, then scrolls.
  useLayoutEffect(() => {
    const el = ref.current
    if (!el) return
    el.style.height = 'auto'
    el.style.height = `${Math.min(el.scrollHeight, 176)}px`
  }, [value])

  const onKey = (e: KeyboardEvent<HTMLTextAreaElement>) => {
    // Enter sends; Shift+Enter breaks the line; a word being composed (Arabic IME) is never sent half-way.
    if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
      e.preventDefault()
      onSubmit()
    }
  }

  return (
    <form className="flex items-end gap-2 rounded-2xl border border-border bg-surface p-1.5 focus-within:border-brand-500" onSubmit={(e) => { e.preventDefault(); onSubmit() }} data-testid="agent-composer">
      <label className="min-w-0 flex-1">
        <span className="sr-only">{ar ? 'رسالتك' : 'Your message'}</span>
        <textarea
          ref={ref}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          onKeyDown={onKey}
          rows={1}
          maxLength={4000}
          dir="auto"
          placeholder={ar ? 'اسأل عن حملاتك…' : 'Ask about your campaigns…'}
          data-testid="agent-input"
          className="block max-h-44 w-full resize-none bg-transparent px-2 py-1.5 text-sm text-text-primary outline-none placeholder:text-text-muted"
        />
      </label>
      {onStop
        ? <button type="button" onClick={onStop} aria-label={ar ? 'إيقاف' : 'Stop'} data-testid="agent-stop" className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-text-primary text-surface"><Square size={13} fill="currentColor" /></button>
        : <button type="submit" disabled={busy || value.trim() === ''} aria-label={ar ? 'إرسال' : 'Send'} data-testid="agent-send" className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-600 text-white disabled:opacity-40"><ArrowUp size={16} /></button>}
    </form>
  )
}
