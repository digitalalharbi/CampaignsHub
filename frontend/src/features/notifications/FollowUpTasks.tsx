import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlarmClock, CheckCircle2, ChevronDown, ExternalLink, UserPlus } from 'lucide-react'
import { usePortalPath } from '@/app/portalPath'
import { assignTask, closeTask, getTask, listAssignees, snoozeTask, type FollowupTask } from './followupApi'
import { useCopy } from './centerCopy'

/**
 * NOTIF-V2-FOLLOWUP-001 — the follow-up task list (Owner spec §6), used by the centre's «متابعة» tab (compact) and by the full page.
 * Each task: its project and workspace, the reason, the date, the button to the exact place, snooze, assign, and — on the page —
 * its history (opened, closed, by whom). A task that is done by itself says so.
 */
const fmt = (iso: string | null) => (iso ? new Date(iso.length === 10 ? `${iso}T12:00:00` : iso).toLocaleDateString('en-CA') : '')

export function TaskList({ tasks, projectWord, compact = false, onNavigate }: { tasks: FollowupTask[]; projectWord: string; compact?: boolean; onNavigate?: () => void }) {
  const c = useCopy()

  if (tasks.length === 0) return <p data-testid="followup-empty" className="p-6 text-center text-sm text-text-muted">{c('followup_empty')}</p>

  return (
    <ul className="flex flex-col">
      {tasks.map((t) => <TaskRow key={t.id} task={t} projectWord={projectWord} compact={compact} onNavigate={onNavigate} />)}
    </ul>
  )
}

function TaskRow({ task, projectWord, compact, onNavigate }: { task: FollowupTask; projectWord: string; compact: boolean; onNavigate?: () => void }) {
  const c = useCopy()
  const navigate = useNavigate()
  const portalPath = usePortalPath()
  const qc = useQueryClient()
  const [menu, setMenu] = useState<null | 'snooze' | 'assign' | 'history'>(null)
  const refresh = () => qc.invalidateQueries({ queryKey: ['followup'] })
  const snooze = useMutation({ meta: { feedback: 'none' }, mutationFn: (p: '1h' | 'tomorrow' | 'next_week') => snoozeTask(task.id, p), onSuccess: () => { setMenu(null); void refresh() } })
  const close = useMutation({ meta: { feedback: 'none' }, mutationFn: () => closeTask(task.id), onSuccess: refresh })
  const assign = useMutation({ meta: { feedback: 'none' }, mutationFn: (userId: number) => assignTask(task.id, userId), onSuccess: () => { setMenu(null); void refresh() } })
  const assignees = useQuery({ queryKey: ['followup', 'assignees', task.id], queryFn: () => listAssignees(task.id), enabled: menu === 'assign' })
  const history = useQuery({ queryKey: ['followup', 'task', task.id], queryFn: () => getTask(task.id), enabled: menu === 'history' })
  const live = task.status === 'open' || task.status === 'snoozed'

  return (
    <li data-testid={`followup-${task.id}`} data-kind={task.kind} data-status={task.status} className="border-b border-border/60 px-3 py-3 last:border-0">
      <div className="flex min-w-0 flex-col gap-1">
        <p className="break-words text-sm font-bold text-text-primary">{task.title}</p>
        <p className="break-words text-xs text-text-secondary">{task.reason}</p>
        <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-text-muted">
          {task.project_name && <span className="rounded-full bg-surface-secondary px-1.5 py-0.5 font-semibold" data-testid="followup-project" title={projectWord}>{task.project_name}</span>}
          <span data-testid="followup-workspace">{task.workspace_name}</span>
          {task.due_on && <span dir="ltr" className="tnum">{c('due')}: {fmt(task.due_on)}</span>}
          <span>{task.assigned_name ? `${c('assigned_to')} ${task.assigned_name}` : c('unassigned')}</span>
          {task.status === 'done' && <span className="font-semibold text-success">{task.closed_how === 'auto' ? c('closed_auto') : c('closed_manual')}</span>}
          {task.status === 'snoozed' && task.snoozed_until && <span dir="ltr" className="tnum">{c('status_snoozed')} {fmt(task.snoozed_until)}</span>}
        </p>
      </div>

      <div className="mt-2 flex flex-wrap items-center gap-1.5">
        {task.action_url && live && (
          <button type="button" data-testid={`followup-open-${task.id}`} onClick={() => { onNavigate?.(); navigate(portalPath(task.action_url!)) }}
            className="inline-flex h-9 items-center gap-1 rounded-lg bg-brand-500 px-3 text-xs font-semibold text-white hover:bg-brand-600">
            <ExternalLink size={13} /> {c('open')}
          </button>
        )}
        {live && (
          <>
            <button type="button" data-testid={`followup-snooze-${task.id}`} aria-expanded={menu === 'snooze'} onClick={() => setMenu(menu === 'snooze' ? null : 'snooze')}
              className="inline-flex h-9 items-center gap-1 rounded-lg border border-border px-3 text-xs font-semibold text-text-secondary hover:bg-surface-hover"><AlarmClock size={13} /> {c('snooze')}</button>
            <button type="button" data-testid={`followup-assign-${task.id}`} aria-expanded={menu === 'assign'} onClick={() => setMenu(menu === 'assign' ? null : 'assign')}
              className="inline-flex h-9 items-center gap-1 rounded-lg border border-border px-3 text-xs font-semibold text-text-secondary hover:bg-surface-hover"><UserPlus size={13} /> {c('assign')}</button>
            <button type="button" data-testid={`followup-close-${task.id}`} onClick={() => close.mutate()}
              className="inline-flex h-9 items-center gap-1 rounded-lg border border-border px-3 text-xs font-semibold text-text-secondary hover:bg-surface-hover"><CheckCircle2 size={13} /> {c('done')}</button>
          </>
        )}
        {!compact && (
          <button type="button" data-testid={`followup-history-${task.id}`} aria-expanded={menu === 'history'} onClick={() => setMenu(menu === 'history' ? null : 'history')}
            className="inline-flex h-9 items-center gap-1 rounded-lg px-2 text-xs font-semibold text-text-muted hover:text-text-primary"><ChevronDown size={13} /> {c('history')}</button>
        )}
      </div>

      {menu === 'snooze' && (
        <div className="mt-2 flex flex-wrap gap-1.5" role="group" aria-label={c('snooze')}>
          {(['1h', 'tomorrow', 'next_week'] as const).map((p) => (
            <button key={p} type="button" data-testid={`followup-snooze-${p}-${task.id}`} onClick={() => snooze.mutate(p)} className="h-9 rounded-lg bg-surface-secondary px-3 text-xs font-semibold text-text-primary hover:bg-surface-hover">
              {p === '1h' ? c('snooze_1h') : p === 'tomorrow' ? c('snooze_tomorrow') : c('snooze_next_week')}
            </button>
          ))}
        </div>
      )}
      {menu === 'assign' && (
        <div className="mt-2">
          <select aria-label={c('assign')} data-testid={`followup-assign-select-${task.id}`} defaultValue="" onChange={(e) => e.target.value && assign.mutate(Number(e.target.value))}
            className="h-10 w-full max-w-full rounded-lg border border-border bg-surface px-2 text-sm text-text-primary">
            <option value="" disabled>{c('pick_person')}</option>
            {(assignees.data ?? []).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
          </select>
        </div>
      )}
      {menu === 'history' && (
        <ol data-testid={`followup-history-list-${task.id}`} className="mt-2 flex flex-col gap-1 border-s-2 border-border ps-3 text-xs text-text-secondary">
          {(history.data?.history ?? []).map((h, i) => (
            <li key={i}><span className="font-semibold text-text-primary">{h.label}</span> · {h.by} · <span dir="ltr" className="tnum">{h.at ? new Date(h.at).toLocaleString('en-CA') : ''}</span></li>
          ))}
        </ol>
      )}
    </li>
  )
}
