import { expect, type APIRequestContext, type Page } from '@playwright/test'

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
  Origin: 'http://localhost:5173',
  Referer: 'http://localhost:5173/',
}

/** Prime the Sanctum CSRF cookie and return the headers needed for unsafe (POST/DELETE) API calls. */
export async function csrfHeaders(request: APIRequestContext): Promise<Record<string, string>> {
  await request.get('/sanctum/csrf-cookie', { headers: API_HEADERS })
  const state = await request.storageState()
  const xsrf = state.cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? ''
  return { ...API_HEADERS, 'X-XSRF-TOKEN': decodeURIComponent(xsrf) }
}

/** Flip the app to English for stable selectors (default locale is Arabic). */
export async function switchToEnglish(page: Page) {
  const toggle = page.getByRole('button', { name: /Toggle language|EN|اللغة/ }).first()
  if (await toggle.count()) await toggle.click().catch(() => {})
}

/** Force the global project switcher (zustand persist) to a specific project before the app boots. */
export async function useProject(page: Page, projectId: string) {
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
  await page.getByLabel(/Phone|رقم الجوال/).fill(opts.phone)
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
