import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft } from 'lucide-react'
import { ChatComposer, ChatMessages } from './PortalChat'
import { getPortalThread, postPortalThreadMessage, type PortalMessage } from './portalAccountApi'
import { PortalShell } from './PortalShell'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { usePortalGuard } from './usePortalGuard'
import { useUi } from '@/stores/ui'
import { useClientSpacePath } from './clientSpace'
import { formatNumber } from '@/lib/numerals'

const COPY = {
  ar: {
    title: 'المحادثة', back: 'الرسائل', error: 'تعذّر تحميل المحادثة.',
    none: 'لا توجد رسائل بعد.', reply: 'اكتب ردّك…', send: 'إرسال', you: 'أنت', team: 'الفريق',
    withheld: 'هذه أحدث {shown} رسالة من {total}.', older: 'عرض الرسائل الأقدم',
    loading_older: 'جارٍ التحميل…', at_start: 'بداية المحادثة.', newest: 'الانتقال إلى الأحدث',
  },
  en: {
    title: 'Conversation', back: 'Messages', error: 'Could not load the conversation.',
    none: 'No messages yet.', reply: 'Write your reply…', send: 'Send', you: 'You', team: 'Team',
    withheld: 'These are the latest {shown} of {total} messages.', older: 'Show older messages',
    loading_older: 'Loading…', at_start: 'The start of the conversation.', newest: 'Jump to the newest',
  },
}

export function ClientThreadPage() {
  const spaceTo = useClientSpacePath()
  const ar = useUi((s) => s.locale) === 'ar'
  const t = ar ? COPY.ar : COPY.en
  const qc = useQueryClient()
  const { id = '' } = useParams()
  /* The window the reader has paged back to. A cursor, not an offset — the thread grows as it is read. */
  const [before, setBefore] = useState<string | null>(null)
  const q = useQuery({
    queryKey: ['client', 'thread', id, before],
    queryFn: () => getPortalThread(id, before),
    retry: false,
    placeholderData: (prev) => prev,
  })
  usePortalGuard(q.isError, q.error)

  const reply = useMutation({
    mutationFn: (text: string) => postPortalThreadMessage(id, text),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['client', 'thread', id] })
      qc.invalidateQueries({ queryKey: ['client', 'threads'] })
    },
  })

  if (q.isLoading) return <PortalShell title={t.title} nav showLogout><div className="h-64 animate-pulse rounded-2xl bg-surface-secondary" /></PortalShell>
  if (q.isError) return <PortalShell title={t.title} nav showLogout><QueryFailure error={q.error} ar={ar} onRetry={() => void q.refetch()} fallbackTitle={t.error} testId="portal-failure" /></PortalShell>
  const { thread, messages } = q.data!
  /*
    MESSAGE-THREAD-TRUTH-001 — this page used to show the OLDEST five hundred messages of a long
    conversation, so the reply sent this morning was simply not on it, and the route marked the whole
    thread read on the way out. It shows the newest now, and says when the window hid older ones —
    a windowed page that ends without saying where it began reads as the whole conversation.
  */
  const withheld = q.data!.messages_withheld ?? 0

  return (
    <PortalShell title={t.title} nav showLogout>
      <Link to={spaceTo('/messages')} className="mb-4 inline-flex items-center gap-1.5 text-sm font-semibold text-text-secondary hover:text-text-primary"><ArrowLeft size={15} className="rtl:rotate-180" /> {t.back}</Link>

      <div className="rounded-2xl border border-border bg-surface p-5 sm:p-6">
        <h1 className="font-heading text-lg font-extrabold text-text-primary">{thread.subject}</h1>

        <div className="mt-4 space-y-2">
          {(withheld > 0 || before !== null) && (
            <div
              data-testid="portal-thread-window-note"
              className="flex flex-col items-center gap-1 rounded-xl border border-border bg-surface-secondary px-3 py-2 text-center text-xs text-text-secondary"
            >
              <span>
                {withheld > 0
                  ? t.withheld
                      .replace('{shown}', formatNumber(messages.length))
                      .replace('{total}', formatNumber(q.data!.messages_total ?? messages.length + withheld))
                  : t.at_start}
              </span>
              <div className="flex items-center gap-3">
                {q.data!.older_before ? (
                  <button
                    type="button"
                    onClick={() => setBefore(q.data!.older_before ?? null)}
                    disabled={q.isFetching}
                    data-testid="portal-thread-older"
                    className="font-semibold text-brand-600 hover:underline disabled:opacity-50"
                  >
                    {q.isFetching ? t.loading_older : t.older}
                  </button>
                ) : null}
                {before !== null ? (
                  <button
                    type="button"
                    onClick={() => setBefore(null)}
                    data-testid="portal-thread-newest"
                    className="font-semibold text-text-secondary hover:underline"
                  >
                    {t.newest}
                  </button>
                ) : null}
              </div>
            </div>
          )}
          {/* CLIENT-CHAT-001 — a chat: the client's own messages on one side with ✓ / ✓✓, the team's on the other. */}
          <ChatMessages
            team={t.team}
            empty={t.none}
            messages={messages.map((m: PortalMessage) => ({ id: m.id, mine: m.author_type === 'client', body: m.body, at: m.created_at, readAt: m.read_by_team_at ?? null }))}
          />
        </div>

        <ChatComposer maxLength={20000} sending={reply.isPending} placeholder={t.reply} onSend={(text) => reply.mutate(text)} />
      </div>
    </PortalShell>
  )
}
