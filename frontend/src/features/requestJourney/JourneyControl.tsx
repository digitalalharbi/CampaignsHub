import { useEffect, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { ArrowRight, Check, CircleDot, GitBranch } from 'lucide-react'
import { useUi } from '@/stores/ui'
import { useAuth } from '@/stores/auth'
import { toApiError } from '@/lib/api/client'
import {
  allowedNext, isOfframp, JOURNEY_TIMELINE, paymentStatusForStage, stageLabel, transitionJourney,
  type RequestStage,
} from './api'

const COPY = {
  ar: {
    title: 'مسار الطلب', current: 'المرحلة الحالية', payment: 'حالة الدفع', none_payment: 'لا شيء',
    next: 'الانتقالات المتاحة', terminal: 'مرحلة نهائية — لا انتقالات إضافية.', reason: 'سبب (اختياري)',
    reason_ph: 'سبب الانتقال…', moving: 'جارٍ النقل…', offramp: 'مسار جانبي',
    no_perm: 'تحتاج صلاحية تغيير الحالة لتنفيذ الانتقالات.', timeline: 'الخط الزمني',
  },
  en: {
    title: 'Request journey', current: 'Current stage', payment: 'Payment status', none_payment: 'None',
    next: 'Available transitions', terminal: 'Terminal stage — no further transitions.', reason: 'Reason (optional)',
    reason_ph: 'Reason for the transition…', moving: 'Moving…', offramp: 'Off-ramp',
    no_perm: 'You need the change-status permission to run transitions.', timeline: 'Timeline',
  },
}

export interface JourneyControlProps {
  requestId: string
  /** The request's current journey stage. */
  currentStage: RequestStage | string
  /** Optional current payment status (from the request), shown alongside the stage. */
  paymentStatus?: string | null
  /** Called after a successful transition with the new stage + payment status. */
  onTransitioned?: (result: { journey_stage: string; payment_status: string | null }) => void
}

/**
 * Staff control to advance one request through the journey state machine. Only valid next stages are
 * enabled (the map mirrors the backend enum); the backend re-validates and a rejected move is surfaced.
 */
export function JourneyControl({ requestId, currentStage, paymentStatus, onTransitioned }: JourneyControlProps) {
  const ar = useUi((s) => s.locale) === 'ar'
  const c = ar ? COPY.ar : COPY.en
  const canChange = useAuth((s) => s.hasPermission('requests.change_status'))

  const [stage, setStage] = useState<string>(currentStage)
  const [payment, setPayment] = useState<string | null>(paymentStatus ?? null)
  const [reason, setReason] = useState('')

  // Keep in sync if the parent supplies a new request/stage.
  useEffect(() => { setStage(currentStage); setPayment(paymentStatus ?? null) }, [currentStage, paymentStatus, requestId])

  const moveM = useMutation({
    mutationFn: (to: RequestStage) => transitionJourney(requestId, to, reason.trim() || undefined),
    onSuccess: (res) => {
      setStage(res.journey_stage)
      setPayment(res.payment_status)
      setReason('')
      onTransitioned?.(res)
    },
  })

  const nexts = allowedNext(stage)
  const errorMessage = moveM.isError ? toApiError(moveM.error).errors?.stage?.[0] ?? toApiError(moveM.error).message : null
  const effectivePayment = payment ?? paymentStatusForStage(stage)

  return (
    <div className="flex flex-col gap-4 rounded-2xl border border-border bg-surface p-4">
      <header className="flex items-center gap-2">
        <GitBranch size={16} className="text-brand-600" />
        <h3 className="text-sm font-bold text-text-primary">{c.title}</h3>
      </header>

      <div className="flex flex-wrap items-center gap-4">
        <div className="flex flex-col gap-1">
          <span className="text-[11px] font-semibold text-text-tertiary">{c.current}</span>
          <span className={`w-fit rounded-full px-2.5 py-1 text-xs font-bold ${isOfframp(stage) ? 'bg-danger/15 text-danger' : 'bg-brand-600/15 text-brand-600'}`}>
            {stageLabel(stage, ar)}
          </span>
        </div>
        <div className="flex flex-col gap-1">
          <span className="text-[11px] font-semibold text-text-tertiary">{c.payment}</span>
          <span className="w-fit rounded-full bg-surface-hover px-2.5 py-1 text-xs font-semibold text-text-secondary" dir="ltr">
            {effectivePayment ?? c.none_payment}
          </span>
        </div>
      </div>

      <Timeline current={stage} ar={ar} label={c.timeline} />

      <div className="flex flex-col gap-2 border-t border-border pt-3">
        <span className="text-xs font-semibold text-text-secondary">{c.next}</span>
        {nexts.length === 0 ? (
          <p className="text-sm text-text-tertiary">{c.terminal}</p>
        ) : !canChange ? (
          <p className="text-xs text-text-tertiary">{c.no_perm}</p>
        ) : (
          <>
            <input
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              maxLength={500}
              placeholder={c.reason_ph}
              aria-label={c.reason}
              className="rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm text-text-primary"
            />
            <div className="flex flex-wrap gap-2">
              {nexts.map((to) => {
                const off = isOfframp(to)
                return (
                  <button
                    key={to}
                    onClick={() => moveM.mutate(to)}
                    disabled={moveM.isPending}
                    className={`flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-semibold disabled:opacity-50 ${
                      off
                        ? 'border-danger/40 text-danger hover:bg-danger/10'
                        : 'border-brand-500 text-brand-600 hover:bg-brand-600/10'
                    }`}
                  >
                    <ArrowRight size={13} className="rtl:rotate-180" />
                    {stageLabel(to, ar)}
                    {off ? <span className="text-[10px] opacity-70">({c.offramp})</span> : null}
                  </button>
                )
              })}
            </div>
          </>
        )}
        {errorMessage ? <p className="rounded-lg bg-danger/10 px-2.5 py-1.5 text-xs text-danger">{errorMessage}</p> : null}
      </div>
    </div>
  )
}

function Timeline({ current, ar, label }: { current: string; ar: boolean; label: string }) {
  const currentIndex = JOURNEY_TIMELINE.indexOf(current as RequestStage)
  const offramp = currentIndex < 0
  return (
    <div className="flex flex-col gap-2">
      <span className="text-[11px] font-semibold text-text-tertiary">{label}</span>
      <ol className="flex flex-wrap gap-1.5">
        {JOURNEY_TIMELINE.map((s, i) => {
          const done = !offramp && i < currentIndex
          const isCurrent = !offramp && i === currentIndex
          return (
            <li
              key={s}
              className={`flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                isCurrent
                  ? 'bg-brand-600 text-white'
                  : done
                    ? 'bg-success/15 text-success'
                    : 'bg-surface-hover text-text-tertiary'
              }`}
            >
              {isCurrent ? <CircleDot size={11} /> : done ? <Check size={11} /> : null}
              {stageLabel(s, ar)}
            </li>
          )
        })}
      </ol>
    </div>
  )
}
