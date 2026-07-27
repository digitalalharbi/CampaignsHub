import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Loader2, Send } from 'lucide-react'
import { getPortalThread, postPortalThreadMessage, type PortalMessage } from './portalAccountApi'
import { PortalShell } from './PortalShell'
import { usePortalGuard } from './usePortalGuard'
import { useUi } from '@/stores/ui'

const COPY = {
  ar: {
    title: 'المحادثة', back: 'الرسائل', error: 'تعذّر تحميل المحادثة.',
    none: 'لا توجد رسائل بعد.', reply: 'اكتب ردّك…', send: 'إرسال', you: 'أنت', team: 'الفريق',
  },
  en: {
    title: 'Conversation', back: 'Messages', error: 'Could not load the conversation.',
    none: 'No messages yet.', reply: 'Write your reply…', send: 'Send', you: 'You', team: 'Team',
  },
}

export function ClientThreadPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const t = ar ? COPY.ar : COPY.en
  const qc = useQueryClient()
  const { id = '' } = useParams()
  const q = useQuery({ queryKey: ['client', 'thread', id], queryFn: () => getPortalThread(id), retry: false })
  usePortalGuard(q.isError, q.error)

  const [body, setBody] = useState('')

  const reply = useMutation({
    mutationFn: () => postPortalThreadMessage(id, body.trim()),
    onSuccess: () => {
      setBody('')
      qc.invalidateQueries({ queryKey: ['client', 'thread', id] })
      qc.invalidateQueries({ queryKey: ['client', 'threads'] })
    },
  })

  if (q.isLoading) return <PortalShell title={t.title} nav showLogout><div className="h-64 animate-pulse rounded-2xl bg-surface-secondary" /></PortalShell>
  if (q.isError) return <PortalShell title={t.title} nav showLogout><div className="rounded-2xl border border-danger/30 bg-[var(--negative-background)] p-6 text-center text-sm text-danger">{t.error}</div></PortalShell>
  const { thread, messages } = q.data!

  return (
    <PortalShell title={t.title} nav showLogout>
      <Link to="/client/messages" className="mb-4 inline-flex items-center gap-1.5 text-sm font-semibold text-text-secondary hover:text-text-primary"><ArrowLeft size={15} className="rtl:rotate-180" /> {t.back}</Link>

      <div className="rounded-2xl border border-border bg-surface p-5 sm:p-6">
        <h1 className="font-heading text-lg font-extrabold text-text-primary">{thread.subject}</h1>

        <div className="mt-4 space-y-2">
          {messages.length === 0 && <p className="text-sm text-text-muted">{t.none}</p>}
          {messages.map((m) => <MessageBubble key={m.id} m={m} you={t.you} team={t.team} />)}
        </div>

        <form className="mt-4 grid gap-2" onSubmit={(e) => { e.preventDefault(); if (body.trim().length >= 1) reply.mutate() }}>
          <textarea value={body} onChange={(e) => setBody(e.target.value)} rows={3} maxLength={20000} placeholder={t.reply} aria-label={t.reply}
            className="w-full rounded-xl border border-border bg-surface px-3.5 py-2.5 text-sm outline-none focus:border-brand-500" />
          <button type="submit" disabled={body.trim().length < 1 || reply.isPending} className="ms-auto flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60">
            {reply.isPending ? <Loader2 size={15} className="animate-spin" /> : <Send size={15} />} {t.send}
          </button>
        </form>
      </div>
    </PortalShell>
  )
}

function MessageBubble({ m, you, team }: { m: PortalMessage; you: string; team: string }) {
  const mine = m.author_type === 'client'
  return (
    <div className={`rounded-xl border border-border px-3 py-2 text-sm ${mine ? 'bg-brand-primary-soft/40' : 'bg-surface-secondary'}`}>
      <div className="text-[11px] font-semibold text-text-secondary">
        {mine ? you : team} · <span className="tnum">{m.created_at ? new Date(m.created_at).toLocaleString('en-CA') : ''}</span>
      </div>
      <div className="whitespace-pre-wrap text-text-primary">{m.body}</div>
    </div>
  )
}
