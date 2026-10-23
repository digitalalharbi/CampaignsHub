/**
 * FORMS-DISPATCH-001 — the one dataLayer contract a form page speaks, and nothing else on the page touches `window.dataLayer`.
 *
 * The standard Google Consent Mode v2 shape: every storage and advertising signal starts `denied`, and only an explicit
 * tick of the OPTIONAL measurement consent moves them to `granted`. The consent to be contacted is a different purpose
 * and never changes these.
 *
 * Events are named once here, with one payload shape, so a tag manager container configured for one form is configured
 * for all of them:
 *
 *   ch_form_view · ch_form_start · ch_form_step ({ step }) · generate_lead ({ event_id })
 *
 * `generate_lead` is GA4's own recommended event for a lead and carries the SAME `event_id` the server uses for its
 * delivery, so a platform that hears from both the browser tag and the server counts one conversion.
 *
 * Pushing to `dataLayer` is inert without a container: it is an array nothing reads. A container is loaded only when
 * the team made a web GTM destination live for the form (`loadContainer`), and only after the defaults above are in.
 */
type DataLayerItem = Record<string, unknown> | IArguments

declare global {
  interface Window { dataLayer?: DataLayerItem[] }
}

const layer = (): DataLayerItem[] => (window.dataLayer ??= [])

/** The gtag shape GTM reads: it must be pushed as an `arguments` object, not an array. */
function gtag(..._args: unknown[]): void {
  // eslint-disable-next-line prefer-rest-params
  layer().push(arguments)
}

const DENIED = { ad_storage: 'denied', analytics_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied' } as const
const GRANTED = { ad_storage: 'granted', analytics_storage: 'granted', ad_user_data: 'granted', ad_personalization: 'granted' } as const

export function initConsentMode(): void {
  gtag('consent', 'default', { ...DENIED, wait_for_update: 500 })
}

export function updateConsent(measurement: boolean): void {
  gtag('consent', 'update', measurement ? GRANTED : DENIED)
}

export type FormEvent = 'ch_form_view' | 'ch_form_start' | 'ch_form_step' | 'generate_lead'

export function pushFormEvent(event: FormEvent, params: { form_name: string; step?: number; event_id?: string }): void {
  layer().push({ event, ...params })
}

/** Load the team's GTM container. Idempotent; a malformed id is ignored rather than put into a script address. */
export function loadContainer(containerId: string | null | undefined): boolean {
  if (!containerId || !/^GTM-[A-Z0-9]{4,10}$/.test(containerId)) return false
  if (document.querySelector(`script[data-ch-gtm="${containerId}"]`)) return true
  layer().push({ 'gtm.start': Date.now(), event: 'gtm.js' })
  const script = document.createElement('script')
  script.async = true
  script.src = `https://www.googletagmanager.com/gtm.js?id=${containerId}`
  script.dataset.chGtm = containerId
  document.head.appendChild(script)

  return true
}
