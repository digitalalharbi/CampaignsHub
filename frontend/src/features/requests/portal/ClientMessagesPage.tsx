import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowRight, Loader2, MessagesSquare, Plus, Send, X } from 'lucide-react'
import { listPortalThreads, openPortalThread, formatDate, type PortalThread } from './portalAccountApi'
import { PortalShell } from './PortalShell'
import { usePortalGuard } from './usePortalGuard'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

const COPY = {
  ar: {
    title: 'الرسائل', subtitle: 'تواصل مع فريقك وتابع محادثاتك.',
    none: 'لا توجد محادثات بعد.', error: 'تعذّر تحميل الرسائل.',
    new: 'محادثة جديدة', subject: 'الموضوع', body: 'رسالتك', send: 'إرسال', cancel: 'إلغاء',
    unread: 'غير مقروء', open: 'فتح', create_error: 'تعذّر بدء المحادثة. حاول مرة أخرى.',
  },
  en: {
    title: 'Messages', subtitle: 'Talk to your team and follow your conversations.',
    none: 'No conversations yet.', error: 'Could not load messages.',
    new: 'New conversation', subject: 'Subject', body: 'Your message', send: 'Send', cancel: 'Cancel',
    unread: 'unread', open: 'Open', create_error: 'Could not start the conversation. Please try again.',
  },
}

export function ClientMessagesPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const t = ar ? COPY.ar : COPY.en
  const navigate = useNavigate()
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['client', 'threads'], queryFn: listPortalThreads, retry: false })
  usePortalGuard(q.isError, q.error)

  const [composing, setComposing] = useState(false)
  const [subject, setSubject] = useState('')
  const [body, setBody] = useState('')
  const [createError, setCreateError] = useState<string | null>(null)

  const open = useMutation({
    mutationFn: () => openPortalThread(subject.trim(), body.trim()),
    onSuccess: (thread) => {
      setComposing(false); setSubject(''); setBody(''); setCreateError(null)
      qc.invalidateQueries({ queryKey: ['client', 'threads'] })
      navigate(`/client/messages/${thread.id}`)
    },
    onError: (e) => setCreateError(toApiError(e).message || t.create_error),
  })

  const rows = q.data ?? []

  return (
    <PortalShell title={t.title} nav showLogout>
      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="font-heading text-2xl font-extrabold text-text-primary">{t.title}</h1>
          <p className="mt-1 text-sm text-text-secondary">{t.subtitle}</p>
        </div>
        {!composing && (
          <button onClick={() => setComposing(true)} className="flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700"><Plus size={16} /> {t.new}</button>
        )}
      </div>

      {composing && (
        <form
          className="mb-5 grid gap-3 rounded-2xl border border-border bg-surface p-5"
          onSubmit={(e) => { e.preventDefault(); if (subject.trim().length >= 2 && body.trim().length >= 1) open.mutate() }}
        >
          <div className="flex items-center justify-between">
            <h2 className="text-sm font-bold text-text-primary">{t.new}</h2>
            <button type="button" onClick={() => { setComposing(false); setCreateError(null) }} aria-label={t.cancel} className="rounded-lg p-1 text-text-muted hover:text-text-primary"><X size={16} /></button>
          </div>
          <input value={subject} onChange={(e) => setSubject(e.target.value)} maxLength={200} placeholder={t.subject} aria-label={t.subject}
            className="h-11 w-full rounded-xl border border-border bg-surface px-3.5 text-sm outline-none focus:border-brand-500" />
          <textarea value={body} onChange={(e) => setBody(e.target.value)} rows={3} maxLength={20000} placeholder={t.body} aria-label={t.body}
            className="w-full rounded-xl border border-border bg-surface px-3.5 py-2.5 text-sm outline-none focus:border-brand-500" />
          {createError && <p className="rounded-lg bg-[var(--negative-background)] px-3 py-2 text-sm text-danger">{createError}</p>}
          <button type="submit" disabled={subject.trim().length < 2 || body.trim().length < 1 || open.isPending} className="ms-auto flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60">
            {open.isPending ? <Loader2 size={15} className="animate-spin" /> : <Send size={15} />} {t.send}
          </button>
        </form>
      )}

      {q.isLoading ? (
        <div className="space-y-2">{[0, 1, 2].map((i) => <div key={i} className="h-16 animate-pulse rounded-2xl bg-surface-secondary" />)}</div>
      ) : q.isError ? (
        <div className="rounded-2xl border border-danger/30 bg-[var(--negative-background)] p-6 text-center text-sm text-danger">{t.error}</div>
      ) : rows.length === 0 ? (
        <div className="flex flex-col items-center gap-2 rounded-2xl border border-border bg-surface p-12 text-center text-text-muted"><MessagesSquare size={26} /><span className="text-sm">{t.none}</span></div>
      ) : (
        <ul className="grid gap-2">
          {rows.map((th) => <ThreadRow key={th.id} th={th} unreadLabel={t.unread} />)}
        </ul>
      )}
    </PortalShell>
  )
}

function ThreadRow({ th, unreadLabel }: { th: PortalThread; unreadLabel: string }) {
  return (
    <li>
      <Link to={`/client/messages/${th.id}`} className="flex items-center justify-between gap-3 rounded-2xl border border-border bg-surface p-4 hover:border-brand-400">
        <div className="flex min-w-0 items-center gap-3">
          {th.unread > 0 && <span className="h-2.5 w-2.5 shrink-0 rounded-full bg-brand-500" aria-hidden />}
          <div className="min-w-0">
            <div className="truncate text-sm font-semibold text-text-primary">{th.subject}</div>
            <div className="text-[11px] text-text-muted"><span className="tnum">{formatDate(th.last_message_at)}</span></div>
          </div>
        </div>
        <div className="flex shrink-0 items-center gap-2">
          {th.unread > 0 && <span className="tnum rounded-full bg-brand-primary-soft px-2 py-0.5 text-[11px] font-semibold text-brand-700">{th.unread} {unreadLabel}</span>}
          <ArrowRight size={14} className="text-text-muted rtl:rotate-180" />
        </div>
      </Link>
    </li>
  )
}
