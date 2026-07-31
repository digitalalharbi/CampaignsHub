import { expect, test } from '@playwright/test'

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
  await page.locator('input[type="email"]').fill('owner@demo-agency.local')
  await page.locator('input[type="password"]').fill('password')
  await page.getByRole('button', { name: /تسجيل الدخول|Sign in/ }).click()
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
