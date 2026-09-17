import { expect, test } from '@playwright/test'
import { AUTH, seededProject, selectProject } from './helpers'

/**
 * OWNER CONTENT P0 — the card and the panel it opens answer the same question the same way.
 *
 * The owner's production reading, on one creative in one period: the card showed Spend beside three
 * figures while the popup one click away showed impressions, clicks, CTR, CPC and CPM as well; and a
 * card drew a picture whose creative, opened, said it had no cover.
 *
 * ## What this asserts, and what it deliberately does not
 *
 * Not a count of figures, and not a named metric: both would be assertions about the SEED, failing
 * the day somebody adds a creative rather than the day something breaks. It asserts the two
 * PROPERTIES the defect violated, for the first card the library draws:
 *
 *   1. every figure the popup can state is also stated on the card — folded behind «+N» is fine,
 *      because folded is a layout decision and lost is not;
 *   2. the still the card draws and the still the popup draws are the same file.
 *
 * A dash is «we cannot say» and is excluded on both sides: the panel states its universal tiles even
 * when the platform sent nothing for them, which is right for a surface opened to study one creative,
 * and it is not a figure either surface is claiming to hold.
 */
test.use({ storageState: AUTH.owner })

/** What a surface actually drew: a named still, a film, or nothing at all. */
const MEDIA = (root: Element): string => {
  const img = root.querySelector('img')

  if (img) return `image:${img.getAttribute('src') ?? ''}`

  return root.querySelector('video') ? 'video' : 'none'
}

/** The metric KEYS a container states a value for — a dash is «we cannot say», not a figure. */
const STATED = (root: Element): string[] => {
  const out: string[] = []

  root.querySelectorAll('[data-metric]').forEach((cell) => {
    const value = (cell.lastElementChild?.textContent ?? '').trim()

    if (value !== '' && value !== '—') out.push(cell.getAttribute('data-metric') ?? '')
  })

  return out.sort()
}

test('a content card states every figure its own popup states, and the same still', async ({ page, request }) => {
  await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
  await page.goto('/agency/content')

  const card = page.locator('article').first()
  await expect(card).toBeVisible({ timeout: 30000 })
  await page.waitForLoadState('networkidle')

  const metrics = card.getByTestId('creative-card-metrics')

  if (await metrics.count() === 0) {
    test.skip(true, 'the first card has no figures of its own — nothing to compare')
  }

  // Folded is not lost: the card reveals the rest in place before the two sets are compared.
  const more = card.getByTestId('creative-card-more-metrics')
  if (await more.count() > 0) await more.click()

  const cardFigures = await metrics.evaluate(STATED)
  const cardMedia = await card.evaluate(MEDIA)

  // By testid, not by label: this gate runs the product in Arabic as well as in English.
  await card.getByTestId('creative-card-open').click()

  const dialog = page.getByTestId('ad-preview-dialog')
  await expect(dialog).toBeVisible()

  const popupFigures = await dialog.getByTestId('ad-preview-dialog-figures').evaluate(STATED)
  const popupMedia = await dialog.evaluate(MEDIA)

  for (const figure of popupFigures) {
    expect(
      cardFigures,
      `the popup states «${figure}» and the card does not — a figure the platform reported, lost between two surfaces one click apart`,
    ).toContain(figure)
  }

  /*
   * A still is compared by its FILE; a film is compared as «there is a film here».
   *
   * The card draws a poster and the panel mounts a player, so the two hold different elements for
   * the same video by design. What must never differ is whether there is an ad to look at: the
   * owner's reading was a card with a picture and a creative saying it had no cover.
   */
  if (cardMedia.startsWith('image:') && popupMedia.startsWith('image:')) {
    expect(
      cardMedia,
      'the card and the popup drew different stills for one creative — one of them is not the ad',
    ).toBe(popupMedia)
  }

  expect(
    cardMedia === 'none',
    `the card and the popup disagree about whether this creative has media — card: ${cardMedia}, popup: ${popupMedia}`,
  ).toBe(popupMedia === 'none')
})
