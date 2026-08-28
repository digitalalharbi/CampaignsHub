/**
 * HIERARCHY-ENTITY-ANALYTICS-DRILLDOWN — campaign → ad set → ad → creative, as one path.
 *
 * The backend has answered a narrowed question since the entity endpoint shipped: `parent` changes
 * the DATABASE scope, not a client-side filter. The UI never asked it — `useEntities` was called with
 * `parent` hardcoded `undefined` — so the levels existed as four separate flat lists and «which ad
 * sets belong to the campaign that is losing money» was a question the product could answer and did
 * not offer.
 *
 * ## The path lives in the URL
 *
 * A drill-down that lives in component state cannot be linked, and «look at this ad set» is exactly
 * the thing an operator pastes into a chat. It is also what makes the back button mean what a reader
 * expects — leaving a level restores the one above it rather than the whole page's default.
 *
 * ## Names are carried, not looked up again
 *
 * The row that was clicked already knows what it is called. Re-fetching a name for the breadcrumb
 * would put a second source of truth beside the first, and the two disagree exactly when the
 * structure sweep has removed an entity — the case where the reader most needs to be told plainly.
 * A missing name is shown as its id, never as «—»: an entity that no longer exists is a real state,
 * and a dash reads as «nothing here».
 */

export type DrillLevel = 'campaign' | 'ad_set' | 'ad' | 'creative'

/** The rungs the entity endpoint itself serves. `campaign` and `creative` are read elsewhere. */
export type EntityLevel = 'ad_set' | 'ad'

export interface DrillStep {
  level: DrillLevel
  id: string
  /** What the clicked row called itself. Null when the structure sweep had already removed it. */
  name: string | null
}

const ORDER: DrillLevel[] = ['campaign', 'ad_set', 'ad', 'creative']

/** The level a row at `level` drills INTO, or null at the bottom of the hierarchy. */
export function nextLevel(level: DrillLevel): DrillLevel | null {
  const i = ORDER.indexOf(level)

  return i < 0 || i === ORDER.length - 1 ? null : ORDER[i + 1]
}

/**
 * Serialise a path for the URL: `campaign:abc~ad_set:def`.
 *
 * `~` rather than `/` or `,` so the value survives a query string without escaping, and reads as one
 * token in a pasted link instead of looking like a truncated path.
 */
export function encodePath(path: DrillStep[]): string {
  return path.map((s) => `${s.level}:${s.id}`).join('~')
}

/**
 * Read a path back, dropping anything malformed rather than throwing.
 *
 * A hand-edited or truncated link is a reader's mistake, not a crash: the page falls back to the
 * deepest prefix it can trust. Names are NOT in the URL — a link would carry a name that has since
 * changed — so a decoded step is nameless until a row supplies one.
 */
export function decodePath(raw: string | null | undefined): DrillStep[] {
  if (!raw) return []

  const out: DrillStep[] = []

  for (const token of raw.split('~')) {
    const [level, ...rest] = token.split(':')
    const id = rest.join(':')

    if (!id || !ORDER.includes(level as DrillLevel)) break

    /*
     * The path must DESCEND, but it need not start at the top.
     *
     * An operator opens the ad-set tab and drills into ads without ever pinning a campaign, so
     * `ad_set:s1` is a complete and legitimate path. Requiring `campaign` first threw that away
     * silently — the tab changed, the crumb vanished and the list came back unnarrowed, which is the
     * exact «looks like it worked» failure this module exists to prevent.
     */
    if (out.length > 0 && level !== nextLevel(out[out.length - 1].level)) break

    out.push({ level: level as DrillLevel, id, name: null })
  }

  return out
}

/**
 * The id to send as `parent` when listing `level`, or null for the unnarrowed list.
 *
 * Only the step immediately above counts. A path pinned two rungs up would silently list every ad in
 * a campaign while the breadcrumb claimed one ad set.
 */
export function parentFor(level: EntityLevel, path: DrillStep[]): string | null {
  const above: DrillLevel = level === 'ad_set' ? 'campaign' : 'ad_set'
  const step = path.find((s) => s.level === above)

  return step?.id ?? null
}

/** Descend, replacing anything already at or below the new step. */
export function drillInto(path: DrillStep[], step: DrillStep): DrillStep[] {
  const i = ORDER.indexOf(step.level)

  return [...path.filter((s) => ORDER.indexOf(s.level) < i), step]
}

/** Step back OUT to a level, keeping everything above it. Passing the first level clears the path. */
export function drillUpTo(path: DrillStep[], level: DrillLevel): DrillStep[] {
  const i = ORDER.indexOf(level)

  return path.filter((s) => ORDER.indexOf(s.level) < i)
}

/**
 * Display names for ids the reader has drilled through, remembered for this session only.
 *
 * The name cannot live in the URL: a link would then carry a name that has since been renamed, and
 * the crumb would confidently caption the list with something the account no longer calls it. So the
 * id is the linkable truth and the name is a courtesy, filled in from the row that was clicked.
 *
 * A miss — a pasted link, a reload — shows the id, which is exactly what `stepLabel` promises. This
 * map NEVER affects the query; it decides one label.
 */
const NAMES = new Map<string, string>()

export function rememberName(id: string, name: string | null): void {
  if (name !== null && name !== '') NAMES.set(id, name)
}

/** Fill in any name this session has seen, leaving the rest null. */
export function withNames(path: DrillStep[]): DrillStep[] {
  return path.map((s) => (s.name !== null ? s : { ...s, name: NAMES.get(s.id) ?? null }))
}

/**
 * How the creative library should be narrowed for this path.
 *
 * The library speaks `ad_ids` / `ad_set_ids` rather than one `parent`, and it takes the DEEPEST rung
 * the reader pinned: an ad if there is one, otherwise the ad set. Sending both would narrow twice for
 * one choice, and sending the shallower one would show an ad set's whole creative population under a
 * breadcrumb naming a single ad.
 *
 * A path that stops at `campaign` narrows nothing here: the library has no campaign axis of its own
 * on this route, and inventing one by passing a campaign id into an ad filter would return an empty
 * list that reads as «this campaign has no creatives».
 */
export function creativeScope(path: DrillStep[]): { ad_ids?: string[]; ad_set_ids?: string[] } {
  const ad = path.find((s) => s.level === 'ad')

  if (ad !== undefined) {
    return { ad_ids: [ad.id] }
  }

  const adSet = path.find((s) => s.level === 'ad_set')

  return adSet !== undefined ? { ad_set_ids: [adSet.id] } : {}
}

/** What a breadcrumb prints for a step — never a dash for an entity that really existed. */
export function stepLabel(step: DrillStep): string {
  return step.name ?? step.id
}
