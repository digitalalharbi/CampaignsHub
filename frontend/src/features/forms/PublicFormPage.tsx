import { useEffect, useMemo, useRef, useState } from 'react'
import { useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { fetchPublicForm, submitPublicForm } from './api'
import { FormRenderer } from './FormRenderer'
import { pick } from './formLogic'
import { useUi } from '@/stores/ui'
import type { Answers } from './types'

/**
 * FORMS-HUB-001 — `/f/{slug}`: the form as a stranger meets it. No sign-in, no workspace chrome.
 *
 * Three things are decided here and nowhere else:
 *  - ONE `submission_id` per attempt, kept until the server accepts it, so a double click or a retry after a
 *    dropped connection is the same submission and never a second lead;
 *  - the attribution the page was opened with (UTM and the click ids the platforms append) is read once from
 *    the address and sent with the submission; the full query string is never sent, only these keys;
 *  - a honeypot field no person sees.
 */
const KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid', 'wbraid', 'gbraid', 'fbclid', 'ttclid', 'sclid', 'li_fat_id', 'msclkid', 'twclid']

const randomId = (): string => {
  const bytes = new Uint8Array(16)
  crypto.getRandomValues(bytes)
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
}

export function PublicFormPage() {
  const { slug = '' } = useParams()
  const ar = useUi((s) => s.locale) === 'ar'
  const form = useQuery({ queryKey: ['public-form', slug], queryFn: () => fetchPublicForm(slug), retry: false })
  const submissionId = useRef(randomId())
  const sessionId = useRef(randomId())
  const trap = useRef<HTMLInputElement>(null)
  const [done, setDone] = useState(false)
  const [sending, setSending] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const attribution = useMemo(() => {
    const out: Record<string, string> = {}
    const q = new URLSearchParams(window.location.search)
    for (const k of KEYS) { const v = q.get(k); if (v) out[k] = v }
    out.landing_path = window.location.pathname
    if (document.referrer) out.referrer = document.referrer
    return out
  }, [])

  const thankYou = form.data?.definition.thank_you
  useEffect(() => {
    if (done && thankYou?.redirect_url) window.location.assign(thankYou.redirect_url)
  }, [done, thankYou?.redirect_url])

  if (form.isLoading) return <Shell><p className="text-sm text-text-secondary" data-testid="public-form-loading">{ar ? 'جارٍ التحميل…' : 'Loading…'}</p></Shell>
  if (form.isError || !form.data) {
    return <Shell><p role="alert" className="text-sm text-text-secondary" data-testid="public-form-missing">{ar ? 'هذا النموذج غير متاح.' : 'This form is not available.'}</p></Shell>
  }

  const send = async (answers: Answers, consent: boolean) => {
    setSending(true)
    setError(null)
    try {
      await submitPublicForm(slug, { submission_id: submissionId.current, answers, consent: { accepted: consent }, attribution, session_id: sessionId.current, website: trap.current?.value ?? '' })
      setDone(true)
    } catch {
      /* The id is kept: sending again is the same submission. */
      setError(ar ? 'تعذّر إرسال النموذج. تحقق من الاتصال وحاول مرة أخرى.' : 'The form could not be sent. Check your connection and try again.')
    } finally {
      setSending(false)
    }
  }

  return (
    <Shell>
      <h1 className="mb-5 text-xl font-bold text-text-primary">{form.data.name}</h1>
      {done
        ? <p role="status" className="rounded-lg border border-border bg-surface p-4 text-sm text-text-primary" data-testid="public-form-thanks">{pick(thankYou?.message, ar) || (ar ? 'شكرًا، وصلتنا بياناتك.' : 'Thank you — we have your details.')}</p>
        : (
          <div className="relative">
            {/* A field no person sees and no label asks for; a bot that fills every input fills this one. */}
            <input ref={trap} type="text" name="website" tabIndex={-1} autoComplete="off" aria-hidden="true" className="absolute -left-[9999px] h-0 w-0 opacity-0" defaultValue="" data-testid="honeypot" />
            <FormRenderer definition={form.data.definition} ar={ar} onSubmit={send} submitting={sending} serverError={error} />
          </div>
        )}
    </Shell>
  )
}

function Shell({ children }: { children: React.ReactNode }) {
  return <main className="min-h-screen bg-background px-4 py-8 sm:py-12"><div className="mx-auto w-full max-w-xl">{children}</div></main>
}
