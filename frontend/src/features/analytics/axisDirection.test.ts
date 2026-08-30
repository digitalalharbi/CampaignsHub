import { describe, expect, it } from 'vitest'

/**
 * A second Y axis follows the reader, not the chart library.
 *
 * Recharts pins a secondary axis to «right» by default. On an Arabic page the PRIMARY axis is
 * already on the right, so both scales stack down one side and the other side of the chart is bare —
 * two rulers on one edge, and a reader matching a line to a scale has to guess which.
 *
 * A source check, because the fault is a literal: the chart renders inside `ResponsiveContainer`,
 * which measures nothing in jsdom, so a render test could assert only that the element exists.
 */
const TREE: Record<string, string> = import.meta.glob('/src/**/*.tsx', {
  query: '?raw',
  import: 'default',
  eager: true,
})

const read = (path: string): string => {
  const source = TREE['/' + path]
  if (source === undefined) throw new Error(`${path} is not in the source tree — this guard reads a file that moved`)
  return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '')
}

describe('every secondary axis is placed by locale', () => {
  it('no chart pins an axis to one side regardless of direction', () => {
    const offenders = Object.keys(TREE)
      .map((path) => path.replace(/^\/+/, ''))
      .flatMap((path) =>
        read(path)
          .split('\n')
          .map((line, i) => [i + 1, line] as const)
          .filter(([, line]) => /orientation=["']right["']|orientation=["']left["']/.test(line))
          .map(([n, line]) => `${path}:${n}  ${line.trim().slice(0, 80)}`),
      )

    expect(offenders, 'an axis fixed to one side puts both scales on one edge of an RTL chart').toEqual([])
  })
})
