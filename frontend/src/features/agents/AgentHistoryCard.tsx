import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2, Lock, Search, Trash2 } from 'lucide-react'
import { Card } from '@/components/ui/Card'
import { toApiError } from '@/lib/api/client'
import { days } from '@/lib/counted'
import { clearThreads, deleteThread, listThreads, type AgentRole } from './api'

const COPY = {
  ar: {
    title: 'المحادثات', neu: 'جديدة', search: 'ابحث في المحادثات…', all: 'كل الوكلاء', anyStatus: 'كل الحالات', failed: 'فشلت', thinking: 'قيد الرد',
    none: 'لا محادثات بعد.', noMatch: 'لا نتائج مطابقة.', private: 'محادثاتك خاصة بك — لا يراها زملاؤك.', keep: (d: number) => `تُحذف المحادثات تلقائيًا بعد ${days(d, 'ar')} من آخر رسالة.`,
    shared: 'تشغيل مجدول', remove: 'حذف المحادثة', clear: 'حذف كل محادثاتي', yes: 'نعم، احذف', cancel: 'إلغاء',
    confirmOne: 'سيُحذف هذا الحوار نهائيًا.', confirmAll: 'ستُحذف كل محادثاتك في هذا المشروع نهائيًا (لا تُحذف محادثات الزملاء).', more: (n: number) => `يعرض أحدث 30 من ${n}.`,
  },
  en: {
    title: 'Conversations', neu: 'New', search: 'Search conversations…', all: 'All agents', anyStatus: 'Any status', failed: 'Failed', thinking: 'Answering',
    none: 'No conversations yet.', noMatch: 'Nothing matches.', private: 'Your conversations are private to you — colleagues cannot see them.', keep: (d: number) => `Conversations are deleted automatically ${days(d, 'en')} after their last message.`,
    shared: 'Scheduled run', remove: 'Delete conversation', clear: 'Delete all my conversations', yes: 'Yes, delete', cancel: 'Cancel',
    confirmOne: 'This conversation will be deleted permanently.', confirmAll: 'All your conversations in this project will be deleted permanently (colleagues’ are not touched).', more: (n: number) => `Showing the latest 30 of ${n}.`,
  },
}

/**
 * AGENT-CHAT-PRIVACY-001 — the person's own conversation history: private, searchable, filterable, deletable, with the retention said.
 * The server decides what is theirs; this only asks and shows. Deleting always asks first and says what it removes.
 */
export function AgentHistoryCard({ projectId, ar, roles, current, activeId, onOpen, onNew, onGone }: {
  projectId: string
  ar: boolean
  roles: AgentRole[]
  current: string
  activeId: string | null
  onOpen: (id: string) => void
  onNew: () => void
  /** A conversation the screen may be showing was deleted. */
  onGone: (ids: string[] | 'all') => void
}) {
  const t = ar ? COPY.ar : COPY.en
  const qc = useQueryClient()
  const [q, setQ] = useState('')
  const [debounced, setDebounced] = useState('')
  const [agent, setAgent] = useState<string>('')
  const [status, setStatus] = useState<string>('')
  const [confirm, setConfirm] = useState<string | 'all' | null>(null)
  useEffect(() => { const h = setTimeout(() => setDebounced(q.trim()), 300); return () => clearTimeout(h) }, [q])

  const filters = { q: debounced, agent, status }
  const list = useQuery({ queryKey: ['agent-threads', projectId, filters], queryFn: () => listThreads(projectId, filters), retry: false })
  const refresh = () => { setConfirm(null); void qc.invalidateQueries({ queryKey: ['agent-threads', projectId] }) }
  const remove = useMutation({ mutationFn: (id: string) => deleteThread(projectId, id), onSuccess: (_r, id) => { onGone([id]); refresh() } })
  const clear = useMutation({ mutationFn: () => clearThreads(projectId), onSuccess: () => { onGone('all'); refresh() } })

  const rows = list.data?.threads ?? []
  const filtering = debounced !== '' || agent !== '' || status !== ''
  const error = remove.error ?? clear.error
  const field = 'h-8 min-w-0 flex-1 rounded-lg border border-border bg-surface px-2 text-xs'
  void current

  return (
    <Card className="space-y-2" data-testid="agent-threads">
      <div className="flex items-center justify-between gap-2">
        <p className="text-xs font-bold text-text-primary">{t.title}</p>
        <button type="button" className="text-xs font-semibold text-brand-700 hover:underline" onClick={onNew} data-testid="agent-new">{t.neu}</button>
      </div>
      <p data-testid="agent-privacy" className="flex items-start gap-1.5 text-[11px] text-text-muted"><Lock size={11} className="mt-0.5 shrink-0" /> <span>{t.private} {t.keep(list.data?.retention_days ?? 90)}</span></p>

      <label className="relative block">
        <Search size={12} className="pointer-events-none absolute start-2 top-2.5 text-text-muted" />
        <input data-testid="agent-search" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t.search} aria-label={t.search} maxLength={120} className="h-8 w-full rounded-lg border border-border bg-surface ps-7 pe-2 text-xs" />
      </label>
      <div className="flex gap-1.5">
        <select data-testid="agent-filter-agent" aria-label={t.all} value={agent} onChange={(e) => setAgent(e.target.value)} className={field}>
          <option value="">{t.all}</option>
          {roles.map((r) => <option key={r.key} value={r.key}>{ar ? r.ar : r.en}</option>)}
        </select>
        <select data-testid="agent-filter-status" aria-label={t.anyStatus} value={status} onChange={(e) => setStatus(e.target.value)} className={field}>
          <option value="">{t.anyStatus}</option>
          <option value="failed">{t.failed}</option>
          <option value="thinking">{t.thinking}</option>
        </select>
      </div>

      {list.isLoading && <Loader2 size={13} className="animate-spin text-text-muted" />}
      {!list.isLoading && rows.length === 0 && <p className="text-xs text-text-muted">{filtering ? t.noMatch : t.none}</p>}
      <ul className="space-y-1">
        {rows.map((r) => (
          <li key={r.id} data-testid={`thread-${r.id}`} className="group flex items-center gap-1">
            <button type="button" onClick={() => onOpen(r.id)} className={`min-w-0 flex-1 truncate rounded px-2 py-1 text-start text-xs ${r.id === activeId ? 'bg-brand-50 font-semibold text-brand-800' : 'text-text-secondary hover:bg-surface-secondary'}`}>
              {r.shared && <span className="me-1 rounded bg-surface-secondary px-1 text-[10px] text-text-muted">{t.shared}</span>}
              {r.title ?? r.id}
            </button>
            {confirm !== r.id && r.status !== 'thinking' && (
              <button type="button" data-testid={`thread-delete-${r.id}`} aria-label={t.remove} onClick={() => setConfirm(r.id)} className="rounded p-1 text-text-muted hover:text-danger"><Trash2 size={12} /></button>
            )}
          </li>
        ))}
      </ul>
      {(list.data?.total ?? 0) > rows.length && <p className="text-[11px] text-text-muted">{t.more(list.data?.total ?? 0)}</p>}

      {confirm !== null && (
        <div role="alert" data-testid="agent-delete-confirm" className="rounded-lg border border-warning/40 bg-[var(--warning-background)] p-2 text-xs text-text-primary">
          <p className="mb-1.5">{confirm === 'all' ? t.confirmAll : t.confirmOne}</p>
          <div className="flex gap-2">
            <button type="button" data-testid="agent-delete-yes" disabled={remove.isPending || clear.isPending} onClick={() => (confirm === 'all' ? clear.mutate() : remove.mutate(confirm))} className="rounded-lg bg-danger px-2.5 py-1 font-semibold text-white disabled:opacity-60">{t.yes}</button>
            <button type="button" onClick={() => setConfirm(null)} className="rounded-lg px-2.5 py-1 font-semibold text-text-secondary">{t.cancel}</button>
          </div>
        </div>
      )}
      {confirm === null && rows.some((r) => !r.shared) && (
        <button type="button" data-testid="agent-clear" onClick={() => setConfirm('all')} className="text-[11px] font-semibold text-danger hover:underline">{t.clear}</button>
      )}
      {error && <p role="alert" className="text-xs text-danger">{toApiError(error).message}</p>}
    </Card>
  )
}
