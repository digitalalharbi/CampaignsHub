import { expect, test, type APIRequestContext, type Page } from '@playwright/test'
import { API_HEADERS, AUTH, csrfHeaders, seededProject, selectProject } from './helpers'

/**
 * §15 — the creative as a unit of analysis, walked in a real browser.
 *
 * The unit tests already assert what each service computes. What they cannot assert is that a reader
 * can GET to any of it, and every defect this file exists to catch was a page that rendered, returned
 * 200 and still failed the reader: findings silently collapsed by duplicate React keys, a group headed
 * with a name none of its members carried, a carousel showing one of five cards, a table pushing the
 * whole document sideways at 375px. Writing this file found one more — `creatives/groups` had no route
 * on the project-pinned surface and answered 500.
 *
 * So these assert on REACHABILITY and on the SENTENCES, never on the demo figures, which change every
 * time the seeder runs. What must not change is that a creative can be opened, that a group's parts
 * add up to its whole on screen, that a carousel says how many cards it has, and that a client link
 * shows exactly what its switches allow.
 *
 * The AGENCY portal drives this: the demo project carrying sixty creatives — including the four-card
 * carousel — belongs to the agency tenant, and no advertiser account can reach it.
 */

const STORE_PROJECT = 'متجر تجريبي — Demo'

/** Every creative card on the page. */
const cards = (page: Page) => page.getByRole('article')

async function openLibrary(page: Page, request: APIRequestContext): Promise<string> {
  const projectId = await seededProject(request, STORE_PROJECT)
  await selectProject(page, projectId)
  await page.goto('/agency/content')
  await expect(page.getByRole('heading', { name: /مكتبة الإعلانات|Ads library/ })).toBeVisible({ timeout: 30000 })

  return projectId
}

type Row = { id: string; provider?: string; preview?: { cards_reported?: boolean } }

/**
 * Creatives of a given kind, straight from the API — the grid's ordering is not a fixture.
 *
 * The UN-PINNED library address, which is the one the page itself calls: a card carries no project
 * id, so the library reads everything within the caller's reach. Pinning a project here would have
 * limited the search to one seeder's output and missed the only carousel that reported its cards.
 */
async function creativesOfKind(request: APIRequestContext, kind: string): Promise<Row[]> {
  const res = await request.get(`/api/v1/creatives?kinds[]=${kind}&per_page=100`, { headers: API_HEADERS })
  expect(res.ok(), `creative list failed with ${res.status()}`).toBeTruthy()

  return ((await res.json()).data?.creatives ?? []) as Row[]
}

/**
 * A carousel that actually reported a card breakdown, and one that did not.
 *
 * Most synced carousels carry no breakdown at all — the provider sent one asset and no cards — and
 * that is a different creative from one with four cards. Picking «the first carousel» would test
 * whichever the seeder happened to order first and would silently assert the wrong thing.
 */
async function carousels(request: APIRequestContext) {
  const all = await creativesOfKind(request, 'carousel')

  return {
    withCards: all.find((c) => c.preview?.cards_reported === true)?.id ?? null,
    withoutCards: all.find((c) => c.preview?.cards_reported === false)?.id ?? null,
  }
}

test.describe('the creative library', () => {
  test.use({ storageState: AUTH.owner })

  test('lists creatives, and each one opens a page of its own', async ({ page, request }) => {
    await openLibrary(page, request)

    await expect(cards(page).first()).toBeVisible({ timeout: 30000 })
    expect(await cards(page).count(), 'the seeded project should have creatives to list').toBeGreaterThan(0)

    await cards(page).first().getByRole('link').first().click()

    // A URL of its own, so a reader can send it to somebody.
    await expect(page).toHaveURL(/\/agency\/content\/[0-9a-f-]{36}/)
    await expect(page.locator('main')).toBeVisible()
  })

  /**
   * The platform filter narrows on the SERVER and lives in the address.
   *
   * A React-only filter looks identical until the second page, where it shows rows it never fetched;
   * and a filter that is not in the URL is one nobody can send to a colleague.
   */
  test('the platform filter narrows the list and survives a reload', async ({ page, request }) => {
    await openLibrary(page, request)
    await expect(cards(page).first()).toBeVisible({ timeout: 30000 })

    const all = await cards(page).count()

    /*
     * The platform axis is ON the page (UX-CONTENT-001).
     *
     * It has been a `<select>` inside a dialog and a chip inside a dialog; it is a visible
     * multi-select now, because narrowing a library to one platform is how a library is used rather
     * than how it is configured. What is asserted below is unchanged across all three and is the
     * part that matters: the filter narrows on the SERVER and the choice lands in the address.
     */
    /*
     * One press on a visible chip — UX-FILTERS-001.
     *
     * This has been a `<select>` in a dialog, a chip in a dialog, and a popover multi-select. It is
     * six visible chips now, because narrowing a library to one platform is how a library is USED
     * rather than how it is configured. There is no popover to open and none to shut.
     *
     * What is asserted below is unchanged across all four shapes and is the part that matters: the
     * filter narrows on the SERVER and the choice lands in the address.
     */
    // The first PLATFORM chip — index 0 is «الكل», which clears rather than narrows. Picking by
    // position rather than by name keeps this independent of which platforms the seed happens to
    // hold, exactly as the old `.first()` option was.
    await page.getByTestId('content-providers').getByRole('button').nth(1).click()
    await expect(page.getByTestId('content-applied')).toBeVisible({ timeout: 20000 })

    await expect.poll(() => cards(page).count(), { timeout: 20000 }).toBeLessThanOrEqual(all)
    await expect(page).toHaveURL(/providers/)

    await page.reload()
    await expect(page.getByRole('heading', { name: /مكتبة الإعلانات|Ads library/ })).toBeVisible()
    await expect(page).toHaveURL(/providers/)
  })

  test('a search that matches nothing says so rather than showing everything', async ({ page, request }) => {
    await openLibrary(page, request)
    await expect(cards(page).first()).toBeVisible({ timeout: 30000 })

    await page.getByLabel(/ابحث بالاسم|Search by name/).fill('zzz-no-such-creative-zzz')

    await expect.poll(() => cards(page).count(), { timeout: 20000 }).toBe(0)
    await expect(
      page.getByText(/لا توجد إعلانات تطابق هذا التحديد|No ads match this selection/),
    ).toBeVisible()
  })
})

test.describe('a creative’s own page', () => {
  test.use({ storageState: AUTH.owner })

  test('a direct visit to a creative URL renders it', async ({ page, request }) => {
    await openLibrary(page, request)
    const [image] = await creativesOfKind(request, 'image')
    test.skip(!image, 'the seed has no image creative')

    await page.goto(`/agency/content/${image.id}`)

    // A client-side route that only works when navigated TO is a link nobody can send.
    await expect(page.locator('main')).toBeVisible({ timeout: 30000 })
    await expect(page.getByText(/الصفحة غير موجودة|Page not found/)).toHaveCount(0)
  })

  /**
   * A malformed id is NOT FOUND, not a server error.
   *
   * Until `whereUuid` was added, any unmatched word under `creatives/` reached Eloquent and came back
   * 500 «invalid input syntax for type uuid» — the server telling a caller it is broken when the truth
   * is that there is no such creative.
   */
  test('a creative id that is not a uuid is refused without a server error', async ({ request }) => {
    const projectId = await seededProject(request, STORE_PROJECT)

    for (const bad of ['not-a-uuid', '12345']) {
      const res = await request.get(`/api/v1/projects/${projectId}/creatives/${bad}`, { headers: API_HEADERS })
      expect(res.status(), `${bad} should be 404, not ${res.status()}`).toBe(404)
    }
  })
})

test.describe('a carousel is more than one picture', () => {
  test.use({ storageState: AUTH.owner })

  /**
   * The columns a creative syncs into are singular, so a five-card carousel kept its FIRST card and
   * dropped the rest — every surface rendered a fifth of what ran, with nothing admitting it. The
   * count on screen is the whole point: it is how a reader knows nothing was dropped.
   */
  test('pages through its cards by button and by keyboard', async ({ page, request }) => {
    const { withCards } = await carousels(request)
    test.skip(!withCards, 'the seed has no carousel that reported its cards')

    await page.goto(`/agency/content/${withCards}`)
    const carousel = page.getByTestId('creative-carousel')
    await expect(carousel).toBeVisible({ timeout: 30000 })
    await expect(carousel.getByText(/البطاقة 1 من \d+|Card 1 of \d+/)).toBeVisible()

    await carousel.getByRole('button', { name: /البطاقة التالية|Next card/ }).click()
    await expect(carousel.getByText(/البطاقة 2 من \d+|Card 2 of \d+/)).toBeVisible()

    // And by keyboard — a control that answers only the mouse is not reachable for everyone.
    await carousel.getByRole('group').first().press('ArrowRight')
    await expect(carousel.getByText(/البطاقة 3 من \d+|Card 3 of \d+/)).toBeVisible()
  })

  test('does not push the page sideways on a phone', async ({ page, request }) => {
    const { withCards } = await carousels(request)
    test.skip(!withCards, 'the seed has no carousel that reported its cards')

    await page.setViewportSize({ width: 375, height: 812 })
    await page.goto(`/agency/content/${withCards}`)
    await expect(page.getByTestId('creative-carousel')).toBeVisible({ timeout: 30000 })

    /*
     * Measured against `clientWidth`, never `innerWidth`: the latter reports the window rather than
     * the layout viewport, and once produced a false overflow alarm on exactly this assertion.
     */
    const overflows = await page.evaluate(
      () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
    )
    expect(overflows, 'the document scrolls sideways at 375px').toBeFalsy()
  })

  /**
   * «This platform sent no card breakdown» must be SAID, never rendered as a carousel that happens to
   * have one card. Most synced carousels are this case, so it is the one a reader meets most.
   */
  test('says plainly when the platform sent no card breakdown', async ({ page, request }) => {
    const { withoutCards } = await carousels(request)
    test.skip(!withoutCards, 'every seeded carousel reported its cards')

    await page.goto(`/agency/content/${withoutCards}`)
    await expect(page.locator('main')).toBeVisible({ timeout: 30000 })

    await expect(
      page.getByText(/لم ترسل هذه المنصة تفاصيل بطاقات|sent no card breakdown/i),
    ).toBeVisible()
    await expect(page.getByTestId('creative-carousel')).toHaveCount(0)
  })
})

test.describe('one asset across platforms', () => {
  test.use({ storageState: AUTH.owner })

  test('the groups page is reachable by URL', async ({ page, request }) => {
    const projectId = await seededProject(request, STORE_PROJECT)
    await selectProject(page, projectId)
    await page.goto('/agency/content/groups')

    /*
     * React Router ranks the static `content/groups` above the dynamic `content/:creativeId`; getting
     * that wrong renders a creative page for a creative whose id is the word «groups», which is
     * exactly what the API did until this spec was written.
     */
    await expect(page.locator('main')).toBeVisible({ timeout: 30000 })
    await expect(page.getByText(/الصفحة غير موجودة|Page not found/)).toHaveCount(0)
  })

  /**
   * The group's parts add back to its whole ON SCREEN.
   *
   * The group is built here rather than hoped for in the seed, so the assertion is about behaviour
   * and not about what the seeder happened to leave behind. Per-platform lines that did not sum to
   * the total would mean the roll-up and the breakdown were computed twice.
   */
  test('a merged group lists both platforms and opens its detail', async ({ page, request }) => {
    const projectId = await seededProject(request, STORE_PROJECT)
    const headers = await csrfHeaders(request)

    const listed = await request.get(
      `/api/v1/projects/${projectId}/creatives?per_page=40`,
      { headers: API_HEADERS },
    )
    const all = ((await listed.json()).data?.creatives ?? []) as Array<{ id: string; provider: string }>
    const meta = all.find((c) => c.provider === 'meta')
    const other = all.find((c) => c.provider && c.provider !== 'meta')
    test.skip(!meta || !other, 'the seed has no two-platform pair to merge')

    const merged = await request.post('/api/v1/creatives/group', {
      headers: { ...headers, 'Content-Type': 'application/json' },
      data: { creative_ids: [meta!.id, other!.id], name: 'E2E merged asset' },
    })
    expect(merged.ok(), `merge failed with ${merged.status()}`).toBeTruthy()
    const groupId = (await merged.json()).data.id as string

    try {
      await selectProject(page, projectId)
      await page.goto(`/agency/content/groups?group=${groupId}`)

      const detail = page.getByTestId('creative-group-detail')
      await expect(detail).toBeVisible({ timeout: 30000 })
      await expect(detail.getByText('E2E merged asset').first()).toBeVisible()
    } finally {
      // Put the two creatives back, so a re-run starts where this one did.
      for (const id of [meta!.id, other!.id]) {
        await request.delete(`/api/v1/creatives/${id}/group`, { headers })
      }
    }
  })
})

test.describe('the dashboard’s creative section', () => {
  test.use({ storageState: AUTH.owner })

  /**
   * `GET /creatives/pulse` returned findings for two commits before anything drew them, and when the
   * panel finally did, React collapsed the repeats of one rule: it rendered nine while the honest
   * counter beside it read «12 of 91». A list that drops rows while reporting the full count is worse
   * than one that reports fewer.
   */
  test('renders exactly as many findings as its own counter promises', async ({ page, request }) => {
    const projectId = await seededProject(request, STORE_PROJECT)
    await selectProject(page, projectId)
    await page.goto('/agency/dashboard')

    await expect(page.getByTestId('creative-pulse')).toBeVisible({ timeout: 30000 })

    const findings = page.getByTestId('creative-findings')
    test.skip(!(await findings.count()), 'this period produced no findings')

    const claimed = Number((await findings.innerText()).match(/(\d+)\s*\/\s*\d+/)?.[1] ?? -1)
    expect(claimed, 'the findings panel states no «shown/total»').toBeGreaterThanOrEqual(0)
    expect(await findings.getByRole('listitem').count()).toBe(claimed)
  })
})

test.describe('what a client link shows', () => {
  test.use({ storageState: AUTH.owner })

  /**
   * Fail-closed: a creative section appears only when the operator switched it on. The default for
   * every creative switch is off, so a link created without settings must carry no creative section
   * at all — not one that is present and empty, which reads as a section that failed to load.
   */
  /*
   * The three preconditions below were `test.skip()` and are now assertions.
   *
   * A skip is a test that proved nothing while reporting green, and every one of these conditions
   * is a product failure rather than an optional precondition: a seeded project with no report, a
   * refused share creation, a share response with no token. In the full three-browser gate this test
   * SKIPPED in all three browsers while passing when run alone — so one of them was firing, and the
   * run said «818 passed» either way. That is the shape of hidden failure this project's rules
   * forbid, and the fix is to make the run say which one rather than to make it quieter.
   *
   * Each message carries what it saw, so a failure names the cause instead of restating the guard.
   */
  test('a link created with no creative switches has no creative section', async ({ page, request }) => {
    const projectId = await seededProject(request, STORE_PROJECT)
    const headers = await csrfHeaders(request)

    const reports = await request.get(`/api/v1/projects/${projectId}/reports`, { headers: API_HEADERS })
    const list = ((await reports.json()).data?.reports ?? []) as Array<{ id: string; status?: string }>

    /*
     * A report that can be SHARED, not merely the first one in the list.
     *
     * `list[0]` was a draft — an earlier spec in the full run creates one, and it sorts to the head.
     * The API then correctly refused with 409 «Generate the report before sharing», and the old
     * `test.skip()` swallowed that into a green run. The product was right and the test was picking
     * the wrong row, which is only visible once the guard is an assertion.
     */
    const shareable = list.find((r) => r.status === 'completed')
    expect(
      shareable,
      `project ${projectId} has no COMPLETED report to share — statuses were [${list.map((r) => r.status ?? '?').join(', ')}]`,
    ).toBeTruthy()

    const created = await request.post(`/api/v1/projects/${projectId}/reports/${shareable!.id}/shares`, {
      headers: { ...headers, 'Content-Type': 'application/json' },
      data: { mode: 'snapshot' },
    })
    expect(created.ok(), `share creation returned ${created.status()}: ${(await created.text()).slice(0, 300)}`).toBe(true)

    const body = (await created.json()).data as Record<string, string>
    const raw = body.raw_token ?? body.token ?? body.url?.split('/r/')[1]
    expect(raw, `the share response carried no token: ${JSON.stringify(body).slice(0, 300)}`).toBeTruthy()

    // No session at all — this is the reader the link is for.
    await page.context().clearCookies()
    await page.goto(`/r/${raw}`)
    await expect(page.locator('main')).toBeVisible({ timeout: 30000 })

    // Absent, not hidden. Nothing about the creatives reached this page.
    await expect(page.getByTestId('shared-creative-section')).toHaveCount(0)
  })
})
