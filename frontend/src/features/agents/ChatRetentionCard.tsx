import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { Card } from '@/components/ui/Card'
import { toApiError } from '@/lib/api/client'
import { days } from '@/lib/counted'
import { getChatRetention, setChatRetention } from './api'

const PRESETS = [30, 60, 90, 180, 365]

/**
 * AGENT-CHAT-RETENTION-001 — for whoever manages the agents: how long this workspace keeps conversations after their last message.
 * A limit between 7 and 730 days; there is no «forever». It decides when conversations are deleted, and gives nobody a way to read them.
 */
export function ChatRetentionCard({ ar }: { ar: boolean }) {
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['ai-chat-retention'], queryFn: getChatRetention })
  const [draft, setDraft] = useState<string | null>(null)
  const save = useMutation({
    mutationFn: (days: number) => setChatRetention(days),
    onSuccess: (data) => { setDraft(null); qc.setQueryData(['ai-chat-retention'], data); void qc.invalidateQueries({ queryKey: ['agent-threads'] }) },
  })
  const r = q.data
  if (!r) return null
  const value = draft ?? String(r.days)
  const n = Number(value)
  const valid = Number.isInteger(n) && n >= r.min && n <= r.max

  return (
    <Card className="space-y-2" data-testid="chat-retention">
      <p className="text-xs font-bold text-text-primary">{ar ? 'مدة حفظ المحادثات' : 'Conversation retention'}</p>
      <p className="text-[11px] text-text-muted">
        {ar
          ? `تُحذف محادثات الوكلاء تلقائيًا بعد المدة التي تختارها من آخر رسالة (من ${r.min} إلى ${days(r.max, 'ar')}). المحادثات تبقى خاصة بأصحابها.`
          : `Agent conversations are deleted automatically this long after their last message (${r.min}–${days(r.max, 'en')}). They stay private to their authors.`}
      </p>
      <div className="flex flex-wrap items-center gap-1.5">
        {PRESETS.map((d) => (
          <button key={d} type="button" data-testid={`retention-preset-${d}`} aria-pressed={n === d} onClick={() => setDraft(String(d))} className={`rounded-lg px-2 py-1 text-[11px] font-semibold ${n === d ? 'bg-brand-600 text-white' : 'border border-border text-text-secondary hover:bg-surface-secondary'}`}>{d}</button>
        ))}
        <input data-testid="retention-days" type="number" min={r.min} max={r.max} value={value} onChange={(e) => setDraft(e.target.value)} aria-label={ar ? 'عدد الأيام' : 'Days'} className="h-8 w-20 rounded-lg border border-border bg-surface px-2 text-xs" />
        <span className="text-[11px] text-text-muted">{ar ? 'يوم' : 'days'}</span>
        <button type="button" data-testid="retention-save" disabled={!valid || save.isPending || n === r.days} onClick={() => save.mutate(n)} className="rounded-lg bg-brand-600 px-2.5 py-1 text-xs font-semibold text-white disabled:opacity-50">
          {save.isPending ? <Loader2 size={12} className="animate-spin" /> : (ar ? 'حفظ' : 'Save')}
        </button>
      </div>
      {!valid && <p role="alert" className="text-xs text-danger">{ar ? `أدخل رقمًا بين ${r.min} و${r.max}.` : `Enter a number between ${r.min} and ${r.max}.`}</p>}
      {save.isError && <p role="alert" className="text-xs text-danger">{toApiError(save.error).message}</p>}
    </Card>
  )
}
