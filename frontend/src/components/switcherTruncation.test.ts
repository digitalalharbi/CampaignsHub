import { describe, expect, it } from 'vitest'

/**
 * A workspace name longer than the rail is SHORTENED, not cut.
 *
 * Both switchers are native selects in an RTL shell. «Growth — Acquisition» is Latin text, so its
 * overflow fell at the START edge: the Arabic product rendered «rowth — Acquisition» on every page —
 * a name the reader cannot verify, with nothing on screen admitting a letter is missing.
 *
 * Two settings fix it and both are asserted, because either alone leaves the defect. The ellipsis
 * says there is more of the name; `dir="auto"` puts that ellipsis at the name's own end rather than
 * at its beginning, which is what makes «Growth — Acquisi…» still name the project.
 *
 * A source check rather than a render: the switchers need a populated query to show a value at all,
 * and a test that silently skips when the query is empty is a test that stops guarding.
 */
const TREE: Record<string, string> = import.meta.glob('/src/**/*.tsx', {
  query: '?raw',
  import: 'default',
  eager: true,
})

/**
 * The markup, with the prose removed.
 *
 * Both files EXPLAIN `dir="auto"` in a comment above the control they set it on, so a guard reading
 * the raw source passes on the explanation alone — it went green with the attribute deleted. Every
 * check here runs against code only.
 */
const read = (path: string): string => {
  const source = TREE['/' + path]
  if (source === undefined) throw new Error(`${path} is not in the source tree — this guard reads a file that moved`)
  return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '')
}

const SWITCHERS = ['src/components/ProjectSwitcher.tsx', 'src/features/agency/AgencyScopeSwitcher.tsx']

describe('the workspace switchers truncate rather than clip', () => {
  for (const path of SWITCHERS) {
    it(`${path} lays the name out in its own direction`, () => {
      expect(read(path)).toContain('dir="auto"')
    })

    it(`${path} ends a name it cannot fit with an ellipsis`, () => {
      const source = read(path)
      expect(source).toContain('text-ellipsis')
      expect(source).toContain('overflow-hidden')
    })
  }
})
