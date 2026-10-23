import { useEffect, useMemo, useRef, useState } from 'react'
import { useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { fetchPublicForm, sendFunnelBeacon, submitPublicForm } from './api'
import { FormRenderer, type FunnelSignal } from './FormRenderer'
import { initConsentMode, loadContainer, pushFormEvent, updateConsent } from './dataLayer'
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
  /* FORMS-REFERRALS-001 — the signed code a referral link carried. Sent as its own field; the server verifies it and ignores one that does not verify. */
  const referral = useMemo(() => new URLSearchParams(window.location.search).get('ref'), [])
  const [done, setDone] = useState(false)
  const [sending, setSending] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const attribution = useMemo(() => {
    const out: Record<string, string> = {}
    const q = new URLSearchParams(window.location.search)
    for (const k of KEYS) { const v = q.get(k); if (v) out[k] = v }
    out.landing_path = window.location.pathname
    /* GA4's own cookie, when the page has one: it is what joins a server-side event to this visit. Digits and dots only. */
    const ga = /(?:^|;\s*)_ga=GA\d\.\d\.(\d{4,12}\.\d{4,12})/.exec(document.cookie)
    if (ga) out.ga_client_id = ga[1]
    if (document.referrer) out.referrer = document.referrer
    return out
  }, [])

  const beacon = (event: Parameters<typeof sendFunnelBeacon>[1]['event'], consent: boolean, step?: number) =>
    sendFunnelBeacon(slug, { event, event_id: randomId(), session_id: sessionId.current, step, consent: consent ? 'granted' : 'unknown', attribution })

  /*
   * The page was seen — once, when the definition has arrived. Consent Mode defaults go in BEFORE any container loads,
   * every signal denied; the measurement state is «unknown» until the visitor ticks the optional box.
   */
  const viewed = useRef(false)
  useEffect(() => {
    if (form.isSuccess && !viewed.current) {
      viewed.current = true
      initConsentMode()
      loadContainer(form.data.tracking?.gtm_container_id)
      pushFormEvent('ch_form_view', { form_name: form.data.name })
      beacon('form_view', false)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [form.isSuccess])

  const measured = useRef(false)
  const signal = (s: FunnelSignal, measurement: boolean) => {
    if (measurement !== measured.current) { measured.current = measurement; updateConsent(measurement) }
    if (s.type === 'form_start') pushFormEvent('ch_form_start', { form_name: form.data?.name ?? '' })
    if (s.type === 'step_completed') pushFormEvent('ch_form_step', { form_name: form.data?.name ?? '', step: s.step })
    beacon(s.type, measurement, 'step' in s ? s.step : undefined)
  }

  const thankYou = form.data?.definition.thank_you
  useEffect(() => {
    if (done && thankYou?.redirect_url) window.location.assign(thankYou.redirect_url)
  }, [done, thankYou?.redirect_url])

  if (form.isLoading) return <Shell><p className="text-sm text-text-secondary" data-testid="public-form-loading">{ar ? 'جارٍ التحميل…' : 'Loading…'}</p></Shell>
  if (form.isError || !form.data) {
    return <Shell><p role="alert" className="text-sm text-text-secondary" data-testid="public-form-missing">{ar ? 'هذا النموذج غير متاح.' : 'This form is not available.'}</p></Shell>
  }

  const send = async (answers: Answers, consent: boolean, measurement: boolean) => {
    setSending(true)
    setError(null)
    try {
      const result = await submitPublicForm(slug, { submission_id: submissionId.current, answers, consent: { accepted: consent, measurement }, attribution, session_id: sessionId.current, website: trap.current?.value ?? '', ...(referral ? { ref: referral } : {}) })
      /* The browser tag gets the server's own id, so a platform that hears from both counts one conversion. */
      if (result.event_id && !result.duplicate) pushFormEvent('generate_lead', { form_name: form.data?.name ?? '', event_id: result.event_id })
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
            <FormRenderer definition={form.data.definition} ar={ar} onSubmit={send} onSignal={signal} submitting={sending} serverError={error} />
          </div>
        )}
    </Shell>
  )
}

function Shell({ children }: { children: React.ReactNode }) {
  return <main className="min-h-screen bg-background px-4 py-8 sm:py-12"><div className="mx-auto w-full max-w-xl">{children}</div></main>
}
