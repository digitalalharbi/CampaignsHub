import { execFileSync } from 'node:child_process'
import { expect, test, type Page } from '@playwright/test'
import { E2E_BACKEND_ENV } from './env'
import { AUTH } from './helpers'

/**
 * OPERATIONAL-INTELLIGENCE-P1-001 — an alert is decision-ready inside the system.
 *
 * Seeds one campaign (meta fresh, tiktok stale) with two open alerts on the E2E database, then walks the real UI: the
 * exception-first board ranks the P1 first and collapses nothing healthy into the list, the drawer opens with the five
 * fields, the status moves through «in progress» to «done» with an action tag, an owner is set from the members the
 * server offered, and the impact says «insufficient data» until its window completes. Reload proves it persisted.
 */
test.use({ storageState: AUTH.owner })
test.describe.configure({ mode: 'serial' })

const SHOTS = process.env.OIC_SHOTS ?? ''
const TAG = `OIC-${Date.now()}`

function seed(label = 'main'): { event: string } {
  const NAME = `${TAG}-${label}`
  const code = `
    if (! str_ends_with((string) config('database.connections.pgsql.database'), '_e2e')) { throw new RuntimeException('not an e2e database'); }
    $u = App\\Models\\User::where('email', 'agency@campaignshub.io')->firstOrFail();
    $m = $u->memberships()->where('status', 'active')->firstOrFail();
    app(App\\Domains\\Tenancy\\Context\\TenantContext::class)->setTenantId((string) $m->tenant_id);
    $p = App\\Domains\\Projects\\Models\\Project::query()->firstOrFail();
    $c = App\\Domains\\Campaigns\\Models\\UnifiedCampaign::create(['tenant_id' => $m->tenant_id, 'project_id' => $p->id, 'name' => '${NAME}', 'objective' => 'sales', 'status' => 'active', 'platforms' => ['meta', 'tiktok'], 'budget_currency' => 'SAR']);
    foreach ([['meta', now()->subDay()->toDateString()], ['tiktok', now()->subDays(30)->toDateString()]] as [$prov, $day]) {
        App\\Domains\\Metrics\\Models\\DailyMetric::create(['id' => (string) Illuminate\\Support\\Str::uuid(), 'tenant_id' => $m->tenant_id, 'project_id' => $p->id, 'external_account_id' => (string) Illuminate\\Support\\Str::uuid(), 'external_campaign_id' => (string) Illuminate\\Support\\Str::uuid(), 'unified_campaign_id' => $c->id, 'provider' => $prov, 'metric_key' => 'spend', 'metric_date' => $day, 'value' => 100, 'project_currency' => 'SAR']);
    }
    $q = App\\Domains\\Campaigns\\Models\\UnifiedCampaign::create(['tenant_id' => $m->tenant_id, 'project_id' => $p->id, 'name' => '${NAME}-quiet', 'objective' => 'sales', 'status' => 'active', 'platforms' => ['snapchat'], 'budget_currency' => 'SAR']);
    $rule = App\\Domains\\Alerts\\Models\\AlertRule::create(['tenant_id' => $m->tenant_id, 'project_id' => $p->id, 'type' => 'cpa_increase', 'name' => '${NAME}', 'active' => true]);
    $ctx = ['cpa_current' => 50.0, 'cpa_previous' => 30.0, 'threshold_pct' => 25.0, 'window_days' => 7, 'title' => 'CPA increased', 'message' => '${NAME} CPA rose'];
    $e = App\\Domains\\Alerts\\Models\\AlertEvent::create(['tenant_id' => $m->tenant_id, 'project_id' => $p->id, 'rule_id' => $rule->id, 'type' => 'cpa_increase', 'entity_type' => App\\Domains\\Campaigns\\Models\\UnifiedCampaign::class, 'entity_id' => $c->id, 'dedup_key' => hash('sha256', (string) Illuminate\\Support\\Str::uuid()), 'status' => 'open', 'severity' => 'warning', 'context' => $ctx, 'last_triggered_at' => now()]);
    echo 'EVENT=' . $e->id;
  `
  const out = execFileSync('php', ['artisan', 'tinker', '--execute', code], {
    cwd: new URL('../../backend', import.meta.url).pathname,
    env: { ...process.env, ...E2E_BACKEND_ENV },
    encoding: 'utf-8',
  })
  const m = out.match(/EVENT=([0-9a-f-]{36})/)
  if (!m) throw new Error(`seeding failed: ${out}`)

  return { event: m[1] }
}

async function shoot(page: Page, name: string) {
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/oic-${name}.png`, fullPage: false })
}

test('the board ranks the exception, the drawer records the decision, and the impact stays honest', async ({ page }) => {
  const errors: string[] = []
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()) })
  page.on('pageerror', (e) => errors.push(String(e)))
  const { event } = seed()

  await page.goto('/agency/alerts')
  const item = page.getByTestId(`alert-board-item-${event}`)
  await expect(item).toBeVisible({ timeout: 20_000 })
  await expect(item).toHaveAttribute('data-priority', 'P1')
  await expect(page.getByTestId('alert-matrix')).toBeVisible()
  // A campaign with no recent data is «no data» (a campaign-level alert marks its own cells as a problem), never healthy.
  await expect(page.locator('[data-testid^="board-cell-"][data-status="no_data"]').first()).toBeVisible()
  await shoot(page, 'board-1440-ar')

  await page.getByTestId(`alert-open-drawer-${event}`).click()
  const drawer = page.getByTestId('alert-drawer')
  await expect(drawer).toBeVisible()
  for (const f of ['problem', 'evidence', 'impact', 'action', 'owner']) {
    await expect(drawer.getByTestId(`drawer-field-${f}`)).toBeVisible()
  }
  await shoot(page, 'drawer-1440-ar')

  await drawer.getByTestId('drawer-status-in_progress').click()
  await drawer.getByTestId('drawer-save').click()
  await expect(drawer.getByTestId('drawer-log')).toContainText(/قيد المعالجة|In progress/)

  // Owner: the member list the server offered (never free text).
  const owner = drawer.getByTestId('drawer-owner')
  const first = await owner.locator('option:not([value=""])').first().getAttribute('value')
  expect(first, 'the server offered no eligible owner').not.toBeNull()
  await owner.selectOption(first as string)
  await expect(drawer.getByTestId('drawer-log')).toContainText(/إسناد|Assignment/)

  await drawer.getByTestId('drawer-status-done').click()
  await drawer.getByTestId('drawer-action-tag').selectOption('pause_ad')
  await drawer.getByTestId('drawer-save').click()
  await expect(drawer.getByTestId('drawer-impact')).toHaveAttribute('data-state', 'insufficient_data')
  await drawer.getByTestId('drawer-note').fill('راجع النتائج بعد أسبوع')
  await drawer.getByTestId('drawer-note-add').click()
  await expect(drawer.getByTestId('drawer-log')).toContainText('راجع النتائج بعد أسبوع')
  await shoot(page, 'drawer-done-1440-ar')

  // Persisted: a reload and the same alert says the same thing.
  await page.reload()
  await showResolved(page) // LOST-CLICK-001 — see below; one line here so the @shots line keys in the skip baseline stay put.
  await page.getByTestId(`alert-details-${event}`).click()
  await expect(page.getByTestId('alert-drawer').getByTestId('drawer-impact')).toHaveAttribute('data-state', 'insufficient_data')
  await expect(page.getByTestId('alert-drawer').getByTestId('drawer-log')).toContainText('راجع النتائج بعد أسبوع')

  expect(errors.filter((e) => !/401|favicon/i.test(e)), errors.join('\n')).toHaveLength(0)
})

for (const [scheme, width, height] of [['dark', 1440, 900], ['light', 1440, 900], ['dark', 375, 812], ['light', 375, 812]] as const) {
  test(`@shots the board and the drawer, ${scheme} ${width}`, async ({ page }) => {
    test.skip(!SHOTS, 'screenshots only when OIC_SHOTS names a folder')
    const { event } = seed(`${scheme}-${width}`)
    await page.addInitScript((s) => localStorage.setItem('campaign-hub-theme', s), scheme)
    await page.setViewportSize({ width, height })
    await page.goto('/agency/alerts')
    await expect(page.getByTestId('alert-board')).toBeVisible({ timeout: 20_000 })
    const box = await page.getByTestId('alert-board').boundingBox()
    console.log(`OIC_HEIGHT ${width} ${scheme} ${Math.round(box?.height ?? 0)}px of ${height}px screen`)
    await shoot(page, `list-${width}-${scheme}`)
    await page.getByTestId(`alert-open-drawer-${event}`).click()
    await expect(page.getByTestId('alert-drawer').getByTestId('drawer-field-evidence')).toBeVisible()
    await shoot(page, `drawer-${width}-${scheme}`)
  })
}

/**
 * LOST-CLICK-001 — choose the «resolved» filter only after the content above it has arrived.
 *
 * `AttentionBoard` renders nothing until its own request answers, then inserts a section a few hundred pixels tall
 * ABOVE the status chips. A click sent while that request is still out can land its `mousedown` on «مُغلقة» and its
 * `mouseup` wherever the chip was pushed away from, and then the browser fires no `click` at all. Playwright checks
 * only the first event of a click against its target (`setupHitTargetInterceptor`), so it reports success.
 * The filter stayed on «open», the resolved alert was never listed, and the test timed out 30 s later on
 * `alert-details-…`. The screenshot showed «مُغلقة 1» with the board already in place. That was firefox in CI
 * (the first run of #1023, then #1041); it never reproduced locally.
 *
 * So: wait for the board, which is the page's final shape, then click. Then ASSERT the click took, so a lost click
 * is reported where it happened and not as a missing row.
 *
 * It lives down here, not inline, because `.github/scripts/gate-allowed-skips.json` keys the `@shots` cases above by
 * line number.
 */
async function showResolved(page: Page) {
  await expect(page.getByTestId('alert-board')).toBeVisible({ timeout: 20_000 })
  const resolved = page.getByTestId('alerts-status').getByRole('button', { name: /مُغلقة|Resolved/ })
  await resolved.click()
  await expect(resolved, 'the «resolved» filter did not take').toHaveAttribute('aria-pressed', 'true')
}
