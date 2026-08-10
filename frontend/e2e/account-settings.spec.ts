import { expect, test } from '@playwright/test'
import { signIn } from './helpers'

/**
 * Account settings journey: a display-name change must persist and show up immediately in the topbar,
 * sidebar and user menu (they all read the same auth store). Runs as a guest so the login + redirect
 * are exercised too. Resets the name at the end so the shared demo owner is left unchanged.
 */
test.use({ storageState: { cookies: [], origins: [] } })

test('change display name → persists and reflects in the shell; then reset', async ({ page }) => {
  // Guest → protected settings route bounces to login with the intended path.
  // Personal settings moved out of the system settings section into the account menu (dac07f2):
  // /settings/profile now redirects to /account/profile.
  await page.goto('/account/profile')
  // The ORIGINAL path, not a portal-prefixed guess: while nobody is signed in there is no portal to
  // prefix with, and guessing one sent an agency operator to the advertiser portal's copy of their
  // own profile after login (LOGIN-002).
  await expect(page).toHaveURL(/\/login\?redirect=%2Faccount%2Fprofile/)
  await signIn(page, 'owner@demo-agency.local', 'password')
  await expect(page).toHaveURL(/\/account\/profile$/)

  const nameField = page.getByLabel(/اسم العرض|Display name/)
  const original = await nameField.inputValue()
  const renamed = 'QA Renamed Owner'

  // Change + save.
  await nameField.fill(renamed)
  await page.getByRole('button', { name: /حفظ التغييرات|Save changes/ }).click()
  await expect(page.getByText(/تم الحفظ|Saved successfully/)).toBeVisible()

  // Reflected immediately in the sidebar user card (and topbar avatar exists).
  await expect(page.locator('aside').getByText(renamed).first()).toBeVisible()

  // Persists across a full reload (comes back from the /auth/me probe).
  await page.reload()
  await expect(page.locator('aside').getByText(renamed).first()).toBeVisible()

  // The unified menu opens from the topbar avatar and shows the full email.
  await page.locator('header button[aria-haspopup="menu"]').click()
  await expect(page.getByText('owner@demo-agency.local').first()).toBeVisible()
  await page.keyboard.press('Escape')

  // Reset the shared demo owner's name so the fixture is left clean.
  await page.getByLabel(/اسم العرض|Display name/).fill(original)
  await page.getByRole('button', { name: /حفظ التغييرات|Save changes/ }).click()
  await expect(page.getByText(/تم الحفظ|Saved successfully/)).toBeVisible()
})

/**
 * AUTH-PHONE-001 — proving the mobile number from Account security, in a real browser.
 *
 * The unit tests pin the copy and the branching; this pins the thing they cannot: that the endpoints
 * exist, that the session reaches them, and that a code answered here really turns the number into a
 * credential in the database — which is what the badge changing to «confirmed» is reading.
 *
 * The number is withdrawn at the end so the shared demo owner is left as it was found.
 */
test('prove the mobile number from Account security, then withdraw it', async ({ page }) => {
  /*
   * Arrive as a guest and let the redirect carry the destination through the sign-in.
   *
   * Not decoration: `signIn` clicks submit and returns without waiting, so a `goto` placed after it
   * races the session cookie and lands back on `/login` — which reads as a broken page rather than
   * as a test that moved too early.
   */
  await page.goto('/account/security')
  /*
   * Wait for the bounce BEFORE signing in.
   *
   * `signIn` calls `openLogin`, which navigates to a bare `/login` when the page is not there yet —
   * so running it while the redirect is still resolving throws the intended path away and lands the
   * test on the dashboard. This assertion is the wait, and it is also the claim that the path
   * survives the sign-in gate.
   */
  await expect(page).toHaveURL(/\/login\?redirect=%2Faccount%2Fsecurity/)
  await signIn(page, 'owner@demo-agency.local', 'password')
  await expect(page).toHaveURL(/\/account\/security$/)

  const panel = page.getByTestId('phone-credential')
  await expect(panel).toBeVisible()
  await expect(page.getByTestId('phone-state')).toHaveText(/غير موثّق|Not confirmed/)

  // What the fixture looked like before this test touched it, so it can be put back.
  const originalNumber = (await page.getByTestId('phone-current').innerText()).trim()

  /*
   * WhatsApp is never offered as a working channel unless the server says a provider is configured.
   * `channels.whatsapp` is what the badge is drawn from, so asserting the badge asserts the rule —
   * and either state is legitimate here, which is the point: the screen follows the server.
   */
  const whatsapp = page.getByTestId('phone-channel-whatsapp')
  await expect(whatsapp).toHaveText(/مفعّلة|Enabled|بانتظار بيانات الاعتماد|Awaiting credentials/)
  if (await whatsapp.getByText(/بانتظار بيانات الاعتماد|Awaiting credentials/).count()) {
    await expect(page.getByTestId('phone-whatsapp-unavailable')).toBeVisible()
  }

  // Send the code. Outside production the server returns it, and the field is filled from that —
  // there is no inbox in CI, and inventing one would prove less than this does.
  await page.getByTestId('phone-credential-number').fill('0512345678')
  await page.getByTestId('phone-send-code').click()
  await expect(page.getByTestId('phone-confirm-code')).toBeVisible()
  await expect(page.getByTestId('phone-otp-5')).not.toHaveValue('')

  await page.getByTestId('phone-confirm-code').click()

  /*
   * The proof landed, and it landed on the SERVER — the panel refetches `/me/phone` after confirming,
   * so the badge is reporting the database rather than its own optimism.
   *
   * The withdrawal control is the assertion, not the badge text: «موثّق» is a substring of
   * «غير موثّق», so a regex on the badge would pass while the number was still unproved. The button
   * renders only for a number that is actually a credential.
   */
  await expect(page.getByTestId('phone-revoke')).toBeVisible()
  await expect(page.getByTestId('phone-current')).toHaveText('+966512345678')

  // Withdrawing keeps the number and drops only the credential.
  await page.getByTestId('phone-revoke').click()
  await expect(page.getByTestId('phone-revoke')).toHaveCount(0)
  await expect(page.getByTestId('phone-state')).toHaveText(/غير موثّق|Not confirmed/)
  await expect(page.getByTestId('phone-current')).toHaveText('+966512345678')

  /*
   * Put the shared demo owner back. The profile field is the only way to CLEAR a number — the
   * security panel deliberately has no «forget it entirely», because a number is a contact detail
   * as well as a credential.
   */
  await page.goto('/account/profile')
  const phoneField = page.getByLabel(/الجوال|رقم الجوال|Phone/).first()
  await phoneField.fill(/^\+?\d/.test(originalNumber) ? originalNumber : '')
  await page.getByRole('button', { name: /حفظ التغييرات|Save changes/ }).click()
  await expect(page.getByText(/تم الحفظ|Saved successfully/)).toBeVisible()
})
