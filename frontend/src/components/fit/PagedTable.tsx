import { useState, type ReactNode } from 'react'
import { useMeasuredPage } from './AgencyFit'
import { Pager } from './Pager'

/**
 * AF2 — a plain table that pages itself to the screen on a fitted `/agency` page: as many rows as fit the panel, the
 * rest a page away. Everywhere else — a phone, a short window, `/app` — every row is drawn, as it always was.
 */
export function PagedTable({ head, rows, className, testid, ar }: { head: ReactNode; rows: ReactNode[]; className: string; testid?: string; ar: boolean }) {
  const { ref, size } = useMeasuredPage(rows.length)
  const [page, setPage] = useState(1)
  const pages = size ? Math.max(1, Math.ceil(rows.length / size)) : 1
  const current = Math.min(page, pages)
  const shown = size ? rows.slice((current - 1) * size, current * size) : rows

  return (
    <div ref={ref}>
      <table className={className} data-testid={testid}>
        {head}
        <tbody>{shown}</tbody>
      </table>
      {size !== undefined && pages > 1 && <Pager page={current} pages={pages} onPage={setPage} total={rows.length} ar={ar} testid={testid ? `${testid}-pager` : undefined} />}
    </div>
  )
}
