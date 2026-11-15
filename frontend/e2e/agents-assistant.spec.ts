import { execFileSync } from 'node:child_process'
import { expect, test } from '@playwright/test'
import { E2E_BACKEND_ENV } from './env'
import { AUTH, seededProject, selectProject } from './helpers'

/**
 * AGENTS-ASSISTANT-001 — «مركز الوكلاء» end to end, against the real backend and the real queue.
 *
 * The model is the gate's sandbox driver (`AGENTS_MODEL_DRIVER=sandbox`): a local script that reads the campaigns through the real
 * tools, streams its words through the real live buffer and raises a real proposal through the real gateway — no provider is
 * called. Approval is recorded and never carried to a platform (`AGENTS_EXECUTE_PROVIDERS=none`), so «reflected» is the card
 * leaving «pending» with the gateway's own answer.
 */
const STORE_PROJECT = 'متجر تجريبي — Demo'

/**
 * The demo bindings are seeded with syncing OFF, so the gateway (rightly) refuses any change to them as not actively assigned.
 * For this walk ONE Meta binding of the project is switched on in the E2E database, and switched back off after; the queue worker
 * runs no sync sweep and `AGENTS_EXECUTE_PROVIDERS=none` keeps the approved change from ever reaching Meta.
 */
function metaSync(projectId: string, on: boolean): void {
  const code = `App\\Domains\\Integrations\\Models\\ProjectIntegrationBinding::withoutGlobalScopes()->where('project_id', '${projectId}')->where('provider', 'meta')->where('is_active', true)->update(['sync_enabled' => ${on ? 'true' : 'false'}]); echo 'OK';`
  const out = execFileSync('php', ['artisan', 'tinker', '--execute', code], {
    cwd: new URL('../../backend', import.meta.url).pathname,
    env: { ...process.env, ...E2E_BACKEND_ENV },
    encoding: 'utf-8',
  })
  if (!out.includes('OK')) throw new Error(`could not switch the Meta binding: ${out}`)
}

test.describe('the agent center assistant', () => {
  test.use({ storageState: AUTH.owner })

  test('open → key → ask → streamed answer → proposal → approve → reflected; settings drawer; memory', async ({ page, request }) => {
    test.setTimeout(180_000)
    const projectId = await seededProject(request, STORE_PROJECT)
    metaSync(projectId, true)
    test.info().annotations.push({ type: 'restore', description: 'Meta binding sync switched back off at the end' })
    try {
      await walk(page, projectId)
    } finally {
      metaSync(projectId, false)
    }
  })

  async function walk(page: import('@playwright/test').Page, projectId: string): Promise<void> {
    await selectProject(page, projectId)
    await page.goto('/agency/agents')
    await expect(page.getByTestId('agent-center')).toBeVisible({ timeout: 30_000 })

    // Settings drawer: a key is stored masked (the sandbox never sends it anywhere) and the test-connection answers.
    await page.getByTestId('open-settings').click()
    const drawer = page.getByTestId('settings-drawer')
    await expect(drawer).toBeVisible()
    await drawer.getByTestId('agent-ai-key-input').fill('sk-ant-e2e-sandbox-key-0000')
    await drawer.getByTestId('agent-ai-key-save').click()
    const keyRow = drawer.getByTestId('settings-key-row').first()
    await expect(keyRow).toContainText('••••0000')
    await expect(drawer).not.toContainText('sk-ant-e2e')
    await keyRow.getByTestId('settings-key-test').click()
    await expect(drawer.getByTestId('settings-key-test-result').first()).toBeVisible()
    await expect(drawer.getByTestId('routine-daily_analysis')).toContainText('08:45')

    // Memory: added in the drawer, visible, then forgotten.
    await drawer.getByTestId('memory-input').fill('اختصر دائمًا')
    await drawer.getByTestId('memory-add').click()
    await expect(drawer.getByTestId('memory-row')).toContainText('اختصر دائمًا')
    await drawer.getByTestId('settings-close').click()
    await expect(drawer).toBeHidden()

    // Ask: the reply streams in, then lands as the stored answer with its specialist chip and a proposal card.
    const input = page.getByTestId('agent-input')
    await input.fill('أوقف حملة ميتا الأعلى إنفاقًا')
    await input.press('Enter')
    await expect(page.getByTestId('agent-streaming')).toBeVisible({ timeout: 30_000 })
    const answer = page.getByTestId('agent-message-assistant').last()
    await expect(answer).toContainText('الإنفاق', { timeout: 60_000 })
    await expect(answer.getByTestId('specialist-chip')).toContainText('حملات')
    await expect(answer.getByTestId('answer-sources')).toBeVisible()
    const card = answer.getByTestId('proposal-card')
    await expect(card).toHaveAttribute('data-status', 'pending')

    // Approve: the gateway decides and the card reflects its answer — no longer waiting.
    await card.getByTestId('proposal-approve').click()
    await expect(card).not.toHaveAttribute('data-status', 'pending', { timeout: 30_000 })

    // The conversation is in the rail; «الفريق» classifies by job.
    await expect(page.getByTestId('conversation-rail')).toContainText('أوقف حملة ميتا')
    await page.getByRole('tab', { name: 'الفريق' }).click()
    await expect(page.getByTestId('team-job-watch')).toBeVisible()

    // Memory cleanup through the drawer: forget all, confirmed.
    await page.getByTestId('open-settings').click()
    await drawer.getByTestId('memory-forget-all').click()
    await drawer.getByTestId('memory-forget-yes').click()
    await expect(drawer.getByTestId('memory-empty')).toBeVisible()
  }

  test('no horizontal scroll at 1280, 1440 and phone width', async ({ page, request }) => {
    await selectProject(page, await seededProject(request, STORE_PROJECT))
    for (const [width, height] of [[1280, 800], [1440, 900], [390, 844]] as const) {
      await page.setViewportSize({ width, height })
      await page.goto('/agency/agents')
      await expect(page.getByTestId('agent-center')).toBeVisible({ timeout: 30_000 })
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
      expect(overflow, `horizontal scroll at ${width}px`).toBeLessThanOrEqual(0)
    }
  })
})
