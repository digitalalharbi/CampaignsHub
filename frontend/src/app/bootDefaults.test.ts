import { describe, expect, it } from 'vitest'

/**
 * MKT-FIX-001 — the document's starting state is the app's resolved state.
 *
 * ## The defect
 *
 * `index.html` declared `lang="en"` with no `dir` and no `data-theme`, while the store resolves to
 * Arabic, RTL and dark when nothing is remembered. Every FIRST visit therefore painted
 * left-to-right and light, and flipped the instant `applyDocument()` ran: the header swapped sides,
 * the hero re-laid out and the document changed colour. On a phone that reads as «the page moved and
 * then settled».
 *
 * A warm cache hides it — the gap between the HTML painting and the bundle executing is milliseconds
 * on a second visit, which is why it survived every casual check and why the owner saw it on a real
 * phone.
 *
 * ## Why this test reads two files
 *
 * The fix puts the defaults in a pre-paint inline script, and that is a SECOND place that decides
 * what «dark» and «ar» mean. Two places deciding one thing is how a flash comes back without anybody
 * touching the thing that flashes. So the source of truth stays `stores/ui.ts` and this fails the
 * moment the copy in the document stops agreeing with it.
 *
 * It reads the source text rather than importing, because the inline script is not a module and
 * `index.html` is not code this bundle can execute.
 */
/*
 * Read through Vite's own raw glob, not `node:fs`.
 *
 * These specs are type-checked by `tsc -b` alongside the app, where a node builtin has no business
 * being — the brand guard next door reads the source tree the same way for the same reason.
 */
const RAW = import.meta.glob('/{index.html,src/stores/ui.ts}', { query: '?raw', import: 'default', eager: true }) as Record<string, string>

const read = (path: string): string => {
  const source = RAW[path]

  if (source === undefined) {
    throw new Error(`${path} is not in the glob — this test has gone stale: ${Object.keys(RAW).join(', ')}`)
  }

  return source
}

const html = '/index.html'
const store = '/src/stores/ui.ts'

describe('the document boots in its final geometry', () => {
  it('declares the product’s own language, direction and theme statically', () => {
    const source = read(html)

    expect(source, 'the document must start in the language the product speaks').toMatch(/<html[^>]*\blang="ar"/)
    expect(source, 'an RTL product that starts LTR flips on first paint').toMatch(/<html[^>]*\bdir="rtl"/)
    expect(source, 'a dark-default product that starts unthemed flashes light').toMatch(/<html[^>]*\bdata-theme="dark"/)
  })

  /**
   * The returning reader is the other half. Starting every visit on the defaults and correcting
   * afterwards is the same flip, pointed the other way.
   */
  it('applies the remembered preference before the stylesheet paints', () => {
    const source = read(html)
    const head = source.slice(0, source.indexOf('</head>'))

    expect(head).toContain("localStorage.getItem('campaign-hub-theme')")
    expect(head).toContain("localStorage.getItem('campaign-hub-locale')")
    expect(head, 'the pre-paint script must set all three, or one of them still flips').toMatch(/setAttribute\('dir'/)

    /*
     * Blocking, and before anything that paints. A `defer` or `type="module"` script runs after the
     * document is parsed, which is after the first paint it exists to get right.
     */
    const tag = head.slice(head.indexOf('<script'), head.indexOf('</script>'))
    expect(tag).not.toContain('type="module"')
    expect(tag).not.toContain('defer')
    expect(tag).not.toContain('async')
  })

  it('agrees with the store about what the defaults are', () => {
    const source = read(html)
    const ui = read(store)

    /* The store's own declarations, read rather than assumed — these are the source of truth. */
    const theme = /remembered<Theme>\([^)]*?,\s*'(light|dark)'\s*\)/.exec(ui)?.[1]
    const locale = /remembered<Locale>\([^)]*?,\s*'(ar|en)'\s*\)/.exec(ui)?.[1]

    expect(theme, 'the store’s default theme could not be read — this test has gone stale').toBeDefined()
    expect(locale, 'the store’s default locale could not be read — this test has gone stale').toBeDefined()

    expect(source, `the document must default to the store’s theme (${theme})`).toContain(`data-theme="${theme}"`)
    expect(source, `the document must default to the store’s locale (${locale})`).toContain(`lang="${locale}"`)
    expect(source).toContain(`if (theme !== 'light' && theme !== 'dark') theme = '${theme}'`)
    expect(source).toContain(`if (locale !== 'ar' && locale !== 'en') locale = '${locale}'`)
  })
})
