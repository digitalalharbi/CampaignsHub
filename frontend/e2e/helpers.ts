import { expect, type APIRequestContext, type Page } from '@playwright/test'
import { E2E_ORIGIN } from './env'
import { RAIL_PAINT_TIMEOUT } from './railWalkTimeout'

/**
 * Re-exported so a spec that needs the gate's origin has one obvious place to take it from — the
 * same module it already imports `AUTH` and `csrfHeaders` from. Six specs held it as a literal, and
 * a literal is exactly what breaks the day the gate moves off the shared port.
 */
export { E2E_ORIGIN }

export const AUTH = {
  owner: 'e2e/.auth/owner.json',
  /**
   * The ADVERTISER owner (LOGIN-002). `owner` above is an AGENCY account, and using it to drive
   * `/app/*` was only ever possible because that tree had no portal guard — a spec that signs in as
   * an agency and asserts an advertiser page proves nothing about either.
   */
  advertiser: 'e2e/.auth/advertiser.json',
  analyst: 'e2e/.auth/analyst.json',
  viewer: 'e2e/.auth/viewer.json',
  /** The platform owner. Belongs to no tenant — `/admin` is held by a flag, not a membership. */
  admin: 'e2e/.auth/admin.json',
  /**
   * The client portal's customer (REVIEW-001c).
   *
   * `/portal` was the one portal no signed-in session could reach: it was gated on the OTP cookie
   * alone, so the account the product routes there was refused by every endpoint in it.
   */
  client: 'e2e/.auth/client.json',
} as const

/**
 * Sanctum only treats a request as stateful (session-authenticated) when its Origin/Referer matches
 * SANCTUM_STATEFUL_DOMAINS. Playwright's request contexts don't send these by default, so every API
 * call must include them or it comes back 401.
 */
export const API_HEADERS: Record<string, string> = {
  Accept: 'application/json',
  Origin: E2E_ORIGIN,
  Referer: `${E2E_ORIGIN}/`,
}

/** Prime the Sanctum CSRF cookie and return the headers needed for unsafe (POST/DELETE) API calls. */
export async function csrfHeaders(request: APIRequestContext): Promise<Record<string, string>> {
  await request.get('/sanctum/csrf-cookie', { headers: API_HEADERS })
  const state = await request.storageState()
  const xsrf = state.cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? ''
  return { ...API_HEADERS, 'X-XSRF-TOKEN': decodeURIComponent(xsrf) }
}

/**
 * Open a filter bar that is folded on a phone (MOBILE-FILTERS-001).
 *
 * Below `sm` the controls start behind a summary toggle, so the numbers are above the fold. A spec
 * that operates a filter at a phone width has to open it first, exactly as a reader does. Above
 * `sm` there is no toggle and this does nothing, so a spec can call it unconditionally.
 */
export async function openFilters(page: Page, id: string) {
  const toggle = page.getByTestId(`${id}-filters-toggle`)
  if (await toggle.isVisible().catch(() => false)) {
    const controls = page.getByTestId(`${id}-filters-controls`)
    if (!(await controls.isVisible().catch(() => false))) await toggle.click()
  }
}

/** Flip the app to English for stable selectors (default locale is Arabic). */
export async function switchToEnglish(page: Page) {
  const toggle = page.getByRole('button', { name: /Toggle language|EN|اللغة/ }).first()
  if (await toggle.count()) await toggle.click().catch(() => {})
}

/**
 * Force the global project switcher (zustand persist) to a specific project before the app boots.
 *
 * Named `selectProject`, not `useProject`: this is a Playwright helper, but anything beginning with
 * «use» is read as a React hook by the hooks lint rule, which then objected — correctly, by its own
 * lights — to it being called from an ordinary named function. The rule was right that the name was
 * lying about what this is.
 */
export async function selectProject(page: Page, projectId: string) {
  await page.addInitScript((id) => {
    localStorage.setItem(
      'campaign-hub-project-storage',
      JSON.stringify({ state: { currentProjectId: id }, version: 0 }),
    )
  }, projectId)
}

/**
 * Put an external campaign back in the unlinked pool, whatever a previous run left behind.
 *
 * `campaigns-linking` starts by linking a named external to campaign A and only then expects the
 * 409. That only holds if the external begins UNLINKED — which used to be true by accident, because
 * every run minted a brand-new Sandbox account and therefore a brand-new external. Now that
 * `seedExternals` reuses the project's binding, the precondition has to be stated rather than
 * inherited from a fresh row.
 */
export async function unlinkExternalByName(request: APIRequestContext, projectId: string, name: string) {
  const headers = await csrfHeaders(request)
  const externals = (await (await request.get(`/api/v1/projects/${projectId}/external-campaigns`, { headers })).json())
    .data as Array<{ id: string; name: string; unified_campaign_id: string | null }>

  for (const e of externals.filter((x) => x.name === name && x.unified_campaign_id)) {
    const res = await request.delete(
      `/api/v1/projects/${projectId}/campaigns/${e.unified_campaign_id}/external/${e.id}`,
      { headers },
    )
    // Asserted rather than hoped for: a precondition that silently fails to apply is how a test
    // ends up exercising something other than what it claims.
    expect(res.ok(), `unlinking ${name} failed with ${res.status()}`).toBeTruthy()
  }

  const unlinked = (await (await request.get(`/api/v1/projects/${projectId}/external-campaigns?linked=0`, { headers })).json())
    .data as Array<{ name: string; project_id?: string | null }>
  expect(
    unlinked.filter((x) => x.name === name),
    `${name} is not in the unlinked pool after unlinking; pool = ${JSON.stringify(unlinked.map((x) => x.name))}`,
  ).not.toHaveLength(0)
}

/**
 * Connect Sandbox + bind an ad account + sync → imports Sandbox external campaigns into `projectId`.
 *
 * REUSES an existing advertising binding when the project already has one.
 *
 * Connecting is genuinely not idempotent in the product — `EstablishSandboxConnection` mints a fresh
 * credential, connection and set of accounts each time, because a person connecting twice means it —
 * so calling this once per test accumulated one more Sandbox account on the same project every run.
 * After enough runs the project held dozens of externals all named «Sandbox Awareness», and
 * `campaigns-linking`, which finds its target by that name, started linking two DIFFERENT externals
 * to campaigns A and B: no conflict, no 409, and the move-confirmation it asserts never appeared.
 *
 * A helper whose effect depends on how many times the suite has been run makes the suite's own
 * behaviour depend on it. This one now leaves the project with exactly one Sandbox binding however
 * often it is called.
 */
export async function seedExternals(request: APIRequestContext, projectId: string) {
  const headers = await csrfHeaders(request)

  const existing = (await (await request.get(`/api/v1/projects/${projectId}/integrations`, { headers })).json())
    .data as Array<{ id: string; purpose: string }>
  const reusable = existing.find((b) => b.purpose === 'advertising')

  if (reusable) {
    await request.post(`/api/v1/projects/${projectId}/integrations/bindings/${reusable.id}/sync`, { headers })
    return
  }

  const connect = await request.post(`/api/v1/projects/${projectId}/integrations/connect`, { headers })
  const accounts = (await connect.json()).data.accounts as Array<{ id: string; account_type: string }>
  const adAccount = accounts.find((a) => a.account_type === 'ad_account')!
  const bind = await request.post(`/api/v1/projects/${projectId}/integrations/bindings`, {
    headers,
    data: { external_account_id: adAccount.id, purpose: 'advertising', confirm: true },
  })
  const bindingId = (await bind.json()).data.id as string
  await request.post(`/api/v1/projects/${projectId}/integrations/bindings/${bindingId}/sync`, { headers })
}

export async function createCampaign(page: Page, name: string) {
  // The campaigns page opens on the chart-heavy overview; wait for it to be interactive first.
  await expect(page.getByTestId('view-overview')).toBeVisible({ timeout: 20000 })
  await page.getByRole('button', { name: /New campaign|حملة جديدة/ }).click()

  /*
   * Assert the field actually HOLDS the name before saving.
   *
   * `fill` writes the DOM value and dispatches an input event, but React has to render before its
   * own state carries it — and on WebKit under the full three-browser load that had not always
   * happened by the time the click landed. The form then posted an empty name, the server refused
   * it, and the modal stayed open showing a validation error on a field the test had just filled.
   * Waiting on the value is waiting for the precondition the next line depends on.
   */
  const nameField = page.getByLabel(/Campaign name|اسم الحملة/)
  await nameField.fill(name)
  await expect(nameField).toHaveValue(name)

  const save = page.getByRole('button', { name: /^Save$|^حفظ$/ })
  await save.click()
  // Wait for the modal to actually close before touching the page behind it — clicking the view
  // switcher while the overlay is still up lands on the overlay and silently does nothing (this was
  // an intermittent failure under parallel load on WebKit and Firefox).
  await expect(save).toBeHidden({ timeout: 15000 })

  // CAMPAIGN-010: the page opens on the overview, so the new campaign is only visible once the card
  // list is shown. Switching here keeps every caller of this helper working.
  await page.getByTestId('view-cards').click()
  await expect(page.getByText(name)).toBeVisible({ timeout: 15000 })
}

/**
 * Fill and submit the public intake wizard through the mandatory OTP verification step. Assumes the app is in
 * English (call switchToEnglish first). Returns the created request reference (REQ-YYYY-XXXXXX).
 */
export async function submitVerifiedRequest(
  page: Page,
  opts: { name: string; email: string; phone: string; company: string; objective: string; service?: RegExp },
): Promise<string> {
  const service = opts.service ?? /Launch a paid campaign|إطلاق حملة إعلانية مدفوعة/
  await page.goto('/requests/new')
  await switchToEnglish(page)
  await page.getByRole('button', { name: service }).click()
  await page.getByRole('button', { name: /Next|التالي/ }).click()
  await page.getByLabel(/Name|الاسم/).fill(opts.name)
  await page.getByLabel(/Email|البريد/).fill(opts.email)
  await page.getByLabel(/Mobile number|رقم الجوال/).fill(opts.phone)
  await page.getByLabel(/Company|اسم النشاط أو الشركة/).fill(opts.company)
  await page.getByRole('button', { name: /Next|التالي/ }).click()
  await page.getByLabel(/Objective|هدف الطلب/).fill(opts.objective)
  await page.getByRole('button', { name: /Next|التالي/ }).click() // budget
  await page.getByRole('button', { name: /Next|التالي/ }).click() // attachments
  await page.getByRole('button', { name: /Next|التالي/ }).click() // review
  // Verify phone + email (dev auto-verifies via the exposed code). Submit stays disabled until BOTH are
  // verified — wait on that (generous timeout: WebKit is slow to resolve the two async OTP round-trips).
  await page.getByRole('button', { name: /Verify Mobile number|تحقّق رقم الجوال/ }).click()
  await expect(page.getByText(/Verified|تم التحقق/).first()).toBeVisible({ timeout: 15000 }) // async OTP round-trip
  await page.getByRole('button', { name: /Verify Email|تحقّق البريد الإلكتروني/ }).click()
  const submitBtn = page.getByRole('button', { name: /Submit request|إرسال الطلب/ })
  await expect(submitBtn).toBeEnabled({ timeout: 15000 })
  await submitBtn.click()
  // Wait for the success page to render the reference before reading it (avoids a null read race).
  const refLocator = page.getByText(/REQ-\d{4}-[A-Z0-9]{6}/).first()
  await expect(refLocator).toBeVisible()
  const ref = (await refLocator.textContent())?.match(/REQ-\d{4}-[A-Z0-9]{6}/)?.[0]
  return ref!
}

/**
 * Arabic left in a page's CHROME while the interface is in English (APP-100).
 *
 * The distinction matters: a tenant's own project called «متجر تجريبي» is DATA, and translating a
 * customer's name would be a defect of its own. What must follow the language is the furniture —
 * headings, labels, buttons, table headers, tabs and empty states — which is what was actually left
 * behind when the product only flipped `dir`.
 *
 * An earlier version of this check read the whole of `main`, and duly failed on a demo client's
 * Arabic name. That was the test being wrong, not the product.
 */
export async function untranslatedChrome(page: Page): Promise<string[]> {
  return page.evaluate(() => {
    const CHROME = 'h1, h2, h3, h4, label, button, th, [role="tab"], [data-testid$="-empty"]'
    const out: string[] = []

    for (const el of Array.from(document.querySelectorAll(`main ${CHROME}`))) {
      // The language toggle says «ع» precisely BECAUSE the interface is in English — it is the way
      // back to Arabic, and translating it would leave no way to find it.
      if (el.getAttribute('aria-label') === 'Toggle language') continue

      // Own text only: a heading that merely CONTAINS a data-bearing child is not itself untranslated.
      const own = Array.from(el.childNodes)
        .filter((n) => n.nodeType === Node.TEXT_NODE)
        .map((n) => n.textContent ?? '')
        .join(' ')

      const arabic = own.match(/[؀-ۿ]+/g)
      if (arabic) out.push(arabic.join(' '))
    }

    return [...new Set(out)].slice(0, 6)
  })
}

/**
 * The project a spec should work in, pinned by NAME rather than by position.
 *
 * `projects[1]` was how one spec chose its workspace, and the project list is neither ordered nor
 * fixed: every registration-and-onboarding run adds one. So which project that index landed on
 * depended on what had run before it — the spec passed alone and failed inside the full gate, on
 * whichever browser happened to reach it after the list had grown.
 *
 * Creating (or reusing) a project the spec names itself makes the choice independent of everything
 * else in the suite, which is the only version of this that stays true as the suite grows.
 */
export async function pinnedProject(request: APIRequestContext, name: string): Promise<string> {
  const headers = await csrfHeaders(request)

  const existing = (await (await request.get('/api/v1/projects', { headers: API_HEADERS })).json())
    .data as Array<{ id: string; name: string; client_workspace_id: string }>
  const found = existing.find((p) => p.name === name)
  if (found) return found.id

  /*
   * The owning client comes from a project that already exists, not from `/client-workspaces`.
   *
   * That endpoint is agency-scoped and answers 403 — `data: null` — for the roles some specs run as,
   * which turned a missing precondition into `Cannot read properties of null`. Every project already
   * carries the id this needs.
   */
  const clientId = existing[0]?.client_workspace_id
  if (!clientId) throw new Error('no existing project to take a client workspace from')

  const created = await request.post('/api/v1/projects', {
    headers,
    data: { name, client_workspace_id: clientId },
  })

  return (await created.json()).data.id as string
}

/**
 * An EXISTING project, found by name and never created.
 *
 * `pinnedProject` creates one when it is missing, which is right for specs that only need somewhere
 * to work. It is wrong for specs that need the SEEDED data — a freshly created project has no
 * campaigns, no metrics and no external bindings, so a spec pointed at one fails on assertions that
 * were never about it.
 *
 * Throws rather than falling back. A spec that silently ran against the wrong project is how
 * `campaigns.spec.ts` came to open a throwaway campaign another spec had created and time out
 * waiting for a button that campaign could not have.
 */
export async function seededProject(request: APIRequestContext, name: string): Promise<string> {
  const projects = (await (await request.get('/api/v1/projects', { headers: API_HEADERS })).json())
    .data as Array<{ id: string; name: string }> | null

  const found = (projects ?? []).find((p) => p.name === name)
  if (!found) throw new Error(`seeded project "${name}" is missing — run the demo seeder`)

  return found.id
}

/**
 * Open `/login`, optionally asking for the DEV-only mobile form (LOGIN-CARD-001).
 *
 * The address and the password are on the production card, so nothing is needed to reach them. The
 * mobile path is not on it, and `?e2e=phone` re-exposes that one form — ONLY under
 * `import.meta.env.DEV`, so it cannot exist in a production build.
 *
 * The parameter is appended rather than replacing the URL, because several specs arrive here as
 * `/login?redirect=%2Fapp%2Freports` after being bounced off a guarded page, and that parameter
 * surviving the sign-in IS what those tests are about.
 */
async function openLogin(page: Page, form?: 'phone'): Promise<void> {
  /*
   * Built from a RELATIVE path, never from `current.origin`.
   *
   * A context that has not navigated yet sits on `about:blank`, whose origin is the string «null» —
   * and `new URL('/login', 'null')` throws `Invalid URL`. Several specs call the sign-in helpers as
   * their very first action, so this is the normal case rather than an edge one, and the throw read
   * as a helper bug rather than as «there is no page yet».
   */
  const current = new URL(page.url())
  const here = /\/login(\?|$)/.test(current.pathname + current.search)

  const search = new URLSearchParams(here ? current.search : '')
  if (form) search.set('e2e', form)

  if (!here || (form && current.searchParams.get('e2e') !== form)) {
    const qs = search.toString()
    await page.goto(`/login${qs === '' ? '' : `?${qs}`}`)
  }
}

/**
 * Sign in through the one door there is (LOGIN-UNIFIED-001).
 *
 * `/login` is two steps now: the identifier, then whichever form the SERVER says that account uses.
 * Every spec that used to fill `input[type="email"]` and `input[type="password"]` back to back was
 * filling a field that does not exist yet, and failed as a timeout — which reads like a slow boot
 * rather than like a form that changed shape. One helper so the next change to the flow is one edit.
 *
 * Assumes a password account, and says so loudly when it is not: a client contact reaches the
 * one-time-code step instead, and silently timing out on a missing password field would hide that.
 */
export async function signIn(page: Page, email: string, password = 'password'): Promise<void> {
  /*
   * Only navigate if we are not already there.
   *
   * Several specs arrive at `/login?redirect=%2Fapp%2Freports` by being bounced off a guarded page,
   * and the whole point of those tests is that the destination survives the sign-in. A helper that
   * always did `goto('/login')` would quietly throw that parameter away and the assertion would then
   * be checking a journey nobody takes.
   */
  await openLogin(page)

  await expect(
    page.getByTestId('login-identify'),
    'the sign-in card never rendered',
  ).toBeVisible({ timeout: 20_000 })

  await page.getByTestId('login-email').fill(email)
  await page.getByTestId('login-password').locator('input[type="password"]').fill(password)
  await page.getByTestId('login-identify').locator('button[type="submit"]').click()
}

/**
 * Sign in as a client contact — the one-time-code half of the unified door.
 *
 * A contact has never had a password, so `/login` sends them down the code step instead. Outside
 * production the backend returns the code it just issued (`dev_code`, hard-gated server-side) and the
 * page fills the field with it, which is what makes this branch testable at all without an inbox.
 *
 * The wait is on the field HAVING a value rather than on the step being visible: the step renders
 * immediately, and submitting before `POST /client/login/start` has answered sends an empty code.
 */
export async function signInWithCode(page: Page, contact: string): Promise<void> {
  await openLogin(page)
  await page.getByTestId('login-email').fill(contact)
  await page.getByTestId('login-request-code').click()

  const form = page.getByTestId('login-code')
  await expect(
    form,
    `${contact} was not offered a code step — the server says it signs in another way`,
  ).toBeVisible({ timeout: 20_000 })

  const field = form.getByTestId('login-otp-5')
  await expect(field, 'the issued code never arrived, so there is nothing to submit').not.toHaveValue('', { timeout: 20_000 })
  await form.locator('button[type="submit"]').click()
}

/** The contact seeded by `DemoAccountsSeeder` — a real client contact, with no password anywhere. */
export const DEMO_CLIENT_CONTACT = 'customer@demo-client.local'

/**
 * Take an application through a real, verified payment — over the API (PLAN-PAID-001).
 *
 * The browser version of this journey lives in `registration-onboarding.spec.ts`, where the point is
 * the journey. This is for specs that build a fixture account and are about something else entirely:
 * they still have to pay, because since the free tier was withdrawn a payment is the only thing that
 * creates a workspace, but they should not have to drive a gateway page to do it.
 *
 * It is still the real path. `checkout` opens the charge the platform decided on, and the sandbox
 * endpoint signs an event and hands it to the same webhook a live gateway posts to — nothing here
 * writes a paid row or skips a gate.
 */
export async function payRegistrationThroughSandbox(
  request: APIRequestContext,
  registrationId: string,
): Promise<void> {
  const headers = await csrfHeaders(request)

  const checkout = await request.post(`/api/v1/auth/registration/${registrationId}/checkout`, { headers, data: {} })
  expect(checkout.status(), 'a charge must be opened before it can be paid').toBe(200)

  const body = await checkout.json()
  const url = body.data.checkout_url as string | null

  expect(
    url,
    `no checkout URL was issued — the configured gateway reported "${body.data.status}". `
    + 'Set SUBSCRIPTION_PROVIDER=sandbox for a machine with no gateway credentials.',
  ).toBeTruthy()

  /*
   * The gateway's own confirm endpoint, given the same reference its page carries.
   *
   * The reference travels as a query parameter rather than a path segment because an idempotency key
   * contains colons, and a percent-encoded colon in a path is not decoded back into the route
   * parameter — the lookup then missed and the page answered 404 for a perfectly real charge.
   */
  const reference = new URL(url!).searchParams.get('ref')!

  /*
   * `maxRedirects: 0`, deliberately.
   *
   * A gateway answers a confirmation with a redirect back to the merchant, and this one is no
   * different. Followed, that redirect lands on the SPA's `/signup/status` — which the dev server
   * answers 404 to for a bare API fetch, so `ok()` reported a failure for a payment that had already
   * gone through. The 302 IS the success; what it points at is the browser's business.
   */
  /*
   * Posted through the SAME origin the rest of the spec uses, not to the absolute URL the gateway
   * published. `checkout_url` names the API host directly, and a request to it deposits a session
   * and an XSRF cookie for a second hostname — after which every later call in this context sent
   * the wrong token and came back 419. A relative path resolves against the base URL and keeps one
   * set of cookies.
   */
  const paid = await request.post('/api/v1/payments/sandbox/confirm', {
    headers: await csrfHeaders(request),
    form: { ref: reference },
    maxRedirects: 0,
  })

  expect(
    paid.status(),
    `the sandbox gateway refused the confirmation: ${(await paid.text()).slice(0, 200)}`,
  ).toBe(302)
}

/**
 * A Saudi mobile number nothing else in this run has used.
 *
 * Written in the national form on purpose: it is what a customer types, and it is what the server has
 * to normalise before it can compare. A helper that only produced `+966…` would quietly stop
 * exercising the reading rule that makes the two the same number (PHONE-SA-001).
 */
export function aFreshSaudiNumber(): string {
  return `05${String(Date.now()).slice(-8)}`
}

/**
 * Sign in with a mobile number and a one-time code (LOGIN-PATHS-001).
 *
 * The second of the two paths `/login` offers. Outside production the issued code comes back on the
 * response and the page fills the field with it, which is what makes this branch walkable without an
 * SMS provider.
 */
export async function signInWithPhone(page: Page, phone: string): Promise<void> {
  await openLogin(page, 'phone')

  await page.getByTestId('login-phone-number').fill(phone)
  await page.getByTestId('login-phone').locator('button[type="submit"]').click()

  const form = page.getByTestId('login-code')
  await expect(form, `${phone} was not offered a code step`).toBeVisible({ timeout: 20_000 })

  const field = form.getByTestId('login-otp-5')
  await expect(field, 'the issued code never arrived, so there is nothing to submit').not.toHaveValue('', { timeout: 20_000 })
  await form.locator('button[type="submit"]').click()
}

/**
 * Clear the mobile gate over the API (PHONE-VERIFY-001).
 *
 * The browser version lives in `registration-onboarding.spec.ts`, where the gate IS the subject.
 * This is for specs that build a fixture account and are about something else: they still have to
 * prove the number, because since PHONE-VERIFY-001 no account exists without one.
 *
 * The code is asked for and read back from `dev_code` — exposed outside production only, the same
 * affordance that keeps the email link walkable without a mail provider.
 */
export async function verifyMobileThroughApi(
  request: APIRequestContext,
  registrationId: string,
): Promise<void> {
  const issued = await request.post(`/api/v1/auth/registration/${registrationId}/resend`, {
    headers: await csrfHeaders(request),
    data: { channel: 'mobile' },
  })
  expect(issued.status(), 'a mobile challenge must be issuable').toBe(200)

  const code = (await issued.json()).data.verification.dev_code as string | null
  expect(code, 'no dev code was issued for the mobile challenge').toBeTruthy()

  const verified = await request.post(`/api/v1/auth/registration/${registrationId}/verify-mobile`, {
    headers: await csrfHeaders(request),
    data: { code },
  })
  expect(verified.status(), 'the mobile code must be accepted').toBe(200)
}

/**
 * A page is "empty" when the shell rendered and the content area did not.
 *
 * Measured AFTER the content area actually has something in it, because `goto` resolves on load and
 * React renders after that — measuring immediately reports every page as empty, which is a broken
 * test rather than a broken product.
 *
 * ## Why the ceiling is named and not 20000
 *
 * The rail walks were given `RAIL_PAINT_TIMEOUT` as their per-TEST budget, and this assertion — the
 * one that actually expires — was left at a bare twenty seconds. That is the same contradiction in
 * the other direction: raising the outer budget cannot help an inner wait that gives up first, and
 * the webkit leg went on failing with «Timeout: 20000ms» on `/app/subscriptions` while the test it
 * sat inside had forty-five seconds left to spend.
 *
 * The failure carried its own diagnosis: five requests reported «Load request cancelled», the two
 * font files among them, at test 446 of ~450 in a twenty-eight-minute run. Requests cancelled
 * including static assets is a document being torn down mid-load — the Vite dev server
 * re-optimising its module graph under a browser that had been hammering it for half an hour — not
 * a route that renders nothing. A route that renders nothing fails on all three browsers in two
 * seconds.
 *
 * Nothing is relaxed but the clock, and a slow paint no longer costs less time than the test it
 * runs inside is allowed to take. A page that never mounts still fails, with the same evidence
 * `walkRail` already collects.
 */
export async function contentLength(page: Page): Promise<number> {
  const main = page.locator('main')
  await expect(main).toBeVisible({ timeout: RAIL_PAINT_TIMEOUT })
  await expect
    .poll(async () => (await main.innerText()).trim().length, { timeout: RAIL_PAINT_TIMEOUT })
    .toBeGreaterThan(0)

  return (await main.innerText()).trim().length
}

/**
 * Walk a rail, and let a failure NAME itself.
 *
 * ## Why this replaced a bare `for` loop
 *
 * The agency walk failed once on webkit with `locator('main')` not found — and the report said
 * exactly that and nothing else. Which of the fifteen links? What did the browser have on screen?
 * The screenshot answered the second question and made the first one worse: the document was
 * **completely blank**. No shell, no navigation, no error boundary. That is not «a page rendered
 * empty», which is what this test is for; it is the application never mounting, and the two need
 * different people to look at them.
 *
 * A route that renders nothing still renders the shell around it, and would fail on all three
 * browsers. So the interesting evidence is the one thing nobody collected: the URL the browser
 * actually ended on, whether the document itself arrived, and what the console said while it did
 * not. All three are captured here and thrown WITH the failure, so the next occurrence is a
 * diagnosis instead of a hunt.
 *
 * Nothing is retried and no timeout is raised. The assertion is the same assertion.
 */
export async function walkRail(page: Page, hrefs: string[]): Promise<void> {
  const problems: string[] = []
  page.on('console', (m) => {
    if (m.type() === 'error') problems.push(`console: ${m.text()}`)
  })
  page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`))
  page.on('requestfailed', (r) => problems.push(`requestfailed: ${r.url()} — ${r.failure()?.errorText ?? '?'}`))

  for (const href of hrefs) {
    problems.length = 0

    const response = await page.goto(href)

    await expect(page.getByText(/later phase/i), `${href} is a placeholder`).toHaveCount(0)

    try {
      expect(await contentLength(page), `${href} rendered an empty page`).toBeGreaterThan(40)
    } catch (failure) {
      const body = (await page.locator('body').innerText().catch(() => '')).trim()

      throw new Error(
        [
          `${href} did not render.`,
          `  document status : ${response?.status() ?? 'no response'}`,
          `  ended on        : ${page.url()}`,
          `  <main> present  : ${(await page.locator('main').count()) > 0}`,
          `  <nav> present   : ${(await page.locator('nav').count()) > 0}`,
          `  body text       : ${body === '' ? '(the document is blank — the app never mounted)' : body.slice(0, 200)}`,
          `  browser said    : ${problems.length === 0 ? '(nothing)' : problems.slice(0, 5).join(' | ')}`,
          '',
          String(failure),
        ].join('\n'),
      )
    }
  }
}
