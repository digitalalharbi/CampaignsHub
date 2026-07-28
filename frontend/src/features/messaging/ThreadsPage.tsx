import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCheck, Inbox, MessagesSquare, Plus, Send, X } from 'lucide-react'
import { useUi } from '@/stores/ui'
import { useAuth } from '@/stores/auth'
import {
  formatDateTime, getThread, listThreads, markThreadRead, openThread, postTeamReply,
  type MessageThread, type ThreadStatus,
} from './api'

const COPY = {
  ar: {
    title: 'المحادثات', subtitle: 'صندوق وارد فريق العمل — تابع محادثات العملاء وردّ عليها.',
    filter_open: 'مفتوحة', filter_closed: 'مغلقة', filter_all: 'الكل',
    none: 'لا توجد محادثات.', error: 'تعذّر تحميل المحادثات.', loading: 'جارٍ التحميل…',
    pick: 'اختر محادثة لعرضها.', unread: 'غير مقروءة', mark_read: 'تحديد كمقروء', marking: 'جارٍ…',
    reply_ph: 'اكتب ردًا باسم الفريق…', send: 'إرسال', sending: 'جارٍ الإرسال…',
    new_thread: 'محادثة جديدة', subject: 'الموضوع', body: 'الرسالة الأولى', create: 'بدء المحادثة',
    creating: 'جارٍ الإنشاء…', optional: 'اختياري', close: 'إغلاق', team: 'الفريق', client: 'العميل', system: 'النظام',
    last_activity: 'آخر نشاط', no_messages: 'لا توجد رسائل بعد.',
  },
  en: {
    title: 'Messages', subtitle: 'The team inbox — follow and reply to client conversations.',
    filter_open: 'Open', filter_closed: 'Closed', filter_all: 'All',
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
  const c = COPY[locale]
  const canManage = useAuth((s) => s.hasPermission('messaging.manage'))
  const [filter, setFilter] = useState<ThreadStatus | 'all'>('open')
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [composing, setComposing] = useState(false)
  const qc = useQueryClient()

  const q = useQuery({
    queryKey: ['messaging', 'threads', filter],
    queryFn: () => listThreads(filter === 'all' ? undefined : filter),
    refetchInterval: 30_000,
  })
  const threads = q.data ?? []

  const filterLabel = (f: ThreadStatus | 'all') =>
    f === 'open' ? c.filter_open : f === 'closed' ? c.filter_closed : c.filter_all

  return (
    <div className="mx-auto flex w-full max-w-6xl flex-col gap-5 p-4 md:p-6">
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

      <div className="flex gap-2">
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

      <div className="grid gap-4 md:grid-cols-[320px_1fr]">
        <div className="flex flex-col gap-2">
          {q.isLoading ? (
            <p className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-text-secondary">{c.loading}</p>
          ) : q.isError ? (
            <p className="rounded-xl border border-danger/30 bg-danger/5 p-8 text-center text-sm text-danger">{c.error}</p>
          ) : threads.length === 0 ? (
            <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border p-10 text-center text-text-secondary">
              <Inbox size={22} /><span className="text-sm">{c.none}</span>
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
  threadId, c, canManage, onChanged,
}: { threadId: string; c: Copy; canManage: boolean; onChanged: () => void }) {
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
  if (q.isError || !q.data) return <p className="rounded-2xl border border-danger/30 bg-danger/5 p-8 text-center text-sm text-danger">{c.error}</p>

  const { thread, messages, unread } = q.data
  const authorLabel = (t: string) => (t === 'team' ? c.team : t === 'client' ? c.client : c.system)

  return (
    <div className="flex h-full flex-col gap-3 rounded-2xl border border-border bg-surface p-4">
      <div className="flex items-start justify-between gap-3 border-b border-border pb-3">
        <div className="flex flex-col gap-1">
          <h2 className="font-extrabold text-text-primary">{thread.subject}</h2>
          <span className="text-[11px] text-text-tertiary">{c.last_activity}: <span className="tnum" dir="ltr">{formatDateTime(thread.last_message_at)}</span></span>
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
