import { expect, test, type Page } from '@playwright/test'
import { AUTH, seededProject, selectProject, switchToEnglish } from './helpers'

/**
 * CREATIVE-PREVIEW-POPUP-001 — the review popup shows the WHOLE ad, whatever shape it is.
 *
 * The defect was measured in a browser, not inferred: the poster carried BOTH `object-cover`
 * (injected by `AdPoster` for anything it did not read as portrait) and the `object-contain` the
 * dialog passed, and `cover` won the cascade. A 600×600 creative was drawn into a 497×416 box, so
 * about 16% of its height was cut off the top and bottom — and because `previewShape` calls
 * anything under `height > width * 1.2` landscape, SQUARE and HORIZONTAL ads were the ones being
 * cropped, which is most of a real library.
 *
 * A unit test cannot see this. Both utilities were present in the markup and the component
 * rendered without complaint; only the browser's cascade decided which applied, and only a layout
 * measurement shows the missing pixels. So this asserts the MEASUREMENT, not the class list: a
 * class assertion would have passed on the broken markup, which held the right class all along.
 */

/*
 * The POSTER button, by name rather than by position.
 *
 * This was `article button`, which worked only while a card held exactly one button: the card now
 * also carries the «+N» control that reveals the figures the objective did not lead with, so the
 * nth button stopped being the nth card's poster. The testid is on the poster itself.
 */
const CARDS = '[data-testid="creative-card-open"]'
const STAGE = '[data-testid="ad-preview-dialog-stage"]'

async function open(page: Page, index: number) {
  await page.locator(CARDS).nth(index).click()
  await expect(page.getByTestId('ad-preview-dialog')).toBeVisible({ timeout: 15000 })
}

async function close(page: Page) {
  await page.getByTestId('ad-preview-dialog-close').click()
  await expect(page.getByTestId('ad-preview-dialog')).toBeHidden({ timeout: 10000 })
}

/** What the browser actually laid out, for whichever element this format renders. */
async function measure(page: Page) {
  return page.evaluate(
    ([stageSel, dialogSel]) => {
      const stage = document.querySelector(stageSel)
      const dialog = document.querySelector(dialogSel)
      const img = stage?.querySelector('img') as HTMLImageElement | null
      const video = stage?.querySelector('video') as HTMLVideoElement | null
      const el = img ?? video
      const sb = stage?.getBoundingClientRect()
      const b = el?.getBoundingClientRect()
      return {
        kind: img ? 'image' : video ? 'video' : 'none',
        fit: el ? getComputedStyle(el).objectFit : null,
        naturalWidth: img?.naturalWidth ?? 0,
        naturalHeight: img?.naturalHeight ?? 0,
        width: b?.width ?? 0,
        height: b?.height ?? 0,
        stageWidth: sb?.width ?? 0,
        stageHeight: sb?.height ?? 0,
        // A panel that needs scrolling has failed the «review it in one view» requirement.
        overflow: dialog ? dialog.scrollHeight - dialog.clientHeight : -1,
      }
    },
    [STAGE, '[data-testid="ad-preview-dialog"]'] as const,
  )
}

/**
 * Open cards until one renders the kind of asset this check is about.
 *
 * Asked through `measure`, which queries the document and never WAITS: a locator for an `img`
 * inside a stage that holds a film waits out the whole test instead of moving to the next card.
 */
async function findCard(page: Page, want: 'image' | 'video', limit = 6) {
  const total = Math.min(await page.locator(CARDS).count(), limit)

  for (let i = 0; i < total; i++) {
    await open(page, i)
    const found = await measure(page)

    if (found.kind === want) {
      // A picture is only measurable once it has decoded.
      if (want === 'image') {
        await expect
          .poll(async () => (await measure(page)).naturalWidth, { timeout: 10000 })
          .toBeGreaterThan(0)
      }
      return i
    }

    await close(page)
  }

  return -1
}

for (const locale of ['ar', 'en'] as const) {
  test.describe(`the creative review popup (${locale})`, () => {
    test.use({ storageState: AUTH.owner, viewport: { width: 1440, height: 900 } })
    // Several cards are opened and measured per check, on three engines.
    test.describe.configure({ timeout: 90_000 })

    test.beforeEach(async ({ page, request }) => {
      await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
      await page.goto('/agency/content')
      // AFTER the shell is up: the toggle is a control in the app, and reading the stored locale
      // on `about:blank` is a SecurityError.
      if (locale === 'en') await switchToEnglish(page)
      await expect(page.locator(CARDS).first()).toBeVisible({ timeout: 20000 })
    })

    test('no creative in the library is cropped, whatever its shape', async ({ page }) => {
      const total = await page.locator(CARDS).count()
      expect(total).toBeGreaterThan(0)
      const sample = Math.min(total, 5)
      let measured = 0

      for (let i = 0; i < sample; i++) {
        await open(page, i)
        const m = await measure(page)

        if (m.kind !== 'none') {
          measured++
          // Never `cover` here: cover is exactly what cut the square ad.
          expect(m.fit, `card ${i} object-fit`).toBe('contain')
          // Bounded on BOTH axes, so the stage's hidden overflow can never clip the asset.
          expect(m.width, `card ${i} width vs stage`).toBeLessThanOrEqual(m.stageWidth + 1)
          expect(m.height, `card ${i} height vs stage`).toBeLessThanOrEqual(m.stageHeight + 1)
        }

        if (m.kind === 'image' && m.naturalHeight > 0) {
          // The shape on screen is the shape of the file — the whole point of the fix.
          const asked = m.naturalWidth / m.naturalHeight
          const got = m.width / m.height
          expect(Math.abs(asked - got), `card ${i} kept its ratio`).toBeLessThan(0.03)
        }

        expect(m.overflow, `card ${i} needed scrolling`).toBeLessThanOrEqual(0)
        await close(page)
      }

      // Guards the guard: with no asset anywhere, every assertion above is vacuous.
      expect(measured, 'the sample held a real asset to measure').toBeGreaterThan(0)
    })

    /*
     * The shapes a real account buys, served into the product's own element.
     *
     * The demo library is square and film, so asserting only over it would leave 9:16 — the shape
     * most of this product's spend is in — unproven. Every picture request is answered with an SVG
     * at exact pixel dimensions, which each engine reports through `naturalWidth`/`naturalHeight`,
     * so what is measured is the product's layout and not a fixture's.
     */
    for (const shape of [
      { name: '9:16 story', w: 1080, h: 1920 },
      { name: '4:5 portrait', w: 1080, h: 1350 },
      { name: '1:1 square', w: 1080, h: 1080 },
      { name: '16:9 landscape', w: 1920, h: 1080 },
    ] as const) {
      test(`shows a ${shape.name} creative whole`, async ({ page }) => {
        const index = await findCard(page, 'image')
        expect(index, 'the library rendered a picture to re-shape').toBeGreaterThanOrEqual(0)

        /*
         * The asset is only a RULER here: what is under test is the product's own stage and the fit
         * it gives the element. So the shape is handed to the element the product already rendered,
         * in the page, rather than by intercepting the network — interception blocked the worker in
         * a way no test timeout could interrupt, and it never bought anything this does not.
         */
        const loaded = await page
          .locator(`${STAGE} img`)
          .first()
          .evaluate(
            (el: HTMLImageElement, size) =>
              new Promise<boolean>((resolve) => {
                const svg =
                  `<svg xmlns="http://www.w3.org/2000/svg" width="${size.w}" height="${size.h}" ` +
                  `viewBox="0 0 ${size.w} ${size.h}"><rect width="100%" height="100%" fill="#2563eb"/></svg>`
                el.addEventListener('load', () => resolve(true), { once: true })
                el.addEventListener('error', () => resolve(false), { once: true })
                el.removeAttribute('srcset')
                el.src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`
              }),
            { w: shape.w, h: shape.h },
          )
        expect(loaded, 'the re-shaped asset decoded').toBe(true)

        const m = await measure(page)
        expect(m.naturalWidth, 'the element took the new shape').toBe(shape.w)
        expect(m.naturalHeight).toBe(shape.h)
        expect(m.fit).toBe('contain')

        // Inside the stage on both axes...
        expect(m.width).toBeLessThanOrEqual(m.stageWidth + 1)
        expect(m.height).toBeLessThanOrEqual(m.stageHeight + 1)
        // ...at its true proportions...
        const got = m.width / m.height
        expect(Math.abs(shape.w / shape.h - got), `${shape.name} kept its ratio`).toBeLessThan(0.03)
        // ...big enough to actually review: the tighter axis fills the stage...
        const filled = Math.max(m.width / m.stageWidth, m.height / m.stageHeight)
        expect(filled, `${shape.name} used the stage`).toBeGreaterThan(0.95)
        // ...and the whole review still in one view.
        expect(m.overflow).toBeLessThanOrEqual(0)
      })
    }

    test('a film is bounded by its stage, not by the window', async ({ page }) => {
      const index = await findCard(page, 'video', 8)
      expect(index, 'the library held a film to measure').toBeGreaterThanOrEqual(0)

      const m = await measure(page)
      // `max-h-[70vh]` is right on a page where the player is the tallest thing present, and wrong
      // in a 58vh stage: the frame spilled a box whose overflow is hidden.
      expect(m.fit).toBe('contain')
      expect(m.height).toBeLessThanOrEqual(m.stageHeight + 1)
      expect(m.width).toBeLessThanOrEqual(m.stageWidth + 1)
      expect(m.overflow).toBeLessThanOrEqual(0)
    })

    test('the review keeps its figures, its trend and the way on', async ({ page }) => {
      // The popup is a judgement surface: the shape fix must not cost the reading of it.
      const total = Math.min(await page.locator(CARDS).count(), 5)
      let judged = false

      for (let i = 0; i < total && !judged; i++) {
        await open(page, i)
        if (await page.getByTestId('ad-preview-dialog-figures').isVisible().catch(() => false)) {
          judged = true
          await expect(page.getByTestId('ad-preview-dialog-trend')).toBeVisible()
          await expect(page.getByTestId('ad-preview-dialog-meta')).toBeVisible()
          await expect(page.getByTestId('ad-preview-dialog-details')).toBeVisible()
          const m = await measure(page)
          expect(m.overflow, 'figures, trend and CTA fit one view').toBeLessThanOrEqual(0)
        }
        await close(page)
      }

      expect(judged, 'a creative with figures was reviewed').toBe(true)
    })
  })
}
