/**
 * BRAND-MARK-001 — the raster icons, generated from the one mark rather than drawn by hand.
 *
 * A favicon can be an SVG; an iOS home-screen icon cannot. `apple-touch-icon` pointed at
 * `favicon.svg`, which iOS does not support, so that icon silently failed on every iPhone. The PWA
 * manifest additionally declared the same SVG as `maskable`, which has no safe zone — Android may
 * crop a maskable icon to a circle and would have cut the mark.
 *
 * So these are produced from the SAME geometry as `CampaignsHubMark.tsx`, by a script that is run
 * again whenever the mark changes, instead of by exporting images from a design tool and hoping
 * they stay in step.
 *
 *   node scripts/build-brand-icons.mjs
 */
import { chromium } from 'playwright-core'
import { mkdir, writeFile } from 'node:fs/promises'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const HERE = dirname(fileURLToPath(import.meta.url))
const OUT = resolve(HERE, '../public')

const GREEN = '#0d8a6f'
const GOLD = '#e8a33d'
const DEEP = '#062d29' // the identity's dark container, for the maskable and apple icons

const mark = (stroke) => `
  <g fill="none" stroke="${stroke}" stroke-width="6" stroke-linecap="round">
    <path d="M8 14 H24 C34 14 34 32 42 32"/>
    <path d="M8 32 H42"/>
    <path d="M8 50 H24 C34 50 34 32 42 32"/>
  </g>
  <circle cx="52" cy="32" r="7" fill="${GOLD}"/>`

/**
 * `inset` is the maskable safe zone: the spec lets a launcher crop to a circle inscribed in the
 * icon, so the mark is drawn at 60% and centred rather than filling the square.
 */
const page = ({ size, background, stroke, inset }) => {
  const box = Math.round(size * inset)
  const pad = Math.round((size - box) / 2)
  return `<!doctype html><meta charset="utf-8">
  <style>html,body{margin:0;padding:0}body{width:${size}px;height:${size}px;background:${background};
  display:flex;align-items:center;justify-content:center}</style>
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="${box}" height="${box}"
       style="margin:${pad}px">${mark(stroke)}</svg>`
}

const TARGETS = [
  // iOS home screen: a dark tile, because iOS puts the icon on wallpaper and a transparent or white
  // tile disappears against half of them.
  { file: 'apple-touch-icon.png', size: 180, background: DEEP, stroke: '#f7f5f0', inset: 0.62 },
  { file: 'icon-192.png', size: 192, background: 'transparent', stroke: GREEN, inset: 0.86 },
  { file: 'icon-512.png', size: 512, background: 'transparent', stroke: GREEN, inset: 0.86 },
  { file: 'icon-maskable-512.png', size: 512, background: DEEP, stroke: '#f7f5f0', inset: 0.58 },
]

/**
 * The share card the identity file lists at 1200×630.
 *
 * A social crawler cannot render a component, so this one surface genuinely needs a raster of the
 * lockup — which is why it is GENERATED from the same geometry and the same words rather than
 * exported by hand. Latin wordmark on purpose: the headless renderer has no guaranteed Arabic face,
 * and a share card with broken shaping is worse than one in the product's Latin name.
 */
const shareCard = () => `<!doctype html><meta charset="utf-8">
  <style>
    html,body{margin:0;padding:0}
    body{width:1200px;height:630px;background:${DEEP};display:flex;flex-direction:column;
         align-items:center;justify-content:center;gap:28px;
         font-family:"Helvetica Neue",Helvetica,Arial,sans-serif}
    .row{display:flex;align-items:center;gap:26px}
    .name{font-size:76px;font-weight:800;letter-spacing:-1.5px;color:#f7f5f0}
    .name em{font-style:normal;color:#2bb894}
    .line{font-size:22px;letter-spacing:11px;color:#9fb3ad;text-transform:uppercase}
  </style>
  <div class="row">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="132" height="132">${mark('#f7f5f0')}</svg>
    <div class="name">Campaigns<em>Hub</em></div>
  </div>
  <div class="line">Paid Media In One Place</div>`

const browser = await chromium.launch({ args: ['--no-sandbox'] })
await mkdir(OUT, { recursive: true })

for (const t of TARGETS) {
  const p = await browser.newPage({ viewport: { width: t.size, height: t.size }, deviceScaleFactor: 1 })
  await p.setContent(page(t))
  const png = await p.screenshot({ omitBackground: t.background === 'transparent' })
  await writeFile(resolve(OUT, t.file), png)
  await p.close()
  process.stdout.write(`${t.file} ${t.size}x${t.size}\n`)
}

{
  const p = await browser.newPage({ viewport: { width: 1200, height: 630 }, deviceScaleFactor: 1 })
  await p.setContent(shareCard())
  await writeFile(resolve(OUT, 'og-card.png'), await p.screenshot())
  await p.close()
  process.stdout.write('og-card.png 1200x630\n')
}

await browser.close()
