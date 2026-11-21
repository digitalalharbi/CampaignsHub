import { platformRank } from './platforms'

/**
 * One ordering for every list of sources — project → platform → organisation → account (Owner 2026-11-06).
 *
 * The centre, a project's page, the analytics account breakdown and a report's scope picker each listed
 * the same accounts in whatever order their endpoint happened to return, so one account sat third on one
 * screen and first on the next. This is the single comparison they all use: a reader who learned where an
 * account is on one surface finds it in the same place on the others.
 *
 * A level a surface does not have (a project's own page has no project level) is simply left out of the
 * key; an absent name sorts after every present one rather than first, so «no organisation» never leads.
 */
export interface SourceKey {
  project?: string | null
  platform?: string | null
  organisation?: string | null
  account?: string | null
}

function byName(a: string | null | undefined, b: string | null | undefined, locale: string): number {
  const left = (a ?? '').trim()
  const right = (b ?? '').trim()
  if (left === '' || right === '') return left === right ? 0 : left === '' ? 1 : -1

  return left.localeCompare(right, locale, { numeric: true, sensitivity: 'base' })
}

export function compareSources(a: SourceKey, b: SourceKey, ar: boolean): number {
  const locale = ar ? 'ar' : 'en'

  return byName(a.project, b.project, locale)
    || platformRank(a.platform) - platformRank(b.platform)
    || byName(a.organisation, b.organisation, locale)
    || byName(a.account, b.account, locale)
}

/** A sorted copy; equal keys keep the order they arrived in. */
export function sortBySource<T>(rows: readonly T[], key: (row: T) => SourceKey, ar: boolean): T[] {
  return rows
    .map((row, index) => ({ row, index, key: key(row) }))
    .sort((x, y) => compareSources(x.key, y.key, ar) || x.index - y.index)
    .map((entry) => entry.row)
}
