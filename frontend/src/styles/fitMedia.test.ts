import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { FIT_LOCK_QUERY } from './scale'

/**
 * NOV-FIT-001 — the lock the stylesheet applies and the one the row-count hook measures for are one query.
 *
 * If `index.css` locked a page at 1280px and `useFitRows` believed it was locked from 1366px, a window between
 * the two would have a locked page sized for a flowing one: cut off, with nothing to say so.
 */
describe('the fit lock has one definition', () => {
  it('index.css uses the same media query as FIT_LOCK_QUERY', () => {
    // `css: false` in this suite blanks `?raw` stylesheets, so the file is read directly.
    const css = readFileSync(join(__dirname, '..', 'index.css'), 'utf8')
    expect(css).toContain(`@media ${FIT_LOCK_QUERY}`)
  })

  it('locks the root, lets each level shrink, and never hides overflow outside the lock', () => {
    const css = readFileSync(join(__dirname, '..', 'index.css'), 'utf8')
    const block = css.slice(css.indexOf(`@media ${FIT_LOCK_QUERY}`))
    expect(block).toMatch(/\[data-fit='true'\]\s*\{[^}]*height: 100dvh/)
    expect(block).toMatch(/\.portal-main[^}]*min-height: 0/)
    // The only `overflow: hidden` on the portal chain sits inside the lock's own media block.
    const outside = css.slice(0, css.indexOf(`@media ${FIT_LOCK_QUERY}`)).replace(/\/\*[\s\S]*?\*\//g, '')
    expect(outside).not.toMatch(/data-fit/)
  })
})
