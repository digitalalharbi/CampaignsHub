import { StatCard } from '@/components/ui/StatCard'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCheck, Inbox, MessagesSquare, Plus, Search, Send, X } from 'lucide-react'
import { useUi } from '@/stores/ui'
import { useAuth } from '@/stores/auth'
import { QueryFailure } from '@/components/ui/QueryFailure'
import {
  formatDateTime, getThread, listThreads, markThreadRead, openThread, postTeamReply,
  type MessageThread, type ThreadStatus,
} from './api'

const COPY = {
  ar: {
    title: 'المحادثات', subtitle: 'صندوق وارد فريق العمل — تابع محادثات العملاء وردّ عليها.',
    filter_open: 'مفتوحة', filter_closed: 'مغلقة', filter_all: 'الكل',
    search_ph: 'ابحث بالموضوع…', no_match: 'لا محادثات تطابق البحث أو الفلتر.',
    sum_total: 'إجمالي المحادثات', sum_open: 'مفتوحة', sum_closed: 'مغلقة', sum_recent: 'نشطة خلال 7 أيام',
    ctx_client: 'ملف العميل', ctx_request: 'الطلب المرتبط', ctx_project: 'المشروع',
    none: 'لا توجد محادثات.', error: 'تعذّر تحميل المحادثات.', loading: 'جارٍ التحميل…',
    pick: 'اختر محادثة لعرضها.', unread: 'غير مقروءة', mark_read: 'تحديد كمقروء', marking: 'جارٍ…',
    reply_ph: 'اكتب ردًا باسم الفريق…', send: 'إرسال', sending: 'جارٍ الإرسال…',
    new_thread: 'محادثة جديدة', subject: 'الموضوع', body: 'الرسالة الأولى', create: 'بدء المحادثة',
    creating: 'جارٍ الإنشاء…', optional: 'اختياري', close: 'إغلاق', team: 'الفريق', client: 'العميل', system: 'النظام',
    last_activity: 'آخر نشاط', no_messages: 'لا توجد رسائل بعد.',
  },
  en: {
    title: 'Conversations', subtitle: 'The team inbox — follow and reply to client conversations.',
    filter_open: 'Open', filter_closed: 'Closed', filter_all: 'All',
    search_ph: 'Search by subject…', no_match: 'No conversations match your search or filter.',
    sum_total: 'Total conversations', sum_open: 'Open', sum_closed: 'Closed', sum_recent: 'Active in 7 days',
    ctx_client: 'Client profile', ctx_request: 'Linked request', ctx_project: 'Project',
    none: 'No threads.', error: 'Could not load threads.', loading: 'Loading…',
    pick: 'Pick a thread to view it.', unread: 'unread', mark_read: 'Mark read', marking: 'Marking…',
    reply_ph: 'Write a reply as the team…', send: 'Send', sending: 'Sending…',
    new_thread: 'New thread', subject: 'Subject', body: 'Opening message', create: 'Start thread',
    creating: 'Creating…', optional: 'optional', close: 'Close', team: 'Team', client: 'Client', system: 'System',
    last_activity: 'Last activity', no_messages: 'No messages yet.',
  },
}

const FILTERS: (ThreadStatus | 'all')[] = ['open', 'closed', 'all']

type Copy = (typeof COPY)['ar']

export function ThreadsPage() {
  const locale = useUi((s) => s.locale)
  const ar = locale === 'ar'
  const c = COPY[locale]
  const canManage = useAuth((s) => s.hasPermission('messaging.manage'))
  const [filter, setFilter] = useState<ThreadStatus | 'all'>('open')
  const [term, setTerm] = useState('')
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [composing, setComposing] = useState(false)
  const qc = useQueryClient()

  // Fetch the full thread list once so the summary and filters stay client-side (the list endpoint carries status).
  const q = useQuery({
    queryKey: ['messaging', 'threads', 'all'],
    queryFn: () => listThreads(),
    refetchInterval: 30_000,
  })
  const all = q.data ?? []

  const weekAgo = Date.now() - 7 * 24 * 60 * 60 * 1000
  const summary = {
    total: all.length,
    open: all.filter((t) => t.status === 'open').length,
    closed: all.filter((t) => t.status === 'closed').length,
    recent: all.filter((t) => t.last_message_at && new Date(t.last_message_at).getTime() >= weekAgo).length,
  }

  const filterLabel = (f: ThreadStatus | 'all') =>
    f === 'open' ? c.filter_open : f === 'closed' ? c.filter_closed : c.filter_all

  const needle = term.trim().toLowerCase()
  const threads = all.filter((t) => {
    if (filter !== 'all' && t.status !== filter) return false
    if (needle && !t.subject.toLowerCase().includes(needle)) return false
    return true
  })

  return (
    <div className="flex w-full flex-col gap-4">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex flex-col gap-1">
          <h1 className="text-3xl font-extrabold tracking-tight text-text-primary">{c.title}</h1>
          <p className="text-sm text-text-secondary">{c.subtitle}</p>
        </div>
        {canManage ? (
          <button onClick={() => setComposing(true)}
            className="flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-sm font-bold text-white hover:bg-brand-700">
            <Plus size={15} /> {c.new_thread}
          </button>
        ) : null}
      </header>

      {/* Summary — the inbox at a glance. */}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <ThreadSummaryCard label={c.sum_total} value={summary.total} tone="brand" unknown={q.isError} />
        <ThreadSummaryCard label={c.sum_open} value={summary.open} tone="warning" unknown={q.isError} />
        <ThreadSummaryCard label={c.sum_closed} value={summary.closed} tone="success" unknown={q.isError} />
        <ThreadSummaryCard label={c.sum_recent} value={summary.recent} tone="muted" unknown={q.isError} />
      </div>

      {/* Search + status filters. */}
      <div className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-3 sm:flex-row sm:items-center sm:justify-between">
        <label className="relative flex w-full items-center sm:max-w-xs">
          <Search size={15} className="pointer-events-none absolute start-3 text-text-muted" aria-hidden />
          <input
            value={term}
            onChange={(e) => setTerm(e.target.value)}
            placeholder={c.search_ph}
            className="w-full rounded-xl border border-border bg-surface-secondary py-2 pe-3 ps-9 text-sm text-text-primary placeholder:text-text-muted focus:border-brand-500 focus:outline-none"
          />
        </label>
        <div className="flex flex-wrap gap-2">
          {FILTERS.map((f) => (
            <button
              key={f}
              onClick={() => setFilter(f)}
              className={`rounded-full px-3 py-1 text-xs font-semibold ${
                filter === f ? 'bg-brand-500 text-white' : 'bg-surface-hover text-text-secondary hover:text-text-primary'
              }`}
            >
              {filterLabel(f)}
            </button>
          ))}
        </div>
      </div>

      <div className="grid gap-4 md:grid-cols-[320px_1fr]">
        <div className="flex flex-col gap-2">
          {q.isLoading ? (
            <p className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-text-secondary">{c.loading}</p>
          ) : q.isError ? (
            // AGENCY-PERMS — a member without `messaging.view` is refused, not broken.
            <QueryFailure error={q.error} ar={ar} fallbackTitle={c.error} testId="threads-failure" onRetry={() => q.refetch()} />
          ) : threads.length === 0 ? (
            <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border p-10 text-center text-text-secondary">
              <Inbox size={22} /><span className="text-sm">{all.length === 0 ? c.none : c.no_match}</span>
            </div>
          ) : (
            threads.map((t) => (
              <ThreadRow key={t.id} thread={t} c={c} active={selectedId === t.id} onClick={() => setSelectedId(t.id)} />
            ))
          )}
        </div>

        <div className="min-h-[300px]">
          {selectedId ? (
            <ThreadDetailPanel
              threadId={selectedId}
              c={c}
              ar={ar}
              canManage={canManage}
              onChanged={() => qc.invalidateQueries({ queryKey: ['messaging', 'threads'] })}
            />
          ) : (
            <div className="flex h-full min-h-[300px] flex-col items-center justify-center gap-2 rounded-2xl border border-dashed border-border text-text-secondary">
              <MessagesSquare size={26} /><span className="text-sm">{c.pick}</span>
            </div>
          )}
        </div>
      </div>

      {composing ? (
        <ComposeModal c={c} onClose={() => setComposing(false)} onCreated={(id) => {
          setComposing(false)
          setSelectedId(id)
          qc.invalidateQueries({ queryKey: ['messaging', 'threads'] })
        }} />
      ) : null}
    </div>
  )
}

/**
 * A count, or «—» when the list could not be read.
 *
 * `unknown` exists because the refusal arm below left these cards reading 0 · 0 · 0 · 0 — which is
 * a claim, and a false one: a member without `messaging.view` has no idea how many conversations
 * exist, and neither do we. Zero is the answer to "how many are there"; this is the answer to
 * "we were not allowed to look".
 */
function ThreadSummaryCard({ label, value, tone, unknown }: { label: string; value: number; tone: 'brand' | 'warning' | 'success' | 'muted'; unknown?: boolean }) {
  /*
   * «We were not allowed to look» keeps its own colour by keeping its own DASH.
   *
   * The card's tone tints the figure, and `neutral` is what the primitive gives a dash — so the muted
   * reading survives the move without this page owning a second palette to say it.
   */
  return (
    <StatCard
      label={label}
      value={unknown ? '—' : value}
      tone={unknown || tone === 'muted' ? 'neutral' : tone}
      dot
    />
  )
}

function ThreadRow({ thread, c, active, onClick }: { thread: MessageThread; c: Copy; active: boolean; onClick: () => void }) {
  // Unread is only known once a thread's detail is fetched (the list endpoint doesn't carry it). We read it
  // from the query cache when present — no fabricated counts.
  const detail = useQuery({ queryKey: ['messaging', 'thread', thread.id], queryFn: () => getThread(thread.id), enabled: false })
  const unread = detail.data?.unread.team ?? 0
  return (
    <button
      onClick={onClick}
      className={`flex flex-col gap-1 rounded-2xl border bg-surface p-3.5 text-start transition-colors hover:border-brand-400 ${
        active ? 'border-brand-500' : 'border-border'
      }`}
    >
      <div className="flex items-start justify-between gap-2">
        <span className="line-clamp-1 font-semibold text-text-primary">{thread.subject}</span>
        {unread > 0 ? (
          <span className="tnum shrink-0 rounded-full bg-brand-600 px-1.5 py-0.5 text-[10px] font-bold text-white" dir="ltr">{unread}</span>
        ) : null}
      </div>
      <span className="text-[11px] text-text-tertiary">{c.last_activity}: <span className="tnum" dir="ltr">{formatDateTime(thread.last_message_at)}</span></span>
    </button>
  )
}

function ThreadDetailPanel({
  threadId, c, ar, canManage, onChanged,
}: { threadId: string; c: Copy; ar: boolean; canManage: boolean; onChanged: () => void }) {
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['messaging', 'thread', threadId], queryFn: () => getThread(threadId) })
  const [draft, setDraft] = useState('')

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['messaging', 'thread', threadId] })
    onChanged()
  }
  const replyM = useMutation({
    mutationFn: (body: string) => postTeamReply(threadId, body),
    onSuccess: () => { setDraft(''); invalidate() },
  })
  const readM = useMutation({ mutationFn: () => markThreadRead(threadId, 'team'), onSuccess: invalidate })

  if (q.isLoading) return <p className="rounded-2xl border border-dashed border-border p-8 text-center text-sm text-text-secondary">{c.loading}</p>
  // `!q.data` matters as much as `isError`: between retries neither is true, and dereferencing the
  // missing payload below would take the panel down instead of explaining itself.
  if (q.isError || !q.data) {
    return <QueryFailure error={q.error} ar={ar} fallbackTitle={c.error} testId="thread-detail-failure" onRetry={() => q.refetch()} />
  }

  const { thread, messages, unread } = q.data
  const authorLabel = (t: string) => (t === 'team' ? c.team : t === 'client' ? c.client : c.system)

  return (
    <div className="flex h-full flex-col gap-3 rounded-2xl border border-border bg-surface p-4">
      <div className="flex items-start justify-between gap-3 border-b border-border pb-3">
        <div className="flex flex-col gap-1">
          <h2 className="font-extrabold text-text-primary">{thread.subject}</h2>
          <span className="text-[11px] text-text-tertiary">{c.last_activity}: <span className="tnum" dir="ltr">{formatDateTime(thread.last_message_at)}</span></span>
          {/* Context linkage — jump to the entities this conversation is about. */}
          {(thread.client_workspace_id || thread.request_id || thread.project_id) && (
            <div className="mt-1 flex flex-wrap gap-1.5">
              {thread.client_workspace_id && (
                <Link to={`/app/clients/${thread.client_workspace_id}`}
                  className="rounded-md bg-surface-hover px-1.5 py-0.5 text-[11px] font-semibold text-text-secondary hover:text-brand-600">
                  {c.ctx_client}
                </Link>
              )}
              {thread.request_id && (
                <Link to={`/app/requests/${thread.request_id}`}
                  className="rounded-md bg-surface-hover px-1.5 py-0.5 text-[11px] font-semibold text-text-secondary hover:text-brand-600">
                  {c.ctx_request}
                </Link>
              )}
              {thread.project_id && (
                <Link to="/app/projects"
                  className="rounded-md bg-surface-hover px-1.5 py-0.5 text-[11px] font-semibold text-text-secondary hover:text-brand-600">
                  {c.ctx_project}
                </Link>
              )}
            </div>
          )}
        </div>
        <div className="flex items-center gap-2">
          {unread.team > 0 ? (
            <span className="tnum rounded-full bg-brand-600/15 px-2 py-0.5 text-[11px] font-semibold text-brand-600" dir="ltr">
              {unread.team} {c.unread}
            </span>
          ) : null}
          {canManage && unread.team > 0 ? (
            <button onClick={() => readM.mutate()} disabled={readM.isPending}
              className="flex items-center gap-1 rounded-lg border border-border px-2 py-1 text-xs font-semibold text-text-secondary hover:border-success hover:text-success disabled:opacity-50">
              <CheckCheck size={13} /> {readM.isPending ? c.marking : c.mark_read}
            </button>
          ) : null}
        </div>
      </div>

      <div className="flex flex-1 flex-col gap-2 overflow-y-auto">
        {messages.length === 0 ? (
          <p className="p-6 text-center text-sm text-text-secondary">{c.no_messages}</p>
        ) : (
          messages.map((m) => (
            <div key={m.id} className={`flex flex-col gap-1 rounded-xl p-3 ${m.author_type === 'team' ? 'bg-brand-600/10' : 'bg-surface-hover'}`}>
              <div className="flex items-center justify-between gap-2 text-[11px] font-semibold text-text-secondary">
                <span>{authorLabel(m.author_type)}</span>
                <span className="tnum text-text-tertiary" dir="ltr">{formatDateTime(m.created_at)}</span>
              </div>
              <p className="whitespace-pre-wrap text-sm text-text-primary">{m.body}</p>
            </div>
          ))
        )}
      </div>

      {canManage ? (
        <form
          onSubmit={(e) => { e.preventDefault(); if (draft.trim()) replyM.mutate(draft.trim()) }}
          className="flex items-end gap-2 border-t border-border pt-3"
        >
          <textarea
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            rows={2}
            placeholder={c.reply_ph}
            className="flex-1 resize-none rounded-lg border border-border bg-background px-3 py-2 text-sm text-text-primary"
          />
          <button type="submit" disabled={replyM.isPending || !draft.trim()}
            className="flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-sm font-bold text-white hover:bg-brand-700 disabled:opacity-50">
            <Send size={15} className="rtl:rotate-180" /> {replyM.isPending ? c.sending : c.send}
          </button>
        </form>
      ) : null}
    </div>
  )
}

function ComposeModal({ c, onClose, onCreated }: { c: Copy; onClose: () => void; onCreated: (id: string) => void }) {
  const [subject, setSubject] = useState('')
  const [body, setBody] = useState('')
  const createM = useMutation({
    mutationFn: () => openThread({ subject: subject.trim(), body: body.trim() || undefined }),
    onSuccess: (thread) => onCreated(thread.id),
  })
  return (
    <div className="fixed inset-0 z-40 flex items-center justify-center bg-black/30 p-4" onClick={onClose}>
      <form
        onClick={(e) => e.stopPropagation()}
        onSubmit={(e) => { e.preventDefault(); if (subject.trim().length >= 2) createM.mutate() }}
        className="flex w-full max-w-md flex-col gap-3 rounded-2xl bg-surface p-5 shadow-xl"
      >
        <div className="flex items-center justify-between">
          <h2 className="text-lg font-extrabold text-text-primary">{c.new_thread}</h2>
          <button type="button" onClick={onClose} className="rounded-lg p-1.5 text-text-secondary hover:bg-surface-hover" aria-label={c.close}><X size={18} /></button>
        </div>
        <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
          {c.subject}
          <input required minLength={2} maxLength={200} value={subject} onChange={(e) => setSubject(e.target.value)}
            className="rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm text-text-primary" />
        </label>
        <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
          {`${c.body} (${c.optional})`}
          <textarea value={body} onChange={(e) => setBody(e.target.value)} rows={3} maxLength={20000}
            className="rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm text-text-primary" />
        </label>
        <button type="submit" disabled={createM.isPending || subject.trim().length < 2}
          className="rounded-lg bg-brand-600 px-3 py-2 text-sm font-bold text-white hover:bg-brand-700 disabled:opacity-50">
          {createM.isPending ? c.creating : c.create}
        </button>
      </form>
    </div>
  )
}
