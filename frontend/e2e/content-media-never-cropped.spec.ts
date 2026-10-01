import { expect, test } from '@playwright/test'
import { AUTH, seededProject, selectProject } from './helpers'

/**
 * CONTENT-PREVIEW-FIT-001 — «part of the actual advertisement is lost».
 *
 * ## What this measures, and why it is not a screenshot
 *
 * A crop is arithmetic, not an impression. An element drawn `object-contain` renders at its own
 * ratio — `clientWidth / clientHeight` equals `naturalWidth / naturalHeight` — and one drawn
 * `cover` into a box of a different shape does not. So «nothing is cropped» is a sum this spec can
 * do on every picture on the page, at every width, in every browser, without a reference image to
 * maintain.
 *
 * The tolerance is 2%: a contained element is laid out in CSS pixels and a 1080×1920 asset in a
 * 383×681 box will not divide exactly.
 *
 * ## The two surfaces, because the owner named both
 *
 * «One creative + same period + same scope must tell the same factual story across card, popup,
 * detail, analytics, report, shared report.» The grid is where a reader scans and the viewer is
 * where they judge, and the viewer is the one that may never lose anything at all.
 */
test.use({ storageState: AUTH.owner })

/** How far a rendered box may differ from the asset's own ratio before it is a crop. */
const TOLERANCE = 0.02

type Measured = { testid: string; rendered: number; natural: number; fit: string }

async function pictures(page: import('@playwright/test').Page, within: string): Promise<Measured[]> {
  return page.evaluate((selector) => {
    const out: Array<{ testid: string; rendered: number; natural: number; fit: string }> = []

    document.querySelectorAll(`${selector} img`).forEach((node) => {
      const img = node as HTMLImageElement

      /* Not yet decoded, or a 1px tracking pixel: neither is a crop this spec can speak about. */
      if (img.naturalWidth < 8 || img.naturalHeight < 8) return
      if (img.clientWidth < 8 || img.clientHeight < 8) return

      out.push({
        testid: img.closest('[data-testid]')?.getAttribute('data-testid') ?? img.src.slice(-40),
        rendered: img.clientWidth / img.clientHeight,
        natural: img.naturalWidth / img.naturalHeight,
        fit: getComputedStyle(img).objectFit,
      })
    })

    return out
  }, within)
}

function cropped(measured: Measured[]): string[] {
  return measured
    .filter((m) => m.fit !== 'contain' && Math.abs(m.rendered - m.natural) / m.natural > TOLERANCE)
    .map((m) => `${m.testid}: drawn ${m.rendered.toFixed(3)} against its own ${m.natural.toFixed(3)} (${m.fit})`)
}

for (const viewport of [
  { name: 'desktop', width: 1440, height: 900 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'phone', width: 390, height: 844 },
]) {
  test(`no creative is cropped in the library grid — ${viewport.name}`, async ({ page, request }) => {
    await page.setViewportSize({ width: viewport.width, height: viewport.height })
    await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
    await page.goto('/agency/content')

    await expect(page.locator('article').first()).toBeVisible({ timeout: 30_000 })
    await page.waitForLoadState('networkidle')

    const measured = await pictures(page, 'article')

    expect(measured.length, 'the library drew no pictures to measure').toBeGreaterThan(0)
    expect(
      cropped(measured),
      `these creatives are drawn at a ratio that is not their own:\n  ${cropped(measured).join('\n  ')}`,
    ).toEqual([])
  })

  test(`the viewer shows the whole creative — ${viewport.name}`, async ({ page, request }) => {
    await page.setViewportSize({ width: viewport.width, height: viewport.height })
    await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
    await page.goto('/agency/content')

    await expect(page.getByTestId('creative-card-open').first()).toBeVisible({ timeout: 30_000 })
    await page.getByTestId('creative-card-open').first().click()

    const dialog = page.getByTestId('ad-preview-dialog')
    await expect(dialog).toBeVisible({ timeout: 20_000 })
    await page.waitForTimeout(1200)

    /* Every picture in the viewer, contained or at its own ratio — there is no third option here. */
    const measured = await pictures(page, '[data-testid="ad-preview-dialog"]')
    expect(
      cropped(measured),
      `the viewer cropped a creative:\n  ${cropped(measured).join('\n  ')}`,
    ).toEqual([])

    /* The stage bounds the asset, so nothing may spill out of it. */
    const spill = await page.evaluate(() => {
      const stage = document.querySelector('[data-testid="ad-preview-dialog-stage"]')
      if (!stage) return null

      const box = stage.getBoundingClientRect()
      const media = stage.querySelector('img, video')
      if (!media) return null

      const m = media.getBoundingClientRect()

      return {
        over: Math.max(0, Math.round(m.width - box.width)),
        under: Math.max(0, Math.round(m.height - box.height)),
      }
    })

    if (spill !== null) {
      expect(spill.over, 'the media is wider than its stage').toBeLessThanOrEqual(1)
      expect(spill.under, 'the media is taller than its stage').toBeLessThanOrEqual(1)
    }

    /* Owner regression 7 — and a phone viewer never scrolls sideways. */
    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    )
    expect(overflow, `the viewer scrolls sideways by ${overflow}px`).toBeLessThanOrEqual(1)

    /* The way out works, wherever the header ended up. */
    await page.getByTestId('ad-preview-dialog-close').click()
    await expect(dialog).toHaveCount(0)
  })
}

/**
 * Owner regression 10 — a film the browser cannot cover for never stays an unexplained rectangle.
 *
 * WebKit is the browser this is about: it accepts a `currentTime` and defers the decode until
 * something plays, so a poster drawn from a first frame can sit unpainted forever. The requirement
 * is not that every browser manages a poster — it is that the reader is never left looking at
 * nothing with no account of it.
 */
test('every card resolves to a picture, a film or a sentence — never an empty box', async ({ page, request }) => {
  await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
  await page.goto('/agency/content')

  await expect(page.locator('article').first()).toBeVisible({ timeout: 30_000 })
  await page.waitForLoadState('networkidle')
  /* Long enough for `VideoPoster` to spend its own budget and give up out loud. */
  await page.waitForTimeout(9_000)

  const blank = await page.evaluate(() =>
    [...document.querySelectorAll('article')]
      .filter((card) => {
        const drew = card.querySelector('img, video')
        const said = card.querySelector('[data-absence]')

        if (said !== null && (said.textContent ?? '').trim() !== '') return false
        if (drew === null) return true

        const box = drew.getBoundingClientRect()

        return box.width < 8 || box.height < 8
      })
      .map((card) => card.getAttribute('data-testid') ?? 'unnamed card'),
  )

  expect(blank, `these cards are an empty box with no account of it:\n  ${blank.join('\n  ')}`).toEqual([])
})
