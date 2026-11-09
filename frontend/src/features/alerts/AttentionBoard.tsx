import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowUpRight, Hourglass, Play, ScanSearch, Zap } from 'lucide-react'
import { Delta } from '@/components/ui/Delta'
import { campaigns } from '@/lib/counted'
import { platformLabel } from '@/lib/platforms'
import { decideAlert, getAttentionBoard, type AlertPriority, type AttentionItem, type CellStatus } from './api'
import { CELL_LABEL, CELL_MARK, CELL_TONE, PRIORITY_TONE, pick } from './decisionCopy'

/* Terse on purpose: the colour, the icon and the number carry the meaning; a word is added only where a symbol alone would not be understood. */
const T = {
  ar: { title: 'يحتاج قرارك', none: 'لا شيء يحتاج قرارك', matrix: 'المنصات', decide: 'قرار', open: 'الحملة', start: 'بدء المعالجة', unowned: 'بلا مسؤول',
    more: (n: number) => `+${n}`, less: 'أقل', unchecked: 'لم يُفحص', checked: 'مفحوصة', rows_more: (n: number) => `+${n} سليمة`,
    truncated: (n: number, total: number) => `${n} من ${campaigns(total, 'ar')}`, all: 'الكل',
    auto: { needs_decision: 'يحتاج قرارك', awaiting_approval: 'ينتظر موافقتك', done_automatically: 'تم تلقائيًا', in_flight: 'قيد التنفيذ' } },
  en: { title: 'Needs your decision', none: 'Nothing needs a decision', matrix: 'Platforms', decide: 'Decide', open: 'Campaign', start: 'Start', unowned: 'No owner',
    more: (n: number) => `+${n}`, less: 'Less', unchecked: 'Not checked', checked: 'checked', rows_more: (n: number) => `+${n} healthy`,
    truncated: (n: number, total: number) => `${n} of ${campaigns(total, 'en')}`, all: 'All',
    auto: { needs_decision: 'Needs you', awaiting_approval: 'Awaiting approval', done_automatically: 'Done automatically', in_flight: 'In progress' } },
}

const LIST_SHOWN = 5
const ROWS_SHOWN = 8

const AUTO_TONE: Record<AttentionItem['automation']['state'], string> = {
  needs_decision: 'bg-danger/10 text-danger', awaiting_approval: 'bg-warning/15 text-warning', done_automatically: 'bg-success/10 text-success', in_flight: 'bg-surface-hover text-text-secondary',
}

/**
 * OPERATIONAL-INTELLIGENCE-P1-001 — exceptions first, as a picture: a ranked list of cards (one short line each), a heat-map of
 * campaign × platform, a coverage count. Healthy is a number, not a list. A cell is «healthy» only with recent data and nothing open.
 */
export function AttentionBoard({ locale, projectId, onOpen }: { locale: 'ar' | 'en'; projectId: string | null; onOpen: (id: string) => void }) {
  const ar = locale === 'ar'
  const t = T[locale]
  const [showAllRows, setShowAllRows] = useState(false)
  const [showAllItems, setShowAllItems] = useState(false)
  const [prio, setPrio] = useState<AlertPriority | null>(null)
  const [platform, setPlatform] = useState<string | null>(null)
  const q = useQuery({ queryKey: ['alert-attention', projectId], queryFn: () => getAttentionBoard(projectId), retry: false, refetchInterval: 60_000 })
  const board = q.data
  if (!board) return null

  const flagged = board.rows.filter((r) => Object.values(r.cells).some((c) => c.status !== 'healthy'))
  const rows = showAllRows ? board.rows : flagged.slice(0, ROWS_SHOWN)
  const hiddenRows = board.rows.length - rows.length
  const platformsInList = [...new Set(board.needs_decision.flatMap((i) => i.platforms))]
  const items = board.needs_decision.filter((i) => (prio === null || i.priority === prio) && (platform === null || i.platforms.includes(platform)))
  const shown = showAllItems ? items : items.slice(0, LIST_SHOWN)

  return (
    <section data-testid="alert-board" className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-3 sm:p-4">
      <header className="flex flex-wrap items-center gap-2">
        <h2 className="me-auto text-base font-extrabold text-text-primary">{t.title}</h2>
        <span data-testid="alert-board-coverage" title={`${board.coverage.checked}/${board.coverage.total} ${t.checked}`}
          className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-bold ${board.coverage.checked === board.coverage.total ? 'bg-success/10 text-success' : 'bg-warning/15 text-warning'}`}>
          <ScanSearch size={13} aria-hidden /><span className="tnum" dir="ltr">{board.coverage.checked}/{board.coverage.total}</span>
        </span>
        <div className="flex flex-wrap gap-1.5" data-testid="alert-board-counts">
          {(['problem', 'no_data', 'watch', 'healthy'] as CellStatus[]).map((k) => (board.counts[k] > 0 || k === 'healthy') && (
            <span key={k} data-testid={k === 'healthy' ? 'alert-board-healthy' : undefined} title={pick(CELL_LABEL[k], ar)} className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-bold ${CELL_TONE[k]}`}>
              <span aria-hidden>{CELL_MARK[k]}</span><span className="tnum">{board.counts[k]}</span>
            </span>
          ))}
        </div>
      </header>

      {board.unchecked.length > 0 && (
        <div data-testid="alert-board-unchecked" className="flex flex-wrap items-center gap-1.5 text-xs">
          <span className="font-bold text-warning">{t.unchecked}</span>
          {board.unchecked.slice(0, 3).map((u) => <span key={u.id} className="rounded-full bg-surface-hover px-2 py-0.5 text-text-secondary">{u.name}</span>)}
          {board.unchecked.length > 3 && <span className="text-text-muted">{t.more(board.unchecked.length - 3)}</span>}
        </div>
      )}

      {board.needs_decision.length > 0 && (
        <div className="flex flex-wrap items-center gap-1.5" data-testid="alert-board-filters" role="group">
          {(['P1', 'P2', 'P3'] as AlertPriority[]).map((p) => (
            <button key={p} type="button" aria-pressed={prio === p} data-testid={`alert-filter-${p}`} onClick={() => setPrio(prio === p ? null : p)}
              className={`rounded-full px-2.5 py-0.5 text-xs font-extrabold ${PRIORITY_TONE[p]} ${prio === p ? 'ring-2 ring-brand-500' : 'opacity-80'}`}>{p}</button>
          ))}
          {platformsInList.length > 1 && platformsInList.map((p) => (
            <button key={p} type="button" aria-pressed={platform === p} data-testid={`alert-filter-${p}`} onClick={() => setPlatform(platform === p ? null : p)}
              className={`rounded-full bg-surface-hover px-2.5 py-0.5 text-xs font-semibold text-text-secondary ${platform === p ? 'ring-2 ring-brand-500' : ''}`}>{platformLabel(p, ar)}</button>
          ))}
        </div>
      )}

      {board.needs_decision.length === 0 ? (
        <p data-testid="alert-board-empty" className="rounded-xl bg-success/10 px-3 py-2 text-sm font-semibold text-success">✓ {t.none}</p>
      ) : (
        <ol data-testid="alert-board-list" className="flex flex-col gap-1.5">
          {shown.map((i) => <BoardItem key={i.id} i={i} ar={ar} t={t} onOpen={onOpen} />)}
        </ol>
      )}
      {items.length > LIST_SHOWN && (
        <button type="button" data-testid="alert-board-more" onClick={() => setShowAllItems((v) => !v)} className="self-start text-xs font-bold text-brand-700 hover:underline">
          {showAllItems ? t.less : t.more(items.length - LIST_SHOWN)}
        </button>
      )}

      {board.rows.length > 0 && (
        <div className="flex flex-col gap-1.5" data-testid="alert-matrix">
          <div className="overflow-x-auto">
            <table className="border-separate border-spacing-y-1 text-xs">
              <thead>
                <tr>
                  <th className="text-start text-text-muted">{t.matrix}</th>
                  {board.platforms.map((p) => <th key={p} className="px-0.5 text-center font-semibold text-text-secondary">{platformLabel(p, ar).slice(0, 7)}</th>)}
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.campaign_id}>
                    <td className="max-w-[150px] truncate pe-3 text-start font-semibold text-text-primary" title={r.name}>{r.name}</td>
                    {board.platforms.map((p) => {
                      const cell = r.cells[p]
                      if (!cell) return <td key={p} className="text-center text-text-muted">·</td>
                      const first = cell.alert_ids[0]
                      return (
                        <td key={p} className="px-0.5 text-center">
                          <button type="button" disabled={!first} onClick={() => first && onOpen(first)} data-testid={`board-cell-${r.campaign_id}-${p}`} data-status={cell.status}
                            aria-label={`${r.name} · ${platformLabel(p, ar)} · ${pick(CELL_LABEL[cell.status], ar)}`} title={pick(CELL_LABEL[cell.status], ar)}
                            className={`inline-flex h-7 w-9 items-center justify-center rounded-lg text-sm font-extrabold ${CELL_TONE[cell.status]} ${first ? 'cursor-pointer ring-1 ring-current/30 hover:ring-2' : 'cursor-default'}`}>
                            <span aria-hidden>{CELL_MARK[cell.status]}</span>
                          </button>
                        </td>
                      )
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-text-muted">
            {(['problem', 'no_data', 'watch', 'healthy'] as CellStatus[]).map((k) => (
              <span key={k} className="inline-flex items-center gap-1"><span className={`inline-flex h-4 w-4 items-center justify-center rounded ${CELL_TONE[k]}`} aria-hidden><span className="text-xs">{CELL_MARK[k]}</span></span>{pick(CELL_LABEL[k], ar)}</span>
            ))}
            {(hiddenRows > 0 || showAllRows) && (
              <button type="button" data-testid="alert-matrix-toggle" onClick={() => setShowAllRows((v) => !v)} className="font-bold text-brand-700 hover:underline">
                {showAllRows ? t.less : t.rows_more(hiddenRows)}
              </button>
            )}
            {board.truncated && <span data-testid="alert-matrix-truncated">{t.truncated(board.rows.length, board.rows_total)}</span>}
          </div>
        </div>
      )}
    </section>
  )
}

function BoardItem({ i, ar, t, onOpen }: { i: AttentionItem; ar: boolean; t: (typeof T)['ar']; onOpen: (id: string) => void }) {
  const qc = useQueryClient()
  const start = useMutation({
    mutationFn: () => decideAlert(i.id, { status: 'in_progress' }),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['alert-attention'] }); void qc.invalidateQueries({ queryKey: ['alert-events'] }) },
  })
  const e = i.evidence
  const AutoIcon = i.automation.state === 'done_automatically' ? Zap : i.automation.state === 'awaiting_approval' ? Hourglass : null

  return (
    <li data-testid={`alert-board-item-${i.id}`} data-priority={i.priority} data-automation={i.automation.state}
      className="flex flex-col gap-1 rounded-xl border border-border bg-background px-2.5 py-2">
      <div className="flex flex-wrap items-center gap-1.5">
        <span className={`rounded-full px-2 py-0.5 text-xs font-extrabold ${PRIORITY_TONE[i.priority]}`}>{i.priority}</span>
        <span className="min-w-0 flex-1 truncate font-semibold text-text-primary" title={pick(i.problem, ar)}>{pick(i.problem, ar)}</span>
        {!i.insufficient && e.before !== null && e.now !== null && e.metric && <Delta metric={e.metric} current={e.now} previous={e.before} ar={ar} />}
        {i.insufficient && <span data-testid={`alert-board-thin-${i.id}`} title={ar ? 'بيانات غير كافية' : 'Insufficient data'} className="rounded-full bg-surface-hover px-2 py-0.5 text-xs font-bold text-text-muted">{ar ? 'بيانات ناقصة' : 'Thin data'}</span>}
      </div>
      <div className="flex flex-wrap items-center gap-1.5 text-xs">
        <span className="max-w-[55%] truncate rounded-full bg-surface-hover px-2 py-0.5 font-semibold text-text-secondary">{i.campaign_name ?? i.platforms.map((p) => platformLabel(p, ar)).join('، ')}</span>
        {i.campaign_name && i.platforms.map((p) => <span key={p} className="rounded-full bg-surface-hover px-2 py-0.5 text-text-secondary">{platformLabel(p, ar).slice(0, 7)}</span>)}
        <span data-testid={`alert-auto-${i.id}`} className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-bold ${AUTO_TONE[i.automation.state]}`}>
          {AutoIcon && <AutoIcon size={12} aria-hidden />}{t.auto[i.automation.state]}
        </span>
        <span title={i.owner?.name || t.unowned} className="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-brand-500/15 px-1 font-bold text-brand-700">{i.owner?.name ? i.owner.name.slice(0, 1) : '–'}</span>
        <span className="ms-auto flex items-center gap-1">
          {i.decision_status === 'new' && (
            <button type="button" data-testid={`alert-start-${i.id}`} title={t.start} aria-label={t.start} disabled={start.isPending} onClick={() => start.mutate()}
              className="rounded-lg border border-border p-1 text-text-secondary hover:border-brand-500 hover:text-brand-600"><Play size={13} aria-hidden /></button>
          )}
          <button type="button" data-testid={`alert-open-drawer-${i.id}`} onClick={() => onOpen(i.id)} className="rounded-lg bg-brand-600 px-2.5 py-1 font-bold text-white hover:bg-brand-700">{t.decide}</button>
          <Link to={i.path} title={t.open} aria-label={t.open} className="rounded-lg border border-border p-1 text-text-secondary hover:border-brand-500 hover:text-brand-600"><ArrowUpRight size={13} aria-hidden /></Link>
        </span>
      </div>
      {!i.insufficient && i.action && <p className="truncate text-xs font-semibold text-brand-700" title={pick(i.action, ar)}>{pick(i.action, ar)}</p>}
    </li>
  )
}
