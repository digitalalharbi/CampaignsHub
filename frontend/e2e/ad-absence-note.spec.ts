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

  const note = page.getByTestId('creative-absence-note').first()

  if ((await note.count()) === 0) {
    test.skip(true, 'no creative in this dataset is missing its file — nothing to place')
  }

  await expect(note).toBeVisible({ timeout: 20000 })

  const card = note.locator('xpath=ancestor::article[1]')
  const box = await note.boundingBox()
  const checkbox = await card.locator('input[type="checkbox"]').first().boundingBox()
  expect(box, 'the note has no box').not.toBeNull()
  expect(checkbox, 'the compare control has no box').not.toBeNull()

  const overlaps =
    box!.x < checkbox!.x + checkbox!.width &&
    box!.x + box!.width > checkbox!.x &&
    box!.y < checkbox!.y + checkbox!.height &&
    box!.y + box!.height > checkbox!.y

  expect(overlaps, 'the reason is rendered under the compare checkbox').toBe(false)
})
