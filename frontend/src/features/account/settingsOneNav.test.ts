import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

/**
 * SETTINGS-ONE-NAV-001 — two lists that must stay the same list.
 *
 * `/settings/workspace` is one page holding several sections behind a `?tab=`, and the SHELL lists
 * them now instead of the page drawing a second navigation beside it. That only works while the two
 * agree: a section added to the route's `only=` and not to the shell is a screen with no way in,
 * and a section listed in the shell but not served by the route opens the first tab instead —
 * either way the reader is the one who finds out.
 *
 * Read from source rather than imported, because the route list lives inside a JSX element and the
 * shell's inside a module-level literal; the point is that the two spellings match.
 */
describe('the workspace settings sections', () => {
  const read = (p: string) => readFileSync(new URL(p, import.meta.url), 'utf8')

  it('are the same in the route and in the shell', () => {
    const route = read('../../app/router.tsx').match(
      /<SettingsPage only=\{\[([^\]]*)\]\} navigation=\{false\}/,
    )
    const shell = read('./SettingsLayout.tsx').match(/const WORKSPACE_SECTIONS = \[([^\]]*)\]/)

    expect(route, 'the workspace settings route no longer spells its sections this way').not.toBeNull()
    expect(shell, 'the settings shell no longer spells its sections this way').not.toBeNull()

    const ids = (s: string) => [...s.matchAll(/'([a-z-]+)'/g)].map((m) => m[1])

    expect(ids(shell![1]!)).toEqual(ids(route![1]!))
  })

  /**
   * A route serving SEVERAL sections must say `navigation={false}`.
   *
   * One section has never drawn an inner nav — that is what `/settings/permissions` relies on — so
   * the rule is about the multi-section routes, which are the ones that drew the second list.
   */
  it('leaves the page drawing no navigation of its own there', () => {
    const offenders = [...read('../../app/router.tsx').matchAll(/<SettingsPage only=\{\[([^\]]*)\]\}([^>]*)\/>/g)]
      .filter(([, only, rest]) => (only!.match(/'/g) ?? []).length > 2 && !rest!.includes('navigation={false}'))
      .map(([match]) => match.slice(0, 80))

    expect(
      offenders,
      'a multi-section settings route stopped passing navigation={false}, so it draws a second nav',
    ).toEqual([])
  })
})
