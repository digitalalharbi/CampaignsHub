import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { adjustWorkspaceCredits, fetchWorkspaceCredits } from './api'
import { Button } from '@/components/ui/Button'
import { Num } from '@/components/ui/Num'
import { toApiError } from '@/lib/api/client'
import { fmtDateTime } from '@/lib/datetime'

const newKey = () => (typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `k-${Date.now()}-${Math.random().toString(36).slice(2)}`)

/**
 * PRICING-USAGE-CREDITS-001 — one workspace's usage balance in its tenant drawer: this month's metered usage, the ledger, and a
 * hand adjustment (add, or take back without going below zero). Each adjustment carries a reason, is audited, and is sent with a
 * key minted once per form so a double click adds once. Nothing here collects money.
 */
export function TenantCreditsPanel({ tenantId, ar }: { tenantId: string; ar: boolean }) {
  const qc = useQueryClient()
  const key = ['admin', 'tenant-credits', tenantId]
  const credits = useQuery({ queryKey: key, queryFn: () => fetchWorkspaceCredits(tenantId) })
  const [amount, setAmount] = useState('')
  const [reason, setReason] = useState('')
  const [idem, setIdem] = useState(newKey)
  const adjust = useMutation({
    mutationFn: () => adjustWorkspaceCredits(tenantId, Number(amount), reason.trim(), idem),
    onSuccess: () => { setAmount(''); setReason(''); setIdem(newKey()); void qc.invalidateQueries({ queryKey: key }) },
  })
  const valid = amount !== '' && amount !== '-' && Number(amount) !== 0 && !Number.isNaN(Number(amount)) && reason.trim().length >= 5

  return (
    <section data-testid="tenant-credits" className="mt-5">
      <h3 className="text-[12px] font-semibold uppercase tracking-wide text-text-muted">{ar ? 'رصيد الاستهلاك' : 'Usage balance'}</h3>
      {credits.data && (
        <>
          <p className="mt-2 text-sm text-text-primary">
            <span data-testid="tenant-credits-balance" className="font-semibold"><Num>{credits.data.balance}</Num> {credits.data.currency}</span>
          </p>
          {credits.data.seats && (
            <p data-testid="tenant-credits-seats" className="mt-1 text-[13px] text-text-secondary">
              {ar ? 'مقاعد الحسابات الإعلانية:' : 'Ad-account seats:'} <Num>{credits.data.seats.active}</Num>
              {credits.data.seats.cap !== null && <> / <Num>{credits.data.seats.cap}</Num></>}
              {credits.data.seats.replacements_allowed !== null && (
                <span className="ms-2 text-[12px] text-text-muted">
                  {ar ? 'استبدالات 30 يومًا' : 'replacements (30d)'} <Num>{credits.data.seats.replacements_used}</Num>/<Num>{credits.data.seats.replacements_allowed}</Num>
                </span>
              )}
            </p>
          )}
          <ul className="mt-2 grid gap-1 text-[13px]">
            {credits.data.meters.map((m) => (
              <li key={m.code} data-testid={`tenant-credits-meter-${m.code}`} className="flex flex-wrap gap-2 rounded-lg bg-surface-secondary px-3 py-1.5">
                <span className="text-text-primary">{ar ? m.name_ar : m.name}</span>
                <span className="ms-auto text-text-secondary">
                  <Num>{m.used.toLocaleString('en-US')}</Num>{m.included !== null && <> / <Num>{m.included.toLocaleString('en-US')}</Num></>}
                  {m.funded_by === 'customer' && <span className="ms-2 text-[11px] text-text-muted">{ar ? 'على مفتاح العميل' : "on the customer's key"}</span>}
                </span>
              </li>
            ))}
          </ul>
          {credits.data.transactions.length > 0 && (
            <ul className="mt-2 grid gap-1 text-[12px] text-text-secondary">
              {credits.data.transactions.slice(0, 8).map((t) => (
                <li key={t.id} data-testid={`tenant-credits-tx-${t.id}`} className="flex flex-wrap gap-2">
                  <span className="text-text-primary"><Num>{t.amount}</Num></span>
                  <span>→ <Num>{t.balance_after}</Num></span>
                  <span className="min-w-0 flex-1 truncate">{t.reason}</span>
                  <span className="text-text-muted">{t.at ? fmtDateTime(t.at) : ''}</span>
                </li>
              ))}
            </ul>
          )}
        </>
      )}
      <div className="mt-2 flex flex-wrap items-center gap-2">
        <input data-testid="tenant-credits-amount" inputMode="decimal" dir="ltr" value={amount}
          onChange={(e) => setAmount(e.target.value.replace(/[^\d.-]/g, ''))}
          aria-label={ar ? 'المبلغ (سالب للاسترجاع)' : 'Amount (negative to take back)'} placeholder={ar ? 'مثال 50 أو -10' : 'e.g. 50 or -10'}
          className="h-9 w-28 rounded-lg border border-border bg-surface px-2 text-sm" />
        <input data-testid="tenant-credits-reason" value={reason} onChange={(e) => setReason(e.target.value)} placeholder={ar ? 'السبب' : 'Reason'}
          className="h-9 min-w-0 flex-1 rounded-lg border border-border bg-surface px-2 text-sm" />
        <Button data-testid="tenant-credits-submit" size="sm" variant="secondary" disabled={!valid || adjust.isPending} onClick={() => adjust.mutate()}>
          {ar ? 'تعديل الرصيد' : 'Adjust balance'}
        </Button>
      </div>
      {adjust.isError && <p className="mt-1 text-xs font-semibold text-danger">{toApiError(adjust.error).message}</p>}
    </section>
  )
}
