import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { toApiError } from '@/lib/api/client'
import { Num } from '@/components/ui/Num'
import { getDeliverySettings, saveDeliverySettings, type DeliverySettingsData } from './deliveryApi'

const COPY = {
  ar: {
    title: 'إعداد التسليم الدوري', auto: 'تجهيز مسودة التقرير تلقائيًا', autoHint: 'تُجهَّز مسودة فقط؛ لا يُرسل شيء للعميل قبل مراجعتك واعتمادك.',
    cadence: 'الدورة', weekly: 'أسبوعي', monthly: 'شهري', weekStart: 'بداية الأسبوع', monday: 'الاثنين', sunday: 'الأحد',
    mode: 'نوع التقرير', snapshot: 'ثابت للفترة', live: 'مباشر (يتحدّث مع البيانات)', next: 'الفترة القادمة', saving: 'جارٍ الحفظ…',
    reviewer: 'مسؤول المراجعة', nobody: 'بدون تعيين', remind: 'تذكير المراجع بعد', hours: 'ساعة',
    liveNote: 'الرابط المباشر يعرض أحدث بيانات متزامنة؛ يبقى الملف PDF ثابتًا للفترة.',
  },
  en: {
    title: 'Periodic delivery settings', auto: 'Prepare the report draft automatically', autoHint: 'Only a draft is prepared; nothing reaches the client before you review and approve it.',
    cadence: 'Cadence', weekly: 'Weekly', monthly: 'Monthly', weekStart: 'Week begins on', monday: 'Monday', sunday: 'Sunday',
    mode: 'Report type', snapshot: 'Snapshot (fixed for the period)', live: 'Live (follows the synced data)', next: 'Next period', saving: 'Saving…',
    reviewer: 'Reviewer', nobody: 'Not assigned', remind: 'Remind the reviewer after', hours: 'hours',
    liveNote: 'A live link shows the latest synced data; the PDF stays fixed for the period.',
  },
}

/**
 * DELIVERY-SETTINGS-001 — one client's delivery set once: on/off, weekly or monthly, the day the week begins, snapshot or live.
 * Saved on each change (a choice is a decision, not a draft); the sweep reads exactly what is stored here.
 */
export function DeliverySettingsCard({ clientId, ar, canEdit }: { clientId: string; ar: boolean; canEdit: boolean }) {
  const t = ar ? COPY.ar : COPY.en
  const qc = useQueryClient()
  const key = ['delivery', 'settings', clientId]
  const q = useQuery({ queryKey: key, queryFn: () => getDeliverySettings(clientId) })
  const save = useMutation({
    mutationFn: (body: Partial<DeliverySettingsData>) => saveDeliverySettings(clientId, body),
    onSuccess: (data) => qc.setQueryData(key, data),
  })
  const s = q.data
  if (!s) return q.isError ? <p role="alert" className="text-xs text-danger">{toApiError(q.error).message}</p> : <div className="h-20 animate-pulse rounded-xl bg-surface-secondary" />

  const seg = (on: boolean) => `rounded-lg px-3 py-1.5 text-xs font-semibold ${on ? 'bg-brand-600 text-white' : 'border border-border text-text-secondary hover:bg-surface-secondary'} disabled:opacity-60`
  const busy = save.isPending || !canEdit

  return (
    <section data-testid="delivery-settings" className="space-y-3 rounded-xl border border-border bg-surface p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h4 className="font-bold text-text-primary">{t.title}</h4>
        {save.isPending && <span className="flex items-center gap-1 text-xs text-text-muted"><Loader2 size={13} className="animate-spin" /> {t.saving}</span>}
      </div>
      <label className="flex items-start gap-2 text-xs">
        <input type="checkbox" data-testid="delivery-auto" checked={s.enabled} disabled={busy} onChange={(e) => save.mutate({ enabled: e.target.checked })} className="mt-0.5" />
        <span><span className="font-semibold text-text-primary">{t.auto}</span><br /><span className="text-text-muted">{t.autoHint}</span></span>
      </label>
      <div className="grid gap-3 sm:grid-cols-3">
        <div>
          <p className="mb-1 text-xs font-semibold text-text-secondary">{t.cadence}</p>
          <div className="flex gap-1.5">
            {(['weekly', 'monthly'] as const).map((c) => <button key={c} type="button" data-testid={`delivery-cadence-${c}`} aria-pressed={s.cadence === c} disabled={busy} onClick={() => save.mutate({ cadence: c })} className={seg(s.cadence === c)}>{t[c]}</button>)}
          </div>
        </div>
        {s.cadence === 'weekly' && (
          <div>
            <p className="mb-1 text-xs font-semibold text-text-secondary">{t.weekStart}</p>
            <div className="flex gap-1.5">
              {(['sunday', 'monday'] as const).map((d) => <button key={d} type="button" data-testid={`delivery-weekstart-${d}`} aria-pressed={s.week_start === d} disabled={busy} onClick={() => save.mutate({ week_start: d })} className={seg(s.week_start === d)}>{t[d]}</button>)}
            </div>
          </div>
        )}
        <div>
          <p className="mb-1 text-xs font-semibold text-text-secondary">{t.mode}</p>
          <div className="flex flex-wrap gap-1.5">
            {(['snapshot', 'live'] as const).map((m) => <button key={m} type="button" data-testid={`delivery-mode-${m}`} aria-pressed={s.mode === m} disabled={busy} onClick={() => save.mutate({ mode: m })} className={seg(s.mode === m)}>{t[m]}</button>)}
          </div>
        </div>
      </div>
      <div className="grid gap-3 sm:grid-cols-2">
        <label className="text-xs font-semibold text-text-secondary">{t.reviewer}
          <select data-testid="delivery-reviewer" disabled={busy} value={s.reviewer_id ?? ''} onChange={(e) => save.mutate({ reviewer_id: e.target.value === '' ? null : Number(e.target.value) })} className="mt-1 h-9 w-full rounded-lg border border-border bg-surface px-2 text-xs font-normal">
            <option value="">{t.nobody}</option>
            {s.reviewers.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
          </select>
        </label>
        <label className="text-xs font-semibold text-text-secondary">{t.remind}
          <span className="mt-1 flex items-center gap-2">
            <input data-testid="delivery-remind-hours" type="number" min={1} max={168} disabled={busy} defaultValue={s.reminder_hours} key={s.reminder_hours}
              onBlur={(e) => { const v = Math.max(1, Math.min(168, Number(e.target.value) || 24)); if (v !== s.reminder_hours) save.mutate({ reminder_hours: v }) }}
              className="h-9 w-24 rounded-lg border border-border bg-surface px-2 text-xs font-normal" />
            <span className="font-normal">{t.hours}</span>
          </span>
        </label>
      </div>
      {s.mode === 'live' && <p className="text-xs text-text-muted">{t.liveNote}</p>}
      <p className="text-xs text-text-secondary">{t.next}: <span dir="ltr"><Num>{s.next_period.from} → {s.next_period.to}</Num></span></p>
      {save.isError && <p role="alert" className="text-xs text-danger">{toApiError(save.error).message}</p>}
    </section>
  )
}
