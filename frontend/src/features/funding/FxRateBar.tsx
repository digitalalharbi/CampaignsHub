import { useState } from 'react'
import { Pencil } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { useUi } from '@/stores/ui'
import { useFundingRate, useSetFundingRate } from './fundingApi'
import { L } from './labels'
import { Money } from './money'

/**
 * BUDGET-FX-DEFAULT-001 — «سعر الدولار: 1 $ = 3.75 ر.س», always visible on the budgets and limits pages, editable by finance.
 * It is the rate used where no dated rate exists, so a budget never stops at «ناقص سعر صرف» for the riyal's own peg.
 */
export function FxRateBar() {
  const ar = useUi((s) => s.locale) === 'ar'
  const tx = (v: { ar: string; en: string }) => (ar ? v.ar : v.en)
  const { data } = useFundingRate()
  const save = useSetFundingRate()
  const [editing, setEditing] = useState(false)
  const [draft, setDraft] = useState('')
  const [msg, setMsg] = useState<string | null>(null)

  if (!data) return null

  const number = Number(draft)
  const valid = draft.trim() !== '' && Number.isFinite(number) && number >= 1 && number <= 10
  const submit = () => {
    if (!valid) { setMsg(tx(L.fxInvalid)); return }
    save.mutate(number, {
      onSuccess: () => { setEditing(false); setMsg(tx(L.fxSaved)) },
      onError: () => setMsg(tx(L.fxFailed)),
    })
  }

  return (
    <div className="flex min-w-0 flex-wrap items-center gap-2 px-1 text-xs text-text-secondary" data-testid="fx-rate-bar">
      <span className="font-semibold">{tx(L.fxRate)}</span>
      {editing ? (
        <>
          <span dir="ltr">1 $ =</span>
          <input
            inputMode="decimal"
            dir="ltr"
            value={draft}
            onChange={(e) => { setDraft(e.target.value); setMsg(null) }}
            aria-label={tx(L.fxRate)}
            data-testid="fx-rate-input"
            className="h-8 w-20 rounded-lg border border-border-strong bg-surface px-2 text-sm"
          />
          <Button size="sm" onClick={submit} loading={save.isPending} data-testid="fx-rate-save">{tx(L.fxSave)}</Button>
          <Button size="sm" variant="ghost" onClick={() => { setEditing(false); setMsg(null) }}>{tx(L.fxCancel)}</Button>
        </>
      ) : (
        <>
          <span data-testid="fx-rate-value"><bdi dir="ltr">1 $ =</bdi> <Money n={data.usd_sar} decimals={2} /></span>
          {!data.custom && <span className="rounded-full bg-surface-hover px-2 py-0.5" data-testid="fx-rate-default">{tx(L.fxDefault)}</span>}
          {data.can_manage && (
            <Button size="sm" variant="ghost" onClick={() => { setDraft(String(data.usd_sar)); setEditing(true); setMsg(null) }} data-testid="fx-rate-edit">
              <Pencil className="h-3.5 w-3.5" aria-hidden /> {tx(L.fxEdit)}
            </Button>
          )}
        </>
      )}
      {msg && <span role="status" className="font-semibold">{msg}</span>}
    </div>
  )
}
