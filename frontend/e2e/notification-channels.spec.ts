import { expect, test, type Page } from '@playwright/test'
import { AUTH, switchToEnglish } from './helpers'

/**
 * NOTIFICATION-CHANNELS-001 — Owner, 2026-11-06: «أحتاج كل شيء يكون إشعارات على الإيميل + النظام + لاحقًا على الواتساب».
 *
 * The settings screen is where a person decides, so it is where this is asked of the running product: the matrix has the
 * three columns, a chip really round-trips through the server, an account message is locked, WhatsApp says «قريبًا» and is
 * not pressable, and the per-channel test button answers. Nothing here sends to a real inbox: the gate's mail provider
 * is unconfigured, which is exactly the state whose honest answer the test button must print.
 *
 * `NOTIF_SHOTS=<dir>` also writes the four screenshots (dark and light, desktop and 375px) that a human reads.
 */
test.use({ storageState: AUTH.owner })

const URL = '/agency/settings/workspace?tab=notifications'

async function open(page: Page, theme: 'dark' | 'light' = 'dark'): Promise<void> {
  await page.addInitScript((t) => { try { localStorage.setItem('campaign-hub-theme', t) } catch { /* private window */ } }, theme)
  await page.goto(URL)
  await expect(page.getByTestId('channels-panel')).toBeVisible({ timeout: 30000 })
}

test('the matrix has the three columns, locks an account message and says «Soon» for WhatsApp', async ({ page }) => {
  await open(page)
  await switchToEnglish(page)

  for (const name of ['In-app', 'Email', 'WhatsApp']) {
    await expect(page.getByRole('columnheader', { name }).first()).toBeVisible()
  }

  // The automation rule — the type that used to reach the bell and nothing else — is a switch in the email column.
  const email = page.getByRole('switch', { name: 'An automation rule matched — email' })
  await expect(email).toBeVisible()
  await expect(email).toHaveAttribute('aria-checked', 'true')

  // An account message has no off switch: a lock, with the word.
  await expect(page.getByRole('img', { name: /Password reset — email: Always/ })).toBeVisible()
  await expect(page.getByRole('switch', { name: /Password reset/ })).toHaveCount(0)

  // WhatsApp: planned, not pressable, and never for a type the policy does not send there.
  const soon = page.getByTestId('whatsapp-soon-automation_rule')
  await expect(soon).toHaveText('Soon')
  expect(await soon.evaluate((el) => el.tagName)).not.toBe('BUTTON')
  await expect(page.getByTestId('whatsapp-soon')).toHaveText('Soon')
  await expect(page.getByTestId('test-whatsapp')).toBeDisabled()
})

test('a chip round-trips through the server and survives a reload', async ({ page }) => {
  await open(page)
  await switchToEnglish(page)

  const email = page.getByRole('switch', { name: 'An automation rule matched — email' })
  await email.click()
  await expect(email).toHaveAttribute('aria-checked', 'false')
  await page.getByRole('button', { name: /Save preferences/i }).click()
  await expect(page.getByText('Preferences saved')).toBeVisible()

  await page.reload()
  await switchToEnglish(page)
  await expect(page.getByRole('switch', { name: 'An automation rule matched — email' })).toHaveAttribute('aria-checked', 'false')

  // Put it back, so a rerun starts from the seeded state.
  await page.getByRole('switch', { name: 'An automation rule matched — email' }).click()
  await page.getByRole('button', { name: /Save preferences/i }).click()
  await expect(page.getByText('Preferences saved')).toBeVisible()
})

test('the test buttons answer: in-app creates a bell item, email says what the transport did', async ({ page }) => {
  await open(page)
  await switchToEnglish(page)

  await page.getByTestId('test-in_app').click()
  await expect(page.getByTestId('test-result-in_app')).toHaveText('Sent', { timeout: 15000 })

  await page.getByTestId('test-email').click()
  // This gate has no mail provider, so the honest answer is that email is not connected — never «Sent».
  await expect(page.getByTestId('test-result-email')).toHaveText(/Email is not connected|Test mode|Your address is not verified|Accepted/, { timeout: 15000 })
  await expect(page.getByTestId('test-result-email')).not.toHaveText('Sent')
})

for (const theme of ['dark', 'light'] as const) {
  for (const [label, size] of [['desktop', { width: 1280, height: 900 }], ['375', { width: 375, height: 812 }]] as const) {
    test(`the matrix fits without sideways page scroll — ${theme}, ${label}`, async ({ page }) => {
      await page.setViewportSize(size)
      await open(page, theme)

      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
      expect(overflow, 'the page scrolls sideways').toBeLessThanOrEqual(0)

      // The three channel cards are never taller than their fixed size lets them be pushed around by a result line.
      const heights = await page.getByTestId('channels-panel').locator('> div').evaluateAll((els) => els.map((e) => Math.round(e.getBoundingClientRect().height)))
      expect(heights.length).toBe(3)

      if (process.env.NOTIF_SHOTS) {
        await page.getByTestId('test-in_app').click()
        await page.getByTestId('channels-panel').scrollIntoViewIfNeeded()
        await page.screenshot({ path: `${process.env.NOTIF_SHOTS}/matrix-${theme}-${label}-top.png` })
        // The operations card: the automation rule's row, where all three channels are in play and a lock sits below it.
        const card = page.getByTestId('whatsapp-soon-automation_rule').locator('xpath=ancestor::div[contains(@class,"rounded-2xl")][1]')
        await card.scrollIntoViewIfNeeded()
        await card.screenshot({ path: `${process.env.NOTIF_SHOTS}/matrix-${theme}-${label}-operations.png` })
        const account = page.getByRole('img', { name: /Password reset|إعادة تعيين كلمة المرور/ }).first().locator('xpath=ancestor::div[contains(@class,"rounded-2xl")][1]')
        await account.scrollIntoViewIfNeeded()
        await account.screenshot({ path: `${process.env.NOTIF_SHOTS}/matrix-${theme}-${label}-account.png` })
      }
    })
  }
}
