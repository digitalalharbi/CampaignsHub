import { expect, test } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * AD-MEDIA-RECOVERY-001 — the reason an ad has no picture is not itself allowed to look broken.
 *
 * When the library has nothing to draw it draws the reason instead, on one line in the strip where
 * the still would have been. Two controls float over that same corner — the compare checkbox at the
 * start, the «فيديو» badge at the end — so the sentence rendered UNDERNEATH the checkbox, and an
 * Arabic card opened with «…ج هذه المنصة أصل المحتوى»: the first words hidden, which a reader takes
 * for a rendering fault rather than for the honest fact it is.
 *
 * Geometry is the only thing that catches it. The classes are right in isolation and the text is
 * present in the DOM; what is wrong is where two boxes land relative to each other on a phone.
 */
test.use({ storageState: AUTH.advertiser })

test('the «why there is no preview» line is not hidden under the card’s own controls', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/app/content')
  await expect(page.locator('main')).toBeVisible({ timeout: 20000 })

  /*
   * FLAKE-ABSENCE-NOTE-001 — decide «is there a note to place?» only once the grid has been drawn.
   *
   * `main` is visible long before the library's cards are: the skip below used to read `count()` the moment the
   * shell appeared, so on most runs the grid did not exist yet, the count was 0 and the test skipped itself
   * (it sits in the skip baseline for all three browsers because of exactly that). It only ever RAN when a
   * browser happened to draw the cards first — Firefox, in the run that turned this into a red gate — so the
   * assertion below was effectively never exercised. A skip that depends on a race is not a skip; this waits
   * for the cards, then for the network to go quiet, and only then asks.
   */
  await page.waitForLoadState('networkidle')
  await page.locator('article').first().waitFor({ state: 'visible', timeout: 30_000 }).catch(() => undefined)

  const note = page.getByTestId('creative-absence-note').first()

  if ((await note.count()) === 0) {
    test.skip(true, 'no creative in this dataset is missing its file — nothing to place')
  }

  await expect(note).toBeVisible({ timeout: 20000 })

  const card = note.locator('xpath=ancestor::article[1]')
  /*
   * The TEXT's own box, not the element's.
   *
   * The note is a block with `ps-12 pe-14` — padding that exists precisely to keep the words clear of the two
   * floating controls — so its border box spans the card and always contains the checkbox. Measuring that box
   * made the assertion fail whenever it actually ran, and for the very property the padding guarantees. What a
   * reader loses is words, so the words' line boxes are what must not meet the checkbox.
   */
  const lines = await note.evaluate((el) => {
    const range = document.createRange()
    range.selectNodeContents(el)
    return Array.from(range.getClientRects()).map((r) => ({ x: r.x, y: r.y, width: r.width, height: r.height }))
  })
  const checkbox = await card.locator('input[type="checkbox"]').first().boundingBox()
  expect(lines.length, 'the note has no text box').toBeGreaterThan(0)
  expect(checkbox, 'the compare control has no box').not.toBeNull()

  const overlaps = lines.some(
    (box) =>
      box.width > 0 &&
      box.x < checkbox!.x + checkbox!.width &&
      box.x + box.width > checkbox!.x &&
      box.y < checkbox!.y + checkbox!.height &&
      box.y + box.height > checkbox!.y,
  )

  expect(overlaps, 'the reason is rendered under the compare checkbox').toBe(false)
})
