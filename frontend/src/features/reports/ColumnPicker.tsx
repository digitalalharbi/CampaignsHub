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

type ColumnDef = { key: string; ar: string; en: string }

/** The Tables view's other three tables — the same keys the server accepts (ReportColumns::TABLES). */
export const TABLE_COLUMNS: Record<'platforms' | 'accounts' | 'content' | 'days', { title: { ar: string; en: string }; columns: ColumnDef[] }> = {
  platforms: { title: { ar: 'أعمدة جدول المنصات', en: 'Platform table columns' }, columns: PLATFORM_COLUMNS },
  accounts: {
    title: { ar: 'أعمدة جدول الحسابات', en: 'Account table columns' },
    columns: [
      { key: 'platform', ar: 'المنصة', en: 'Platform' },
      { key: 'results', ar: 'النتائج', en: 'Results' },
      { key: 'impressions', ar: 'الظهور', en: 'Impressions' },
      { key: 'clicks', ar: 'النقرات', en: 'Clicks' },
      { key: 'spend', ar: 'الإنفاق', en: 'Spend' },
      { key: 'cost', ar: 'تكلفة النتيجة', en: 'Cost per result' },
    ],
  },
  content: {
    title: { ar: 'أعمدة جدول المحتوى', en: 'Content table columns' },
    columns: [
      { key: 'platform', ar: 'المنصة', en: 'Platform' },
      { key: 'results', ar: 'النتائج', en: 'Results' },
      { key: 'impressions', ar: 'الظهور', en: 'Impressions' },
      { key: 'clicks', ar: 'النقرات', en: 'Clicks' },
      { key: 'spend', ar: 'الإنفاق', en: 'Spend' },
      { key: 'cost', ar: 'تكلفة النتيجة', en: 'Cost per result' },
    ],
  },
  days: {
    title: { ar: 'أعمدة السجل اليومي', en: 'Daily record columns' },
    columns: [
      { key: 'impressions', ar: 'الظهور', en: 'Impressions' },
      { key: 'clicks', ar: 'النقرات', en: 'Clicks' },
      { key: 'results', ar: 'النتائج', en: 'Results' },
      { key: 'spend', ar: 'الإنفاق', en: 'Spend' },
    ],
  },
}

export function ColumnPicker({
  value,
  onChange,
  ar,
  table = 'platforms',
}: {
  value: string[] | null
  onChange: (next: string[] | null) => void
  ar: boolean
  /** Which table this picks for. The platform picker keeps its original test ids. */
  table?: keyof typeof TABLE_COLUMNS
}) {
  const def = TABLE_COLUMNS[table]
  const shown = value ?? def.columns.map((c) => c.key)
  const id = (key: string) => (table === 'platforms' ? `column-${key}` : `column-${table}-${key}`)

  const toggle = (key: string) => {
    const next = shown.includes(key) ? shown.filter((k) => k !== key) : [...shown, key]
    // Everything ticked is «no restriction» again, so the stored link is unchanged from the default.
    onChange(next.length === def.columns.length ? null : next)
  }

  return (
    <div className="rounded-xl border border-border p-3" data-testid={table === 'platforms' ? 'column-picker' : `column-picker-${table}`}>
      <p className="mb-2 text-xs font-bold text-text-muted">{ar ? def.title.ar : def.title.en}</p>
      <div className="flex flex-wrap gap-1.5">
        {def.columns.map((c) => {
          const on = shown.includes(c.key)
          return (
            <button
              key={c.key}
              type="button"
              aria-pressed={on}
              data-testid={id(c.key)}
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
