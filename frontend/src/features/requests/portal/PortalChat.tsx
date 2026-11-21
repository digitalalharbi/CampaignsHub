import { useEffect, useRef, useState, type ReactNode } from 'react'
import { Check, CheckCheck, Loader2, Paperclip, SendHorizontal } from 'lucide-react'
import { useUi } from '@/stores/ui'

/**
 * CLIENT-CHAT-001 — the client's conversation, drawn as a chat: the client's own messages on one side, the team's on the
 * other, the day between them, and on the client's own message ✓ (sent) or ✓✓ (read by the team) — the way the client
 * already reads WhatsApp. Shared by a request's conversation and a message thread, so the two never differ.
 */
export interface ChatMessage {
  id: string
  mine: boolean
  body: string
  at: string | null
  /** For the client's own message: when the team read it (null: not yet). */
  readAt?: string | null
}

const time = (iso: string, ar: boolean) => new Intl.DateTimeFormat(ar ? 'ar-SA-u-nu-latn' : 'en-GB', { hour: '2-digit', minute: '2-digit', hour12: ar }).format(new Date(iso))
const dayKey = (iso: string) => iso.slice(0, 10)

function dayLabel(iso: string, ar: boolean): string {
  const d = new Date(iso)
  const today = new Date()
  const yesterday = new Date(today.getTime() - 86_400_000)
  const same = (a: Date, b: Date) => a.toDateString() === b.toDateString()
  if (same(d, today)) return ar ? 'اليوم' : 'Today'
  if (same(d, yesterday)) return ar ? 'أمس' : 'Yesterday'

  return new Intl.DateTimeFormat(ar ? 'ar-SA-u-nu-latn-ca-gregory' : 'en-GB', { day: 'numeric', month: 'long', year: 'numeric' }).format(d)
}

export function ChatMessages({ messages, empty, team }: { messages: ChatMessage[]; empty: string; team: string }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const end = useRef<HTMLDivElement | null>(null)
  useEffect(() => { end.current?.scrollIntoView?.({ block: 'nearest' }) }, [messages.length])

  if (messages.length === 0) {
    return <p data-testid="chat-empty" className="py-10 text-center text-sm text-text-muted">{empty}</p>
  }

  let lastDay = ''

  return (
    <div data-testid="chat-messages" className="flex max-h-[60vh] min-h-48 flex-col gap-1.5 overflow-y-auto rounded-2xl bg-surface-secondary/60 p-3 sm:p-4">
      {messages.map((m) => {
        const day = m.at ? dayKey(m.at) : ''
        const divider = day !== '' && day !== lastDay
        lastDay = day || lastDay

        return (
          <div key={m.id} className="contents">
            {divider && (
              <div className="my-2 flex justify-center">
                <span className="rounded-full bg-surface px-3 py-1 text-[11px] font-semibold text-text-secondary shadow-sm">{dayLabel(m.at!, ar)}</span>
              </div>
            )}
            <div className={`flex ${m.mine ? 'justify-end' : 'justify-start'}`}>
              <div
                data-testid={`chat-bubble-${m.mine ? 'mine' : 'team'}`}
                className={`relative max-w-[82%] rounded-2xl px-3 py-2 text-sm shadow-sm ${m.mine ? 'rounded-ee-md bg-brand-600 text-white' : 'rounded-es-md bg-surface text-text-primary'}`}
              >
                {!m.mine && <div className="mb-0.5 text-[11px] font-bold text-brand-600">{team}</div>}
                <div dir="auto" className="whitespace-pre-wrap break-words leading-relaxed">{m.body}</div>
                <div className={`mt-1 flex items-center justify-end gap-1 text-[10px] ${m.mine ? 'text-white/75' : 'text-text-muted'}`}>
                  {m.at && <span className="tnum">{time(m.at, ar)}</span>}
                  {m.mine && (m.readAt
                    ? <CheckCheck size={14} aria-label={ar ? 'قُرئت' : 'Read'} data-testid="chat-read" className="text-sky-200" />
                    : <Check size={14} aria-label={ar ? 'أُرسلت' : 'Sent'} data-testid="chat-sent" />)}
                </div>
              </div>
            </div>
          </div>
        )
      })}
      <div ref={end} />
    </div>
  )
}

/** The message box under a conversation: Enter sends, Shift+Enter is a new line. */
export function ChatComposer({ onSend, sending, placeholder, attach, note, minLength = 1, maxLength = 2000 }: {
  onSend: (body: string) => void
  sending: boolean
  placeholder: string
  attach?: ReactNode
  note?: ReactNode
  minLength?: number
  maxLength?: number
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const [body, setBody] = useState('')
  const ready = body.trim().length >= minLength && !sending
  const send = () => { if (ready) { onSend(body.trim()); setBody('') } }

  return (
    <form className="mt-3" onSubmit={(e) => { e.preventDefault(); send() }}>
      {note}
      <div className="flex items-end gap-2 rounded-2xl border border-border bg-surface p-1.5 focus-within:border-brand-500">
        {attach}
        <textarea
          value={body}
          onChange={(e) => setBody(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) { e.preventDefault(); send() } }}
          rows={1}
          maxLength={maxLength}
          placeholder={placeholder}
          aria-label={placeholder}
          data-testid="chat-input"
          className="max-h-40 min-h-10 flex-1 resize-none border-0 bg-transparent px-2 py-2 text-sm text-text-primary outline-none placeholder:text-text-muted"
        />
        <button
          type="submit"
          disabled={!ready}
          aria-label={ar ? 'إرسال' : 'Send'}
          data-testid="chat-send"
          className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-brand-600 text-white transition-colors hover:bg-brand-700 disabled:opacity-40"
        >
          {sending ? <Loader2 size={17} className="animate-spin" /> : <SendHorizontal size={17} className="rtl:rotate-180" />}
        </button>
      </div>
    </form>
  )
}

/** The paperclip inside the composer. */
export function ChatAttach({ uploading, onFile, label }: { uploading: boolean; onFile: (f: File) => void; label: string }) {
  return (
    <label className="grid h-10 w-10 shrink-0 cursor-pointer place-items-center rounded-full text-text-secondary hover:bg-surface-hover hover:text-text-primary" aria-label={label} title={label}>
      {uploading ? <Loader2 size={17} className="animate-spin" /> : <Paperclip size={17} />}
      <input type="file" className="hidden" onChange={(e) => { const f = e.target.files?.[0]; if (f) onFile(f); e.target.value = '' }} />
    </label>
  )
}
