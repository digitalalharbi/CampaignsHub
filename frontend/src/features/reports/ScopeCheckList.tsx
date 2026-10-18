import { useRef, useState } from 'react'

export interface CheckItem {
  id: string
  label: string
  /** A second, quieter line — the platform, or the campaign an ad runs in. */
  sub?: string
  group?: string
}

/**
 * REPORT-SCOPE-HIERARCHY-001 — a list of VISIBLE checkboxes, not a dropdown.
 *
 * The ad account, campaign and ad rungs used to be a closed multi-select: a button that, once opened,
 * listed names. An operator choosing «these two accounts, not the other three» could not see what was
 * ticked without opening it, and the Owner rejected exactly that. Every row here is a labelled
 * checkbox on the page, with a count, search, select-all and clear.
 *
 * «Nothing ticked» is stated, in words, as what it means for THIS level (`emptyMeans`) — never left
 * for the reader to guess.
 */
export function ScopeCheckList({ label, kind, items, selected, onChange, ar, emptyMeans, note, truncated, limit, required }: {
  label: string
  kind: string
  items: CheckItem[]
  selected: string[]
  onChange: (next: string[]) => void
  ar: boolean
  /** What an empty selection means at this level, e.g. «All campaigns of the selected accounts». */
  emptyMeans?: string
  note?: string
  truncated?: boolean
  /** The server's cap, so the notice can say how many are shown. */
  limit?: number
  required?: boolean
}) {
  const [query, setQuery] = useState('')
  const needle = query.trim().toLocaleLowerCase()
  const shown = items.filter((i) => needle === '' || `${i.label} ${i.sub ?? ''}`.toLocaleLowerCase().includes(needle))
  const chosen = new Set(selected)
  /*
   * The LATEST selection, updated the moment it changes — not the one this render saw.
   *
   * Two ticks that land before React has re-rendered both read the same `selected`, and the second
   * overwrites the first: the operator (or a fast test) ticks four accounts and three are lost, with
   * nothing to say so. Composing each change on the last one makes consecutive ticks add up.
   */
  const latest = useRef(selected)
  latest.current = selected
  const commit = (next: string[]) => {
    latest.current = next
    onChange(next)
  }
  const toggle = (id: string) =>
    commit(latest.current.includes(id) ? latest.current.filter((x) => x !== id) : [...latest.current, id])

  // Group headings only where the caller supplied them; rows keep the caller's order.
  const groups: Array<[string | undefined, CheckItem[]]> = []
  for (const i of shown) {
    const last = groups.at(-1)
    if (last && last[0] === i.group) last[1].push(i)
    else groups.push([i.group, [i]])
  }

  return (
    <div className="grid gap-2" data-testid={`scope-select-${label}`} data-kind={kind}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="text-xs font-bold text-text-primary">{label}</span>
        <span className="flex items-center gap-2 text-[11px] text-text-muted">
          <span className="tnum" data-testid={`scope-count-${kind}`}>
            {selected.length === 0 && emptyMeans
              ? emptyMeans
              : ar ? `${selected.length} من ${items.length} محدد` : `${selected.length} of ${items.length} selected`}
          </span>
          <button type="button" data-testid={`scope-all-${kind}`} onClick={() => commit([...new Set([...latest.current, ...shown.map((i) => i.id)])])} className="font-bold text-brand-700 hover:underline">
            {ar ? 'تحديد الكل' : 'Select all'}
          </button>
          <button type="button" data-testid={`scope-clear-${kind}`} onClick={() => commit([])} disabled={selected.length === 0} className="font-bold text-brand-700 hover:underline disabled:opacity-40">
            {ar ? 'مسح' : 'Clear'}
          </button>
        </span>
      </div>

      {note && <p className="text-[10px] text-text-secondary">{note}</p>}
      {truncated === true && (
        <p className="text-[10px] font-semibold text-warning" data-testid={`scope-truncated-${label}`}>
          {ar
            ? `يُعرض ${limit ?? items.length} فقط — ضيّق الاختيار بالأعلى أو ابحث بالاسم للوصول إلى البقية`
            : `Showing ${limit ?? items.length} only — narrow the choice above or search by name to reach the rest`}
        </p>
      )}

      {items.length > 6 && (
        <input
          type="search"
          aria-label={ar ? `بحث في ${label}` : `Search ${label}`}
          data-testid={`scope-search-${kind}`}
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder={ar ? 'ابحث بالاسم' : 'Search by name'}
          className="min-w-0 rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-text-primary"
        />
      )}

      <div className="grid max-h-44 gap-1 overflow-y-auto rounded-lg border border-border p-2" role="group" aria-label={label}>
        {groups.map(([group, rows]) => (
          <div key={group ?? '__'} className="grid gap-1">
            {group && <p className="pt-1 text-[11px] font-bold text-text-muted">{group}</p>}
            {rows.map((i) => (
              <label key={i.id} className="flex items-center gap-2 text-xs">
                <input
                  type="checkbox"
                  checked={chosen.has(i.id)}
                  onChange={() => toggle(i.id)}
                  data-testid={`scope-${kind}-${i.id}`}
                  className="h-4 w-4 shrink-0 accent-brand-600"
                />
                <span className="min-w-0 flex-1 truncate">
                  {i.label}
                  {i.sub && <span className="text-text-muted">{' · '}{i.sub}</span>}
                </span>
              </label>
            ))}
          </div>
        ))}
        {shown.length === 0 && <p className="text-xs text-text-muted">{ar ? 'لا شيء يطابق البحث.' : 'Nothing matches the search.'}</p>}
      </div>

      {required && selected.length === 0 && items.length > 0 && (
        <p className="text-[11px] text-danger" data-testid={`scope-${kind}-required`}>
          {ar ? 'اختر حسابًا إعلانيًا واحدًا على الأقل — لا يُشمل أي حساب تلقائيًا.' : 'Choose at least one ad account — none is included by default.'}
        </p>
      )}
    </div>
  )
}
