import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Lock, MessageSquarePlus, Pencil, Pin, PinOff, Search, Trash2, X } from 'lucide-react'
import { toApiError } from '@/lib/api/client'
import { deleteThread, listThreads, updateThread, type AgentThreadSummary } from './api'

/**
 * AGENTS-ASSISTANT-001 — the slim conversations rail: search, pin, rename, delete (always confirmed), grouped by day.
 *
 * The person's own conversations only (the server decides what is theirs; a scheduled run is the project's and is marked).
 * On a small screen the rail folds away behind one button so the chat keeps the width.
 */
type Group = 'pinned' | 'today' | 'yesterday' | 'week' | 'older'

const GROUP_WORDS: Record<Group, { ar: string; en: string }> = {
  pinned: { ar: 'مثبّتة', en: 'Pinned' },
  today: { ar: 'اليوم', en: 'Today' },
  yesterday: { ar: 'أمس', en: 'Yesterday' },
  week: { ar: 'آخر 7 أيام', en: 'Last 7 days' },
  older: { ar: 'أقدم', en: 'Older' },
}

export function groupOf(t: Pick<AgentThreadSummary, 'pinned' | 'updated_at'>, now: Date = new Date()): Group {
  if (t.pinned) return 'pinned'
  if (!t.updated_at) return 'older'
  const day = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()
  const diff = Math.round((day(now) - day(new Date(t.updated_at))) / 86_400_000)
  if (diff <= 0) return 'today'
  if (diff === 1) return 'yesterday'
  if (diff < 7) return 'week'
  return 'older'
}

export function ConversationRail({ projectId, ar, activeId, onOpen, onNew, onGone }: {
  projectId: string
  ar: boolean
  activeId: string | null
  onOpen: (id: string) => void
  onNew: () => void
  onGone: (id: string) => void
}) {
  const qc = useQueryClient()
  const [q, setQ] = useState('')
  const [debounced, setDebounced] = useState('')
  const [renaming, setRenaming] = useState<{ id: string; title: string } | null>(null)
  const [confirm, setConfirm] = useState<string | null>(null)
  useEffect(() => { const h = setTimeout(() => setDebounced(q.trim()), 300); return () => clearTimeout(h) }, [q])

  const list = useQuery({ queryKey: ['agent-threads', projectId, { q: debounced }], queryFn: () => listThreads(projectId, { q: debounced }), retry: false })
  const refresh = () => void qc.invalidateQueries({ queryKey: ['agent-threads', projectId] })
  const patch = useMutation({ meta: { feedback: 'none' }, mutationFn: (v: { id: string; title?: string; pinned?: boolean }) => updateThread(projectId, v.id, v), onSuccess: () => { setRenaming(null); refresh() } })
  const remove = useMutation({ meta: { feedback: 'none' }, mutationFn: (id: string) => deleteThread(projectId, id), onSuccess: (_r, id) => { setConfirm(null); onGone(id); refresh() } })

  const rows = list.data?.threads ?? []
  const groups = (['pinned', 'today', 'yesterday', 'week', 'older'] as Group[])
    .map((g) => ({ g, items: rows.filter((t) => groupOf(t) === g) }))
    .filter((x) => x.items.length > 0)
  const error = patch.error ?? remove.error

  return (
    <nav className="flex min-h-0 min-w-0 flex-col gap-2" data-testid="conversation-rail" aria-label={ar ? 'المحادثات' : 'Conversations'}>
      <button type="button" onClick={onNew} data-testid="rail-new" className="flex items-center justify-center gap-1.5 rounded-xl border border-border bg-surface px-3 py-2 text-sm font-semibold text-text-primary hover:border-brand-400">
        <MessageSquarePlus size={15} aria-hidden />{ar ? 'محادثة جديدة' : 'New chat'}
      </button>
      <label className="relative block">
        <span className="sr-only">{ar ? 'بحث' : 'Search'}</span>
        <Search size={13} className="pointer-events-none absolute start-2.5 top-2.5 text-text-muted" aria-hidden />
        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder={ar ? 'بحث…' : 'Search…'} data-testid="rail-search" className="h-8 w-full rounded-lg border border-border bg-surface ps-7 pe-2 text-xs" />
      </label>

      <div className="min-h-0 flex-1 space-y-3 overflow-y-auto">
        {list.isSuccess && rows.length === 0 && <p className="px-1 text-xs text-text-muted">{debounced ? (ar ? 'لا نتائج.' : 'No results.') : (ar ? 'لا محادثات بعد.' : 'No conversations yet.')}</p>}
        {groups.map(({ g, items }) => (
          <div key={g} data-testid={`rail-group-${g}`}>
            <p className="px-1 pb-1 text-xs font-semibold text-text-muted">{ar ? GROUP_WORDS[g].ar : GROUP_WORDS[g].en}</p>
            <ul className="space-y-0.5">
              {items.map((t) => {
                const active = t.id === activeId
                if (renaming?.id === t.id) {
                  return (
                    <li key={t.id}>
                      <form className="flex items-center gap-1" onSubmit={(e) => { e.preventDefault(); if (renaming.title.trim()) patch.mutate({ id: t.id, title: renaming.title.trim() }) }}>
                        <input autoFocus value={renaming.title} maxLength={160} onChange={(e) => setRenaming({ id: t.id, title: e.target.value })} dir="auto" data-testid="rail-rename-input" className="h-7 min-w-0 flex-1 rounded-md border border-border bg-surface px-1.5 text-xs" />
                        <button type="submit" aria-label={ar ? 'حفظ' : 'Save'} className="text-brand-700"><Check size={13} /></button>
                        <button type="button" aria-label={ar ? 'إلغاء' : 'Cancel'} onClick={() => setRenaming(null)} className="text-text-muted"><X size={13} /></button>
                      </form>
                    </li>
                  )
                }
                return (
                  <li key={t.id} className={`group flex min-w-0 items-center gap-1 rounded-lg px-1.5 py-1 ${active ? 'bg-[var(--brand-background)]' : 'hover:bg-surface-secondary'}`}>
                    <button type="button" onClick={() => onOpen(t.id)} className="min-w-0 flex-1 truncate text-start text-xs text-text-primary" dir="auto" data-testid={`rail-open-${t.id}`} title={t.title ?? ''}>
                      {t.shared && <Lock size={10} className="me-1 inline text-text-muted" aria-label={ar ? 'تشغيل مجدول' : 'Scheduled run'} />}
                      {t.title ?? (ar ? 'بدون عنوان' : 'Untitled')}
                    </button>
                    {!t.shared && confirm !== t.id && (
                      <span className="flex shrink-0 items-center gap-0.5 opacity-100 sm:opacity-0 sm:group-hover:opacity-100 sm:group-focus-within:opacity-100">
                        <button type="button" onClick={() => patch.mutate({ id: t.id, pinned: !t.pinned })} aria-label={t.pinned ? (ar ? 'إلغاء التثبيت' : 'Unpin') : (ar ? 'تثبيت' : 'Pin')} data-testid={`rail-pin-${t.id}`} className="p-0.5 text-text-muted hover:text-text-primary">{t.pinned ? <PinOff size={12} /> : <Pin size={12} />}</button>
                        <button type="button" onClick={() => setRenaming({ id: t.id, title: t.title ?? '' })} aria-label={ar ? 'إعادة تسمية' : 'Rename'} data-testid={`rail-rename-${t.id}`} className="p-0.5 text-text-muted hover:text-text-primary"><Pencil size={12} /></button>
                        <button type="button" onClick={() => setConfirm(t.id)} aria-label={ar ? 'حذف' : 'Delete'} data-testid={`rail-delete-${t.id}`} className="p-0.5 text-text-muted hover:text-danger"><Trash2 size={12} /></button>
                      </span>
                    )}
                    {confirm === t.id && (
                      <span className="flex shrink-0 items-center gap-1 text-xs" data-testid="rail-confirm">
                        <button type="button" onClick={() => remove.mutate(t.id)} className="font-semibold text-danger" data-testid="rail-confirm-yes">{ar ? 'احذف' : 'Delete'}</button>
                        <button type="button" onClick={() => setConfirm(null)} className="text-text-muted">{ar ? 'إلغاء' : 'Cancel'}</button>
                      </span>
                    )}
                  </li>
                )
              })}
            </ul>
          </div>
        ))}
      </div>
      <p className="flex items-center gap-1 px-1 text-xs text-text-muted"><Lock size={10} aria-hidden />{ar ? 'محادثاتك خاصة بك.' : 'Your chats are private.'}</p>
      {error && <p role="alert" className="text-xs text-danger">{toApiError(error).message}</p>}
    </nav>
  )
}
