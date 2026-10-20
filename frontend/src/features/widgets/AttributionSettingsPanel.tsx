import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { putData, toApiError } from '@/lib/api/client'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { useUi } from '@/stores/ui'

const MODELS: Array<{ id: string; en: string; ar: string }> = [
  { id: 'first_click', en: 'First click', ar: 'أول نقرة' },
  { id: 'last_click', en: 'Last click', ar: 'آخر نقرة' },
  { id: 'last_paid_click', en: 'Last paid click', ar: 'آخر نقرة مدفوعة' },
  { id: 'linear', en: 'Linear', ar: 'خطي' },
  { id: 'position_based', en: 'Position-based', ar: 'حسب الموضع' },
  { id: 'time_decay', en: 'Time decay', ar: 'تناقص زمني' },
]

/** ATTRIB-CREDIT-001 — choose which fractional models this project reads, and the lookback they use. */
export function AttributionSettingsPanel({ projectId }: { projectId: string }) {
  const ar = useUi((u) => u.locale) === 'ar'
  const qc = useQueryClient()
  const [chosen, setChosen] = useState<string[]>(['last_click', 'linear'])
  const [lookback, setLookback] = useState(30)
  const save = useMutation({
    mutationFn: () => putData(`/projects/${projectId}/attribution-settings`, { models: chosen, lookback_days: lookback }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['attribution-credit', projectId] }),
  })

  const toggle = (id: string) => setChosen((c) => (c.includes(id) ? c.filter((x) => x !== id) : [...c, id]))

  return (
    <div className="space-y-3" data-testid="attribution-settings">
      <p className="text-sm text-text-secondary">
        {ar
          ? 'تتقاسم هذه النماذج كل طلب بين نقاط التماس التسويقية المُتحقَّق منها فقط. لا يُحتسب أي طلب سابق كنقطة تماس.'
          : 'These models share each order across its verified marketing touchpoints only. An earlier order is never a touchpoint.'}
      </p>
      <div className="flex flex-wrap gap-2">
        {MODELS.map((m) => (
          <label key={m.id} className="inline-flex items-center gap-1.5 rounded-lg border border-border px-2.5 py-1 text-sm">
            <input type="checkbox" data-testid={`attribution-choose-${m.id}`} checked={chosen.includes(m.id)} onChange={() => toggle(m.id)} />
            {ar ? m.ar : m.en}
          </label>
        ))}
      </div>
      <label className="flex items-center gap-2 text-sm">
        {ar ? 'نافذة الرجوع (أيام)' : 'Lookback (days)'}
        <input type="number" min={1} max={365} value={lookback} onChange={(e) => setLookback(Number(e.target.value))} className="w-20 rounded-lg border border-border bg-surface px-2 py-1" data-testid="attribution-lookback" />
      </label>
      {save.isError && <Alert severity="danger" title={toApiError(save.error).message} />}
      <Button onClick={() => save.mutate()} disabled={save.isPending || chosen.length === 0} data-testid="attribution-save">
        {ar ? 'حفظ النماذج' : 'Save models'}
      </Button>
    </div>
  )
}
