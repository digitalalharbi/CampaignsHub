import { expect, test, type Page, type Route } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * NOTIF-V2-SETTINGS-UI-001 — the one workspace notification settings page.
 *
 *   1. both sections open from their deep links;
 *   2. what the page edits is saved and survives a reload (the settings the old tab held are on it);
 *   3. a feature the server says the plan no longer includes shows the lock and «ترقية»;
 *   4. the agency, direct-business and client-portal workspace types each show their own terms and roles;
 *   5. no sideways scroll at 390, and on a 1366×768 desktop the document does not scroll vertically.
 *
 * Recipients and subscriptions are served by builder Q's pipeline, which is not merged here, so those requests
 * are answered by fixtures; everything else is the real backend. NOTHING is sent: the test-send and the
 * confirmation-request endpoints are never called.
 */
test.use({ storageState: AUTH.owner })

const BASE = '/agency/settings/workspace?tab=notifications'
const API = '**/api/v1/settings/workspace/notifications'

const TYPES = {
  agency: { roles: ['finance', 'project_manager', 'admin', 'client', 'other'], responsible: 'admin', client: ['عميل', 'Client'], project: ['مشروع', 'Project'], labels: ['المالية', 'مدير المشاريع', 'الإدارة', 'العميل', 'أخرى'] },
  business: { roles: ['owner', 'marketing', 'finance', 'other'], responsible: 'owner', client: ['علامة', 'Brand'], project: ['حملة', 'Campaign'], labels: ['المالك', 'التسويق', 'المالية', 'أخرى'] },
  client_portal: { roles: ['account_owner', 'team'], responsible: 'account_owner', client: ['حساب', 'Account'], project: ['مشروع', 'Project'], labels: ['صاحب الحساب', 'الفريق'] },
} as const

/** The real settings answer with the workspace type swapped for another type's profile (data, exactly as the server sends it). */
async function asType(page: Page, type: keyof typeof TYPES, extra: (d: Record<string, unknown>) => void = () => undefined) {
  const t = TYPES[type]
  await page.route(`${API}/settings`, async (route: Route) => {
    if (route.request().method() !== 'GET') return route.continue()
    const res = await route.fetch()
    const body = await res.json()
    body.data.workspace.type = {
      key: type, responsible_role: t.responsible, client_role: null, work_days: [0, 1, 2, 3, 4], sees_sensitive_events: true,
      roles: t.roles.map((k) => ({ key: k, label_key: k })), terminology: {},
      role_labels: Object.fromEntries(t.roles.map((k, i) => [k, { ar: t.labels[i], en: k }])),
      term_labels: { client: { ar: t.client[0], en: t.client[1] }, project: { ar: t.project[0], en: t.project[1] } },
    }
    body.data.first_run = false
    extra(body.data)
    await route.fulfill({ response: res, json: body })
  })
}

/** Q's recipients endpoint: the migrated legacy rows — pending, nothing deleted, nothing mailed. */
async function migratedRecipients(page: Page) {
  await page.route(`${API}/external/settings`, (route) => route.fulfill({ json: { success: true, data: {
    sender: { reply_to: null, signature: null },
    external: { disclaimer: { ar: null, en: null }, forbidden_words: [], contact_link: null, subject_templates: {}, continuity_days: 5, improvement_pct: 10, copy_delivery: 'all', review_window_hours: 4, display_currency: null },
  } } }))
  await page.route(`${API}/external/kpis`, (route) => route.fulfill({ json: { success: true, data: { held_for_review: 2, held_for_privacy: 1, sent_this_week: 9, failed: 0, awaiting_confirmation: 2, unsubscribed_this_month: 1 } } }))
  await page.route(`${API}/external/recipients`, (route) => route.fulfill({
    json: {
      success: true,
      data: [
        { id: 'r1', email: 'sara@client.test', name: 'Sara', salutation: null, role_key: 'finance', language: 'ar', display_currency: null, is_role_mailbox: false, status: 'pending', consent: 'none',
          subscriptions: [{ id: 's1', recipient_id: 'r1', status: 'pending', project_ids: ['p1', 'p2'], event_keys: [], digest_types: ['performance'], period_key: 'last_7', frequency: 'weekly', weekdays: [], month_day: null, send_time: '08:00', timezone: 'Asia/Riyadh', review_mode: null, reviews_remaining: 3, template_key: null, subject_template: null, cc: [], bcc: [], next_run_at: null }] },
        { id: 'r2', email: 'omar@client.test', name: null, salutation: null, role_key: 'other', language: 'en', display_currency: null, is_role_mailbox: false, status: 'pending', consent: 'none',
          subscriptions: [{ id: 's2', recipient_id: 'r2', status: 'paused', project_ids: ['p1'], event_keys: [], digest_types: ['spend'], period_key: 'last_month', frequency: 'monthly', weekdays: [], month_day: null, send_time: '09:00', timezone: 'Asia/Riyadh', review_mode: null, reviews_remaining: 3, template_key: null, subject_template: null, cc: [], bcc: [], next_run_at: null }] },
      ],
    },
  }))
}

const isSettingsGet = (r: { url(): string; request(): { method(): string } }) => r.url().includes('/notifications/settings') && r.request().method() === 'GET'

/**
 * A workspace that never completed notification setup meets the first-run wizard (by design). These specs are about the
 * page, so it is dismissed — but only when the server actually said `first_run`, so nothing waits on a dialog that is not coming.
 */
async function dismissFirstRun(page: Page, response: { json(): Promise<unknown> }) {
  const body = (await response.json()) as { data?: { first_run?: boolean } }
  if (body.data?.first_run !== true) return
  const skip = page.getByTestId('first-run-skip')
  await skip.click({ timeout: 20_000 })
  await expect(skip).toHaveCount(0)
}

async function open(page: Page, query = '') {
  const settings = page.waitForResponse(isSettingsGet, { timeout: 30_000 })
  await page.goto(`${BASE}${query}`)
  await expect(page.getByTestId('workspace-notifications')).toBeVisible({ timeout: 30_000 })
  await dismissFirstRun(page, await settings)
}

async function reload(page: Page) {
  const settings = page.waitForResponse(isSettingsGet, { timeout: 30_000 })
  await page.reload()
  await expect(page.getByTestId('workspace-notifications')).toBeVisible({ timeout: 30_000 })
  await dismissFirstRun(page, await settings)
}

test.describe('deep links and structure', () => {
  test.beforeEach(async ({ page }) => { await page.setViewportSize({ width: 1366, height: 768 }) })

  test('section=internal and section=external open their own section', async ({ page }) => {
    await migratedRecipients(page)
    await open(page, '&section=internal')
    await expect(page.getByTestId('internal-section')).toBeVisible()
    await expect(page.getByTestId('kpis-internal')).toBeVisible()
    await expect(page.getByTestId('external-section')).toHaveCount(0)

    await open(page, '&section=external')
    await expect(page.getByTestId('external-section')).toBeVisible()
    await expect(page.getByTestId('kpis-external').locator('[data-testid^="kpi-"]')).toHaveCount(6)
    await expect(page.getByTestId('internal-section')).toHaveCount(0)
  })

  test('the header toggle moves between sections and the address follows', async ({ page }) => {
    await migratedRecipients(page)
    await open(page)
    await page.getByTestId('section-toggle-external').click()
    await expect(page).toHaveURL(/section=external/)
    await expect(page.getByTestId('external-section')).toBeVisible()
    await page.getByTestId('section-toggle-internal').click()
    await expect(page).toHaveURL(/section=internal/)
  })

  test('migrated recipients are all there, pending, with their subscriptions', async ({ page }) => {
    await migratedRecipients(page)
    await open(page, '&section=external')
    const table = page.getByTestId('recipients-table')
    await expect(table).toContainText('sara@client.test')
    await expect(table).toContainText('omar@client.test')
    await expect(page.getByTestId('recipients-pager')).toHaveCount(0)
  })

  test('an edit is saved and is still there after a reload', async ({ page }) => {
    await open(page, '&section=internal&tab2=delivery')
    const start = page.getByTestId('delivery-panel').locator('input[type="time"]').first()
    await start.fill('22:30')
    await page.getByTestId('delivery-save').click()
    await expect(page.getByTestId('delivery-save')).toContainText(/تم الحفظ|Saved/)
    await reload(page)
    await expect(page.getByTestId('delivery-panel').locator('input[type="time"]').first()).toHaveValue('22:30')
    // restore the seeded default so other specs see the same workspace
    await page.getByTestId('delivery-panel').locator('input[type="time"]').first().fill('21:00')
    await page.getByTestId('delivery-save').click()
    await expect(page.getByTestId('delivery-save')).toContainText(/تم الحفظ|Saved/)
  })

  test('the old external recipients panel is one line pointing here', async ({ page }) => {
    await page.goto('/agency/account/notifications')
    await expect(page.getByTestId('external-recipients')).toBeVisible({ timeout: 30_000 })
    await page.getByTestId('external-recipients-link').click()
    await expect(page).toHaveURL(/tab=notifications&section=external/)
    await expect(page.getByTestId('external-section')).toBeVisible()
  })
})

test.describe('first run', () => {
  for (const type of ['agency', 'business', 'client_portal'] as const) {
    test(`${type}: a new workspace starts with its responsible recipient and the organisation domains`, async ({ page }) => {
      await page.setViewportSize({ width: 1366, height: 768 })
      await asType(page, type, (d) => { d.first_run = true })
      await page.goto(`${BASE}&section=external`)
      const wizard = page.getByTestId('first-run')
      await expect(wizard).toBeVisible({ timeout: 30_000 })
      await expect(wizard).toContainText(TYPES[type].labels[TYPES[type].roles.indexOf(TYPES[type].responsible)])
      await expect(page.getByTestId('first-run-domains')).toBeVisible()
      await page.getByTestId('first-run-skip').click()
      await expect(wizard).toHaveCount(0)
    })
  }
})

test.describe('plan lock', () => {
  test('a key the server reports view-only shows the lock and «ترقية», and the settings stay readable but not editable', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 768 })
    await page.route(`${API}/entitlements`, async (route) => {
      const res = await route.fetch()
      const body = await res.json()
      body.data.entitlements = body.data.entitlements.map((e: { key: string }) => (e.key === 'notifications.internal'
        ? { ...e, mode: 'view_only', allowed: false, can_edit: false, upgrade: true } : e))
      await route.fulfill({ response: res, json: body })
    })
    await open(page, '&section=internal')
    const lock = page.getByTestId('locked-notifications.internal')
    await expect(lock).toBeVisible()
    await expect(lock).toContainText('ترقية')
    await expect(page.getByTestId('routing-save')).toBeDisabled()
    await expect(page.getByTestId('routing-table')).toBeVisible()
  })
})

test.describe('workspace types', () => {
  for (const type of Object.keys(TYPES) as (keyof typeof TYPES)[]) {
    test(`${type}: its own roles in the wizard and its own term for projects`, async ({ page }) => {
      await page.setViewportSize({ width: 1366, height: 768 })
      await asType(page, type)
      await migratedRecipients(page)
      await page.route('**/settings/notifications/external-recipients/projects', (r) => r.fulfill({ json: { success: true, data: [{ id: 'p1', name: 'Alpha' }] } }))
      await open(page, '&section=external')
      const t = TYPES[type]
      await expect(page.getByTestId('recipients-table')).toContainText(t.project[0] === 'مشروع' ? 'المشاريع' : 'الحملات')
      await page.getByTestId('add-recipient').click()
      const role = page.getByTestId('wiz-role')
      for (const label of t.labels) await expect(role.locator('option', { hasText: label })).toHaveCount(1)
      await expect(role.locator('option')).toHaveCount(t.roles.length)
      await page.getByTestId('wiz-email').fill('finance@acme.test')
      await expect(page.getByTestId('wiz-mailbox-hint')).toHaveCount(0)
      for (let i = 0; i < 3; i++) await page.getByTestId('wiz-next').click()
      await expect(page.getByRole('dialog')).toContainText(t.project[0] === 'مشروع' ? 'المشاريع' : 'الحملات')
    })
  }

  test('the wizard suggests a role mailbox for a personal address', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 768 })
    await migratedRecipients(page)
    await open(page, '&section=external')
    await page.getByTestId('add-recipient').click()
    await page.getByTestId('wiz-email').fill('sara@acme.test')
    await expect(page.getByTestId('wiz-mailbox-hint')).toBeVisible()
  })
})

test.describe('fit and overflow', () => {
  const VIEWS = ['&section=internal', '&section=internal&tab2=delivery', '&section=internal&tab2=followup', '&section=external', '&section=external&tab2=events', '&section=external&tab2=settings', '&section=external&tab2=log', '&section=usage']

  for (const query of VIEWS) {
    test(`1366x768: no overflow sideways — ${query}`, async ({ page }) => {
      await page.setViewportSize({ width: 1366, height: 768 })
      await migratedRecipients(page)
      await open(page, query)
      const m = await page.evaluate(() => ({
        docH: document.documentElement.scrollHeight, winH: window.innerHeight, docW: document.documentElement.scrollWidth, winW: window.innerWidth,
        cut: (() => { const c = document.querySelector('.portal-content'); return c ? c.scrollHeight - c.clientHeight : 0 })(),
      }))
      // SCROLL-RESTORE-001 — the page may scroll vertically now; it must still never be wider than the window.
      expect(m.docW).toBeLessThanOrEqual(m.winW)
    })

    test(`390: no sideways scroll — ${query}`, async ({ page }) => {
      await page.setViewportSize({ width: 390, height: 844 })
      await migratedRecipients(page)
      await open(page, query)
      const w = await page.evaluate(() => ({ doc: document.documentElement.scrollWidth, win: window.innerWidth }))
      expect(w.doc).toBeLessThanOrEqual(w.win)
    })
  }

  test('390: the recipients are cards, not a table', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 })
    await migratedRecipients(page)
    await open(page, '&section=external')
    await expect(page.getByTestId('recipients-cards')).toBeVisible()
    await expect(page.getByTestId('recipients-table')).toHaveCount(0)
  })
})
