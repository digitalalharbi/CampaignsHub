import { expect, test } from '@playwright/test'
import { AUTH, seededProject, selectProject } from './helpers'

/**
 * UX-MULTISELECT-SCALE-001 — the campaign picker at two hundred campaigns, in a real browser.
 *
 * `option-scale.spec.ts` proves that a typed term reaches the server. It runs on the demo store,
 * which has fifteen campaigns, so it proves nothing about what the row is actually about: an
 * estate large enough that the picker cannot show it. The seed now carries a project built for
 * exactly that — `Scale — 241 campaigns`, one more than twice the picker's cap, with one campaign
 * whose name sorts after every other so the cap hides it and only the search can reach it.
 *
 * Three claims, all read off the screen and the network together:
 *   1. the picker is BOUNDED — it shows the cap and says so, rather than rendering the estate;
 *   2. the statement is a fact from the server («has_more» is read one row past the cap), not an
 *      inference from a full page;
 *   3. a campaign the cap hid is reachable by search, and the note goes away once the list fits.
 */
const CAP = 120
const SCALE_PROJECT = 'Scale — 241 campaigns'
const HIDDEN = 'Zayed launch — past the cap'

test.describe('the campaign picker at production cardinality', () => {
  test.use({ storageState: AUTH.owner })

  test('bounds the list, says so from the server, and search reaches past the cap', async ({ page, request }) => {
    test.setTimeout(180_000)
    await selectProject(page, await seededProject(request, SCALE_PROJECT))

    const answers: Array<{ url: string; has_more: unknown; count: number }> = []
    page.on('response', async (r) => {
      if (!r.url().includes('metrics/campaign-options')) return
      try {
        const body = (await r.json()) as { data?: { options?: unknown[]; has_more?: unknown } }
        answers.push({ url: r.url(), has_more: body.data?.has_more, count: body.data?.options?.length ?? -1 })
      } catch {
        /* a non-JSON answer is recorded nowhere and fails the assertions below on absence */
      }
    })

    await page.goto('/agency/analytics')
    await expect(page.locator('main')).toBeVisible({ timeout: 30_000 })

    const picker = page.getByTestId('analytics-campaign')
    await expect(picker, 'the scale project offers no campaign filter').toBeVisible({ timeout: 30_000 })
    await picker.click()

    /*
     * The campaign LISTBOX, not every option on the page. The first run counted 150: the listbox's
     * 120 plus the native <option> elements of the project, action and objective selects beside it —
     * a page-wide role query that read as «the cap is broken» while the control was exactly bounded.
     */
    const listbox = page.getByRole('listbox', { name: /الحملة|campaign/i })
    await expect(listbox).toBeVisible({ timeout: 20_000 })

    /* 1. Bounded, and said: the cap is shown and the note names it. */
    const note = page.getByTestId('analytics-campaign-has-more')
    await expect(note, 'the picker rendered the estate without saying there is more').toBeVisible({ timeout: 20_000 })
    await expect(note).toContainText(String(CAP))
    const shown = await listbox.getByRole('option').count()
    expect(shown, 'more options in the DOM than the cap').toBeLessThanOrEqual(CAP)
    expect(shown, 'the picker showed nothing at all').toBeGreaterThan(0)

    /* The hidden campaign is not among them — the cap is real, not a count on a full list. */
    await expect(listbox.getByRole('option', { name: HIDDEN })).toHaveCount(0)

    /* 2. The statement came from the server, one row past the cap. */
    await expect.poll(() => answers.length, { timeout: 20_000 }).toBeGreaterThan(0)
    const first = answers.find((a) => !/[?&]q=/.test(a.url))
    expect(first, `no unfiltered options request was answered: ${answers.map((a) => a.url).join(' ')}`).toBeTruthy()
    expect(first!.has_more, 'the server did not say there is more').toBe(true)
    expect(first!.count, 'the server answered more rows than its own cap').toBeLessThanOrEqual(CAP)

    /* 3. Search reaches what the cap hid, and the note leaves once the list fits. */
    const search = listbox.getByRole('searchbox').first()
    await expect(search, 'a picker past the cap offers no search box').toBeVisible()
    await search.fill('Zayed')

    await expect(listbox.getByRole('option', { name: HIDDEN })).toBeVisible({ timeout: 20_000 })
    await expect(note).toHaveCount(0)

    const narrowed = answers.find((a) => /[?&]q=Zayed/i.test(a.url))
    expect(narrowed, 'the typed term never reached the server').toBeTruthy()
    expect(narrowed!.has_more, 'the server still claims more after a narrowing that fits').toBe(false)
  })
})
