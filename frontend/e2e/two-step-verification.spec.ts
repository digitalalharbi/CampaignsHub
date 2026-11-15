import { createHmac } from 'node:crypto'
import { execFileSync } from 'node:child_process'
import { expect, test, type Page } from '@playwright/test'
import { E2E_BACKEND_ENV } from './env'
import { signIn } from './helpers'

/**
 * TWO-STEP-001 — a second step the person turned on is asked for at sign-in, every time.
 *
 * The Owner turned two-step verification on and the next sign-in went straight in: the flag was stored and nothing at sign-in read
 * it. This walks the real pages with an account of its own (seeded on the e2e database, so no demo account is changed): turn the
 * authenticator app on, sign out, sign in with the right password — and the session opens only after the code. The code is
 * computed here from the secret the page shows, exactly as an authenticator app computes it (RFC 6238).
 */
test.use({ storageState: { cookies: [], origins: [] } })

const PASSWORD = 'two-step-e2e-pass-1'

function seedUser(label: string, extra = ''): string {
  const email = `two-step-${label}-${Date.now()}@e2e.test`
  const code = `
    if (! str_ends_with((string) config('database.connections.pgsql.database'), '_e2e')) { throw new RuntimeException('not an e2e database'); }
    $owner = App\\Models\\User::where('email', 'advertiser@campaignshub.io')->firstOrFail();
    $m = $owner->memberships()->where('status', 'active')->firstOrFail();
    $u = App\\Models\\User::create(['name' => 'Two Step', 'email' => '${email}', 'password' => '${PASSWORD}']);
    $u->forceFill(['email_verified_at' => now()])->save();
    ${extra}
    app(App\\Domains\\Tenancy\\Actions\\GrantMembership::class)->execute(new App\\Domains\\Tenancy\\DTOs\\MembershipGrant(
      user: $u, tenant: App\\Domains\\Tenancy\\Models\\Tenant::findOrFail($m->tenant_id), portal: App\\Domains\\Tenancy\\Enums\\Portal::App, role: 'member', clientScopeIds: [], enrolNotifications: false,
    ));
    echo 'SEEDED=' . $u->id;
  `
  const out = execFileSync('php', ['artisan', 'tinker', '--execute', code], {
    cwd: new URL('../../backend', import.meta.url).pathname,
    env: { ...process.env, ...E2E_BACKEND_ENV },
    encoding: 'utf-8',
  })
  if (!/SEEDED=\d+/.test(out)) throw new Error(`seeding failed: ${out}`)

  return email
}

function base32(secret: string): Buffer {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'
  let bits = ''
  for (const ch of secret.replace(/=+$/, '').toUpperCase()) {
    const i = alphabet.indexOf(ch)
    if (i >= 0) bits += i.toString(2).padStart(5, '0')
  }
  const bytes: number[] = []
  for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2))

  return Buffer.from(bytes)
}

/** RFC 6238: HMAC-SHA1, 30-second steps, six digits. `offset` moves to a later step. */
function totp(secret: string, offset = 0): string {
  const counter = Math.floor(Date.now() / 1000 / 30) + offset
  const msg = Buffer.alloc(8)
  msg.writeUInt32BE(Math.floor(counter / 2 ** 32), 0)
  msg.writeUInt32BE(counter >>> 0, 4)
  const hash = createHmac('sha1', base32(secret)).update(msg).digest()
  const o = hash[hash.length - 1] & 0x0f
  const n = ((hash[o] & 0x7f) << 24) | (hash[o + 1] << 16) | (hash[o + 2] << 8) | hash[o + 3]

  return String(n % 1_000_000).padStart(6, '0')
}

async function typeCode(page: Page, testId: string, code: string) {
  await page.getByTestId(`${testId}-0`).click()
  await page.keyboard.type(code)
}

async function whoAmI(page: Page): Promise<number> {
  const res = await page.request.get('/api/v1/auth/me', { headers: { Accept: 'application/json', Origin: new URL(page.url()).origin } })

  return res.status()
}

test('turning on the authenticator app makes the next sign-in ask for its code', async ({ page, context }) => {
  const email = seedUser('totp')

  await signIn(page, email, PASSWORD)
  await expect(page).not.toHaveURL(/\/login/, { timeout: 20_000 })

  await page.goto('/account/security')
  const card = page.getByTestId('two-step-card')
  await expect(card.getByTestId('two-step-status')).toBeVisible({ timeout: 20_000 })
  await card.getByTestId('two-step-enable-totp').click()

  const secret = (await card.getByTestId('two-step-secret').innerText()).trim()
  expect(secret).toMatch(/^[A-Z2-7]{16,}$/)
  await typeCode(page, 'two-step-enrol-otp', totp(secret))

  await expect(card.getByTestId('two-step-recovery-codes')).toBeVisible({ timeout: 15_000 })
  await expect(card.getByTestId('two-step-recovery-codes').locator('li')).toHaveCount(10)
  await expect(card.getByTestId('two-step-chip-totp')).toHaveText(/مفعّل|On/)

  // Out — then in again with the right password.
  await context.clearCookies()
  await signIn(page, email, PASSWORD)

  await expect(page.getByTestId('two-step')).toBeVisible({ timeout: 20_000 })
  expect(await whoAmI(page), 'no session before the second step').toBe(401)

  await typeCode(page, 'two-step-otp', '000000')
  await expect(page.getByTestId('two-step-error')).toBeVisible()
  expect(await whoAmI(page)).toBe(401)

  // The enrolment spent this step's code; the next step's is what an app shows a moment later (inside the ±1 window).
  await page.getByTestId('two-step-otp-0').click()
  for (let i = 0; i < 6; i++) await page.keyboard.press('Backspace')
  await typeCode(page, 'two-step-otp', totp(secret, 1))

  await expect(page).not.toHaveURL(/\/login/, { timeout: 20_000 })
  expect(await whoAmI(page)).toBe(200)
})

test('an email code answers the second step', async ({ page }) => {
  const email = seedUser('email', "$u->forceFill(['two_factor_enabled' => true, 'two_factor_email_enabled_at' => now(), 'two_factor_default_method' => 'email'])->save();")

  await signIn(page, email, PASSWORD)
  await expect(page.getByTestId('two-step')).toBeVisible({ timeout: 20_000 })
  expect(await whoAmI(page)).toBe(401)

  // Sent on arrival; outside production the server hands the code back (hard-gated), standing in for the mailbox.
  await expect(page.getByTestId('two-step-otp-5')).not.toHaveValue('', { timeout: 15_000 })
  await page.getByTestId('two-step-verify').click()

  await expect(page).not.toHaveURL(/\/login/, { timeout: 20_000 })
  expect(await whoAmI(page)).toBe(200)
})
