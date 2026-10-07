import { expect, test } from '@playwright/test'
import { signInWithCode, submitVerifiedRequest, switchToEnglish } from './helpers'

/**
 * External Client Portal acceptance (mandated flow): submit a request → verify phone + email → receive a
 * request number → sign into the client portal (OTP) → see the request → open it → reply → the reply persists
 * across a reload. Runs on Chromium/Firefox/WebKit. No provider is wired, so delivery is honestly recorded as
 * "awaiting provider" — the portal surfaces that state rather than faking a send.
 */
/*
 * FIRST occurrence, 2026-10-06 (#605, run 37530577534, attempt 1) — and the one this spec changed for.
 *
 * `✘ 133 [webkit] › client-portal.spec.ts:53` — «Any update on webkit-1791320739017?» not found
 * after the reload, while chromium and firefox passed the same commit and 651 webkit cases passed
 * around it. The re-run (attempt 2) was green, which is the shape that gets called a flake.
 *
 * It was not one, and the protocol that says «reproduce before re-running» is what showed it ({@see
 * ./railWalkTimeout.ts} for the protocol and the paint-timeout family this is NOT a member of).
 * Four facts, gathered before anything was changed:
 *
 *   THE BRANCH IS INNOCENT, AND SO IS THE PRODUCT. `git diff origin/main...HEAD --name-only` reaches
 *   CRM and leads files only — no portal, request, messaging or thread file. And the product cannot
 *   produce the failure the shape suggested: `ClientRequestDetailPage` has no optimistic insert. The
 *   thread renders `q.data.comments` and nothing else, the mutation's `onSuccess` runs only on a 2xx,
 *   and `ClientPortalController::reply()` writes the comment before it returns 201. A reply cannot be
 *   rendered before the server holds it, so «shown but never persisted» was never available.
 *
 *   THE PAGE WAS FINE. The failure's own `error-context.md` snapshot is a fully rendered detail page
 *   — banner, nav, journey, timeline, the right reference — not a redirect to `/login`, not a loading
 *   skeleton, not a refusal. It said «No messages yet.», and its timeline carried only «Request
 *   submitted». The server answered, and answered that there was no comment.
 *
 *   NOTHING REFUSED. No 500, no 502 and no exception in the job log; the backend log for that window
 *   holds mail debug lines only.
 *
 *   THE REQUEST LOG HELD THE ANSWER. Opening the request, posting the reply and reloading require
 *   three page-data loads; `serve-requests.log` recorded two — and the reply POST completed AFTER
 *   the reload's `GET …/REQ-2026-V9GRAI` had already completed. The absent load is the refetch
 *   `onSuccess` would have triggered. It never ran, because the assertion that existed to wait for it
 *   had already been satisfied without it.
 *
 * So the defect was in this file; the data was never lost (the comment was written, moments after the
 * page had read); and the repair is to make the assertion mean what it says — GATE-PORTAL-REPLY-001.
 */
test.use({ storageState: { cookies: [], origins: [] } })

test('guest submits a verified request then tracks it in the client portal', async ({ page }, testInfo) => {
  const tag = `${testInfo.project.name}-${Date.now()}`
  const email = `portal.${tag}@example.com`.toLowerCase()
  const phone = `+96650${String(Date.now()).slice(-7)}`
  const company = `Portal Co ${tag}`

  // 1) Submit through the mandatory OTP verification, get a reference.
  const reference = await submitVerifiedRequest(page, {
    name: 'Portal Client', email, phone, company, objective: 'Track this in the client portal.',
  })
  expect(reference).toMatch(/REQ-\d{4}-[A-Z0-9]{6}/)

  /*
   * 2) Sign in at the one door there is (LOGIN-UNIFIED-001).
   *
   * The contact is not asked which portal they want and is never shown a password field: they type
   * the address they filed with, the server recognises it as a contact rather than an operator, and
   * the code step renders. Outside production the issued code is returned and filled for us.
   */
  await page.goto('/login')
  await switchToEnglish(page)
  await signInWithCode(page, email)

  // 3) The dashboard lists the request. A contact named on exactly one of the agency's clients is
  //    sent straight into that space rather than shown a picker with one option (PORTAL-CLIENT-001),
  //    so the landing URL is the space itself. A contact with no client space yet stays at /portal.
  await expect(page).toHaveURL(/\/portal(\/clients\/[^/]+)?$/)
  await expect(page.getByText(reference)).toBeVisible()

  // 4) Open it → reply → the message persists after a reload.
  await page.getByText(reference).click()
  await expect(page).toHaveURL(/\/portal(\/clients\/[^/]+)?\/requests\/REQ-/)
  await expect(page.getByText(/Timeline|المسار/)).toBeVisible()
  const msg = `Any update on ${tag}?`

  /*
   * GATE-PORTAL-REPLY-001 — why the reply is not located with `getByText(msg)` alone.
   *
   * It used to be, on both sides of the reload, and the first of those two assertions could never
   * fail. React renders a controlled `<textarea value={message}>` by assigning BOTH properties:
   * `updateTextarea` sets `element.value` and — because the component passes no `defaultValue` —
   * `element.defaultValue` as well (react-dom 19.2.7). `defaultValue` on a textarea IS the element's
   * child text content, so the composer publishes whatever has been typed into it as page TEXT.
   * `getByText(msg)` therefore matched the TEXTAREA the instant `fill()` returned — before Send had
   * been clicked, before a request existed. Measured on webkit against this page, after `fill()` and
   * with nothing sent: `matches=1 tagNames=["TEXTAREA"] visible=true`.
   *
   * A test can survive an assertion that cannot fail; this one could not, because that assertion was
   * the only thing holding the reload back. Satisfied by the composer, `page.reload()` fired while the
   * POST was still in flight, and the reload's own `GET /client/requests/{ref}` raced the write it
   * existed to observe. The backend serves concurrently (`PHP_CLI_SERVER_WORKERS=4`), so on a loaded
   * runner the read won and the page honestly reported no messages — the occurrence recorded above.
   *
   * So the locator names the claim. A persisted reply is a comment the server re-served into the
   * thread, which is a DIV; the composer is a TEXTAREA, and intersecting the two excludes it. This is
   * strictly stronger than what it replaces, and it is why the spec no longer has a race to lose.
   */
  const postedReply = page.getByText(msg).and(page.locator(':not(textarea)'))

  await page.getByLabel(/Message|رسالة/).fill(msg)
  await page.getByRole('button', { name: /^Send$|^إرسال$/ }).click()
  // The composer is cleared in the mutation's `onSuccess` and nowhere else, so an empty box is the
  // client's own record that the server answered 2xx — the acknowledgement the old assertion skipped.
  await expect(page.getByLabel(/Message|رسالة/)).toHaveValue('', { timeout: 15000 })
  // And this is the reply coming back out of the database, which is the thing worth asserting.
  await expect(postedReply).toBeVisible({ timeout: 15000 })

  await page.reload()
  await switchToEnglish(page)
  await expect(postedReply).toBeVisible({ timeout: 15000 }) // persisted
})
