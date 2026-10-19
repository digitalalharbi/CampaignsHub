import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarClock } from 'lucide-react'
import { listRoutines, setRoutine } from './api'
import { Card } from '@/components/ui/Card'
import { toApiError } from '@/lib/api/client'

const OUTCOME: Record<string, { ar: string; en: string }> = {
  queued: { ar: 'آخر تشغيل: بدأ', en: 'Last run: started' },
  skipped_busy: { ar: 'آخر تشغيل: تُخطّي لأن السابق لم ينتهِ', en: 'Last run: skipped, the previous one was still answering' },
  no_ai_credential: { ar: 'آخر تشغيل: تُخطّي — لا مفتاح ذكاء اصطناعي', en: 'Last run: skipped — no AI key' },
  model_unavailable: { ar: 'آخر تشغيل: تُخطّي — النموذج غير متاح', en: 'Last run: skipped — model unavailable' },
}

/**
 * AGENTS-ROUTINES-001 — the scheduled routines of this project. Off by default (each run spends the
 * workspace's own model tokens); a run opens a conversation here, and any change it wants waits in Approvals.
 */
export function RoutinesCard({ projectId, ar, canManage, onOpen }: { projectId: string; ar: boolean; canManage: boolean; onOpen: (threadId: string) => void }) {
  const qc = useQueryClient()
  const routines = useQuery({ queryKey: ['agent-routines', projectId], queryFn: () => listRoutines(projectId), retry: false })
  const toggle = useMutation({
    mutationFn: ({ key, enabled }: { key: string; enabled: boolean }) => setRoutine(projectId, key, enabled),
    onSuccess: (data) => qc.setQueryData(['agent-routines', projectId], data),
  })

  return (
    <Card className="space-y-2" data-testid="agent-routines">
      <p className="flex items-center gap-1 text-xs font-bold text-text-primary"><CalendarClock size={13} aria-hidden />{ar ? 'المهام المجدولة' : 'Scheduled routines'}</p>
      <ul className="space-y-2">
        {(routines.data?.routines ?? []).map((r) => (
          <li key={r.key} className="space-y-0.5" data-testid={`routine-${r.key}`}>
            <label className="flex items-center justify-between gap-2 text-xs">
              <span className="min-w-0">
                <span className="block font-semibold text-text-primary">{ar ? r.ar : r.en}</span>
                <span className="block text-[11px] text-text-muted">{ar ? r.when_ar : r.when_en}</span>
              </span>
              <input
                type="checkbox"
                checked={r.enabled}
                disabled={!canManage || toggle.isPending}
                onChange={(e) => toggle.mutate({ key: r.key, enabled: e.target.checked })}
                data-testid={`routine-${r.key}-toggle`}
                className="h-4 w-4 shrink-0 accent-brand-600"
              />
            </label>
            {r.last_outcome && (
              <p className="text-[11px] text-text-muted">
                {OUTCOME[r.last_outcome] ? (ar ? OUTCOME[r.last_outcome].ar : OUTCOME[r.last_outcome].en) : r.last_outcome}
                {r.last_thread_id && r.last_outcome === 'queued' && (
                  <button type="button" className="ms-1 font-semibold text-brand-700 hover:underline" onClick={() => onOpen(r.last_thread_id!)} data-testid={`routine-${r.key}-open`}>
                    {ar ? 'عرض' : 'Open'}
                  </button>
                )}
              </p>
            )}
          </li>
        ))}
      </ul>
      <p className="text-[11px] text-text-muted">{ar ? 'متوقفة افتراضيًا. أي تغيير يقترحه وكيل ينتظر الموافقة.' : 'Off by default. Any change an agent proposes waits for approval.'}</p>
      {toggle.isError && <p role="alert" className="text-xs text-danger">{toApiError(toggle.error).message}</p>}
    </Card>
  )
}
