import { expect, test, type APIRequestContext, type Page } from '@playwright/test'
import { API_HEADERS, AUTH, seededProject, selectProject } from './helpers'

/**
 * OWNER DEFECT, /app/content — «images are now appearing, VIDEOS still do NOT render/play».
 *
 * ## Why this file exists beside `creative-analysis.spec.ts`
 *
 * That file already proves a video PLAYS — on `/agency/content/{id}`, the DETAIL page, after a click
 * on its play button. The owner's surface is neither: it is `/app/content`, the library GRID, where
 * nothing is clicked and no player is mounted. A card there shows a still, and for the Snapchat
 * shape — Production holds 549 video creatives and zero thumbnails — that still has to be decoded
 * out of the film itself by `VideoPoster`.
 *
 * Nothing has ever tested that in a browser. jsdom decodes no video at all: `contentVisible.test.tsx`
 * can assert the `<video>` element EXISTS and cannot assert that one pixel of it was painted. So the
 * one claim the owner is disputing — that the frame appears — was the one claim with no coverage,
 * in the one place jsdom cannot reach.
 *
 * ## What «rendered» is taken to mean
 *
 * Not «the element is in the DOM», which is what was already true while the owner saw nothing.
 * A frame counts as painted only when the browser reports real decoded dimensions AND the seek that
 * forces the paint has completed AND the element occupies real space on the page. Each of those
 * fails independently: a video that 404s decodes to 0×0, a `preload="metadata"` element in some
 * browsers never seeks, and a zero-height container renders a perfect frame nobody can see.
 */
const STORE_PROJECT = 'متجر تجريبي — Demo'

type Row = { id: string; preview?: { video_url?: string | null; thumbnail_url?: string | null; image_url?: string | null } }

/** The library the way the page asks for it — un-pinned, because a card carries no project id. */
async function videoOnlyCreatives(request: APIRequestContext): Promise<Row[]> {
  const res = await request.get('/api/v1/creatives?per_page=100', { headers: API_HEADERS })
  expect(res.ok(), `the library refused the request: ${res.status()}`).toBeTruthy()

  /* `data.creatives` — the library's own envelope. Reading `data` returns nothing and looks empty. */
  const rows: Row[] = (await res.json()).data?.creatives ?? []

  /* The Snapchat shape: a film and no cover. This is what the grid must decode a still out of. */
  return rows.filter((r) => r.preview?.video_url && !r.preview?.thumbnail_url && !r.preview?.image_url)
}

/*
 * The AGENCY portal, and the reason is access rather than convenience.
 *
 * The seeded library belongs to the `demo-agency` tenant, and `/app` is the ADVERTISER portal: an
 * agency account opening `/app/content` is met by «بوابة إدارة الحملات غير متاحة لحسابك», which is
 * correct behaviour and not a library at all. `/app/content` and `/agency/content` render the same
 * `CreativesPage`, so the media path under test here is the same code the owner is looking at — but
 * that is a statement about the component, NOT about the owner's route, and this file does not
 * claim otherwise. Owner acceptance on `/app/content` stays open in the ledger.
 */
async function openLibrary(page: Page, request: APIRequestContext): Promise<void> {
  const projectId = await seededProject(request, STORE_PROJECT)
  await selectProject(page, projectId)
  await page.goto('/agency/content')
  await expect(page.getByRole('heading', { name: /مكتبة المحتويات|Content library/ })).toBeVisible({ timeout: 30000 })
}

test.describe('the video previews on /app/content', () => {
  test.use({ storageState: AUTH.owner })

  test('a card with a film and no cover paints a real frame', async ({ page, request }) => {
    await openLibrary(page, request)

    const films = await videoOnlyCreatives(request)
    /*
     * Skipped LOUDLY, never quietly. A green test that asserted nothing about video is exactly how
     * this defect reached the owner: the detail-page test skips itself the same way, and a seed
     * without a coverless film would have hidden this too.
     */
    test.skip(films.length === 0, 'the seed holds no film without a cover — the shape this tests cannot occur')

    const poster = page.getByTestId('creative-video-poster').first()
    await expect(poster, 'the grid mounted no video poster for a film with no cover').toBeVisible({ timeout: 30000 })

    /*
     * The requirement is «no unexplained blank rectangle», not «every browser decodes a poster».
     *
     * Two rounds of this asserted `data-painted="true"` outright and CI's WebKit failed both: it will
     * not decode a frame under `preload="metadata"` without a gesture, and the card then sat as an
     * empty box — the owner's blank card, reproduced. A browser that manages the frame must show it;
     * one that cannot must say so instead. Both are acceptable; a silent hole is not, and this waits
     * for whichever answer arrives rather than demanding the one some engines cannot give.
     */
    /*
     * Watched on the POSTER, never on «is there an absence sentence somewhere on the page».
     *
     * The first version polled `creative-absence-reason` at page level, and a catalog card — which
     * legitimately says «this ad has no fixed asset» — satisfied it on the first tick, before the
     * film had decoded anything. The test then judged the film card by another card's sentence.
     * The seed holds exactly one coverless film, so the poster disappearing IS this card giving up.
     */
    await expect
      .poll(
        async () => {
          if ((await page.getByTestId('creative-video-poster').count()) === 0) return 'gave-up'

          return (await poster.getAttribute('data-painted')) === 'true' ? 'painted' : 'waiting'
        },
        { timeout: 25000, message: 'the card neither painted a frame nor gave up and explained itself' },
      )
      .not.toBe('waiting')

    /*
     * The fallback is a real sentence, not an empty element — and the dead `<video>` is GONE, because
     * a card that says «no cover» while still holding a blank player is the same rectangle with a
     * caption.
     */
    if ((await page.getByTestId('creative-video-poster').count()) === 0) {
      const absence = page.getByTestId('creative-absence-reason').first()

      await expect(absence, 'the card gave up on the film and explained nothing').toBeVisible()
      await expect(absence).not.toHaveText(/^\s*$/)

      return
    }

    const verdict = await poster.evaluate((v: HTMLVideoElement) => {
      const box = v.getBoundingClientRect()
      const style = getComputedStyle(v)

      return {
        error: v.error?.code ?? null,
        readyState: v.readyState,
        width: v.videoWidth,
        height: v.videoHeight,
        boxWidth: Math.round(box.width),
        boxHeight: Math.round(box.height),
        visibility: style.visibility,
        opacity: style.opacity,
      }
    })

    expect(verdict.error, 'the browser could not decode the film').toBeNull()
    expect(verdict.readyState, 'no frame was ever available to draw').toBeGreaterThanOrEqual(2)

    // Decoded — a 404 or an HTML document served as a film decodes to 0×0.
    expect(verdict.width, 'the film decoded to no width — it is not a film').toBeGreaterThan(0)
    expect(verdict.height).toBeGreaterThan(0)

    // …and actually on the page. A perfect frame in a zero-height box is the owner's blank card.
    expect(verdict.boxWidth, 'the poster occupies no width').toBeGreaterThan(0)
    expect(verdict.boxHeight, 'the poster occupies no height').toBeGreaterThan(0)
    expect(verdict.visibility).not.toBe('hidden')
    expect(Number(verdict.opacity)).toBeGreaterThan(0)
  })

  /**
   * The other half of the owner's sentence: «do not render **or play**».
   *
   * The grid is deliberately inert — twenty autoplaying films would cost a phone tens of megabytes —
   * so «play» belongs to the card the reader opens. The existing detail-page test drives a film that
   * HAS a cover; this one drives the coverless shape, where the player is the only thing that can
   * show the asset at all.
   */
  test('opening a film from the library plays it', async ({ page, request }) => {
    await openLibrary(page, request)

    const films = await videoOnlyCreatives(request)
    test.skip(films.length === 0, 'the seed holds no film without a cover')

    await page.goto(`/agency/content/${films[0].id}`)

    const video = page.locator('video')
    await expect(video, 'the detail page rendered no player').toBeVisible({ timeout: 30000 })

    const play = page.getByRole('button', { name: /play|تشغيل/i }).first()
    if (await play.count() > 0) await play.click()

    await expect
      .poll(async () => video.first().evaluate((v: HTMLVideoElement) => v.readyState), { timeout: 20000 })
      .toBeGreaterThanOrEqual(2)

    const verdict = await video.first().evaluate((v: HTMLVideoElement) => ({
      error: v.error?.code ?? null,
      width: v.videoWidth,
      height: v.videoHeight,
    }))

    expect(verdict.error, 'the browser could not decode the film').toBeNull()
    expect(verdict.width, 'the film decoded to no width').toBeGreaterThan(0)
    expect(verdict.height).toBeGreaterThan(0)
  })
})
