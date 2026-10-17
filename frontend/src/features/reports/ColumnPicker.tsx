/**
 * REPORT-TABLES-OWNER-COLUMNS-001 — the Owner picks the columns a table publishes.
 *
 * `value` is null while nothing is restricted (every column), which is also what is sent: a link the
 * Owner never touched here must stay exactly what it was. The platform name is not offered — a row
 * without its name is not a row — and a hidden-spend link ignores the money columns on the page, so
 * ticking them here can never publish what the link withholds.
 */
export const PLATFORM_COLUMNS: Array<{ key: string; ar: string; en: string }> = [
  { key: 'spend', ar: 'الإنفاق', en: 'Spend' },
  { key: 'results', ar: 'النتائج', en: 'Results' },
  { key: 'cost', ar: 'تكلفة النتيجة', en: 'Cost per result' },
  { key: 'impressions', ar: 'الظهور', en: 'Impressions' },
  { key: 'clicks', ar: 'النقرات', en: 'Clicks' },
  { key: 'ctr', ar: 'نسبة النقر', en: 'CTR' },
]

export function ColumnPicker({
  value,
  onChange,
  ar,
}: {
  value: string[] | null
  onChange: (next: string[] | null) => void
  ar: boolean
}) {
  const shown = value ?? PLATFORM_COLUMNS.map((c) => c.key)

  const toggle = (key: string) => {
    const next = shown.includes(key) ? shown.filter((k) => k !== key) : [...shown, key]
    // Everything ticked is «no restriction» again, so the stored link is unchanged from the default.
    onChange(next.length === PLATFORM_COLUMNS.length ? null : next)
  }

  return (
    <div className="rounded-xl border border-border p-3" data-testid="column-picker">
      <p className="mb-2 text-xs font-bold text-text-muted">{ar ? 'أعمدة جدول المنصات' : 'Platform table columns'}</p>
      <div className="flex flex-wrap gap-1.5">
        {PLATFORM_COLUMNS.map((c) => {
          const on = shown.includes(c.key)
          return (
            <button
              key={c.key}
              type="button"
              aria-pressed={on}
              data-testid={`column-${c.key}`}
              onClick={() => toggle(c.key)}
              className={`rounded-lg border px-2.5 py-1.5 text-xs font-semibold transition-colors ${
                on ? 'border-brand-500 bg-[var(--brand-background)] text-brand-700' : 'border-border text-text-secondary hover:bg-surface-hover'
              }`}
            >
              {ar ? c.ar : c.en}
            </button>
          )
        })}
      </div>
    </div>
  )
}
