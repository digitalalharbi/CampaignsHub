import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Gift, ShieldCheck, Undo2 } from 'lucide-react'
import { createGrant, fetchGrants, revokeGrant, type AccountGrant, type GrantKind } from './api'
import { Button } from '@/components/ui/Button'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'

/**
 * GRANT-001, from the console — giving one account something its plan does not include.
 *
 * The panel is deliberately small and deliberately awkward in one respect: nothing can be granted
 * without a reason typed by the person granting it. That is not a validation nicety. A concession
 * nobody can explain is one nobody dares revoke, and the accounts that quietly keep an exception
 * forever are the ones whose reason was never written down.
 *
 * Revoked grants stay on screen, greyed. They are the record of what this account used to have and
 * who took it back, and hiding them would make that unanswerable a month later.
 */

const KIND_LABEL: Record<GrantKind, { ar: string; en: string }> = {
  section: { ar: 'صلاحية إضافية', en: 'Extra capability' },
  module: { ar: 'خدمة إضافية', en: 'Extra service' },
  plan: { ar: 'اشتراك مجاني', en: 'Complimentary plan' },
  full_access: { ar: 'وصول كامل', en: 'Full access' },
}

export function AccountGrantsPanel({ tenantId, ar }: { tenantId: string; ar: boolean }) {
  const qc = useQueryClient()
  const grants = useQuery({ queryKey: ['admin', 'grants', tenantId], queryFn: () => fetchGrants(tenantId) })

  const [kind, setKind] = useState<GrantKind>('section')
  const [value, setValue] = useState('')
  const [reason, setReason] = useState('')

  const refresh = () => void qc.invalidateQueries({ queryKey: ['admin'] })

  const grant = useMutation({
    mutationFn: () => createGrant(tenantId, {
      kind,
      // `full_access` names nothing in particular, and sending a stale value from another kind's
      // dropdown would record an exception for something the owner did not choose.
      value: kind === 'full_access' ? undefined : value,
      reason,
    }),
    onSuccess: () => { setReason(''); setValue(''); refresh() },
  })

  const revoke = useMutation({
    mutationFn: ({ id, why }: { id: string; why: string }) => revokeGrant(tenantId, id, why),
    onSuccess: refresh,
  })

  const error = grant.isError ? toApiError(grant.error) : revoke.isError ? toApiError(revoke.error) : null

  if (grants.isPending) return <Skeleton className="mt-2 h-24" />
  if (grants.isError || !grants.data) {
    return (
      <ErrorState
        error={grants.error}
        title={ar ? 'تعذّر تحميل المنح.' : 'Grants could not be loaded.'}
        onRetry={() => void grants.refetch()}
      />
    )
  }

  const options = kind === 'section' ? grants.data.catalogue.sections
    : kind === 'module' ? grants.data.catalogue.modules
      : kind === 'plan' ? grants.data.catalogue.plans
        : []

  const canGrant = reason.trim().length >= 3 && (kind === 'full_access' || value !== '')
  const control = 'h-9 w-full rounded-lg border border-border bg-surface px-2.5 text-sm text-text-primary outline-none focus:border-brand-500'

  return (
    <section data-testid="account-grants" className="mt-5">
      <h3 className="text-[12.5px] font-semibold uppercase tracking-wide text-text-muted">
        {ar ? 'منح واستثناءات' : 'Grants and exceptions'}
      </h3>
      <p className="mt-1 text-[12.5px] text-text-muted">
        {ar
          ? 'تُضاف المنحة فوق ما تتيحه الباقة ولا تنقص منه أبدًا، وتسري داخل بوابات هذا الحساب فقط. كل منحة وإلغاء يُسجَّل بالمنفّذ والسبب والتاريخ.'
          : 'A grant is added on top of what the plan already allows and never subtracts from it, and applies only inside this account’s own portals. Every grant and revocation is recorded with the actor, the reason and the date.'}
      </p>

      {error && (
        <p role="alert" className="mt-2 rounded-xl bg-[var(--negative-background)] px-3.5 py-2.5 text-[13px] text-danger">
          {error.message}
        </p>
      )}

      <div className="mt-3 grid gap-2 rounded-xl border border-border bg-surface-secondary p-3 sm:grid-cols-[auto_1fr]">
        <label className="text-[11.5px] font-semibold text-text-muted">
          <span className="mb-1 block">{ar ? 'النوع' : 'Kind'}</span>
          <select
            data-testid="grant-kind" className={`${control} sm:w-40`}
            value={kind}
            onChange={(e) => { setKind(e.target.value as GrantKind); setValue('') }}
          >
            {(Object.keys(KIND_LABEL) as GrantKind[]).map((k) => (
              <option key={k} value={k}>{KIND_LABEL[k][ar ? 'ar' : 'en']}</option>
            ))}
          </select>
        </label>

        <label className="text-[11.5px] font-semibold text-text-muted">
          <span className="mb-1 block">{ar ? 'ماذا' : 'What'}</span>
          {kind === 'full_access' ? (
            <p className="flex h-9 items-center text-[13px] font-normal text-text-secondary">
              {ar
                ? 'كل ما تتيحه بوابات هذا الحساب — ولا شيء من بوابة أخرى.'
                : 'Everything this account’s own portals offer — and nothing from any other.'}
            </p>
          ) : (
            <select data-testid="grant-value" className={control} value={value} onChange={(e) => setValue(e.target.value)}>
              <option value="">{ar ? '— اختر —' : '— choose —'}</option>
              {options.map((o) => <option key={o} value={o}>{o}</option>)}
            </select>
          )}
        </label>

        <label className="text-[11.5px] font-semibold text-text-muted sm:col-span-2">
          <span className="mb-1 block">{ar ? 'السبب (مطلوب)' : 'Reason (required)'}</span>
          <textarea
            data-testid="grant-reason" rows={2}
            className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm font-normal text-text-primary outline-none focus:border-brand-500"
            value={reason} onChange={(e) => setReason(e.target.value)}
            placeholder={ar ? 'لماذا يُمنح هذا الحساب هذا الاستثناء؟' : 'Why is this account being given this exception?'}
          />
        </label>

        <div className="sm:col-span-2">
          <Button
            size="sm" data-testid="grant-submit"
            disabled={!canGrant} loading={grant.isPending}
            onClick={() => grant.mutate()}
          >
            <Gift size={14} /> {ar ? 'منح' : 'Grant'}
          </Button>
        </div>
      </div>

      {grants.data.grants.length === 0 ? (
        <p className="mt-3 text-[13px] text-text-muted">
          {ar ? 'لا منح على هذا الحساب.' : 'This account has no grants.'}
        </p>
      ) : (
        <ul data-testid="grant-list" className="mt-3 grid gap-1.5">
          {grants.data.grants.map((g) => (
            <GrantRow key={g.id} grant={g} ar={ar} busy={revoke.isPending} onRevoke={(why) => revoke.mutate({ id: g.id, why })} />
          ))}
        </ul>
      )}
    </section>
  )
}

function GrantRow({ grant, ar, busy, onRevoke }: {
  grant: AccountGrant
  ar: boolean
  busy: boolean
  onRevoke: (reason: string) => void
}) {
  const [asking, setAsking] = useState(false)
  const [why, setWhy] = useState('')

  const label = KIND_LABEL[grant.kind][ar ? 'ar' : 'en']
  const when = grant.granted_at?.slice(0, 10) ?? '—'

  return (
    <li
      data-testid={`grant-${grant.id}`}
      data-in-force={grant.in_force}
      className={`rounded-lg border px-3 py-2 text-[13px] ${grant.in_force ? 'border-border bg-surface' : 'border-dashed border-border bg-surface-secondary opacity-70'}`}
    >
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="min-w-0">
          <span className="font-semibold text-text-primary">{label}</span>
          {grant.value && <span className="ms-2 text-text-secondary" dir="ltr">{grant.value}</span>}
          <span className="ms-2 tabular-nums text-text-muted" dir="ltr">{when}</span>
          {!grant.in_force && (
            <span className="ms-2 rounded-full bg-surface px-2 py-0.5 text-[11px] font-semibold text-text-muted">
              {ar ? 'مُلغاة' : 'Revoked'}
            </span>
          )}
        </span>

        {grant.in_force && !asking && (
          <Button size="sm" variant="secondary" onClick={() => setAsking(true)}>
            <Undo2 size={13} /> {ar ? 'إلغاء المنحة' : 'Revoke'}
          </Button>
        )}
      </div>

      <p className="mt-1 text-[12.5px] text-text-muted">{grant.reason}</p>
      {grant.revoked_reason && (
        <p className="mt-0.5 flex items-center gap-1.5 text-[12.5px] text-text-muted">
          <ShieldCheck size={12} aria-hidden /> {grant.revoked_reason}
        </p>
      )}

      {/* A revocation states its own reason. It is a different decision from the grant, and inheriting
          the grant's reason would record "given because X" as the explanation for taking it away. */}
      {/* Closed the moment the grant stops being in force — the row survives as a record, but its
          revoke box would be a control with nothing left to do. */}
      {asking && grant.in_force && (
        <div className="mt-2 flex flex-wrap items-end gap-2">
          <input
            data-testid={`grant-revoke-reason-${grant.id}`}
            className="h-9 min-w-0 flex-1 rounded-lg border border-border bg-surface px-2.5 text-sm outline-none focus:border-brand-500"
            value={why} onChange={(e) => setWhy(e.target.value)}
            placeholder={ar ? 'سبب الإلغاء' : 'Why it is being revoked'}
          />
          <Button
            size="sm" data-testid={`grant-revoke-${grant.id}`}
            disabled={why.trim().length < 3 || busy}
            onClick={() => onRevoke(why.trim())}
          >
            {ar ? 'تأكيد' : 'Confirm'}
          </Button>
          <Button size="sm" variant="secondary" onClick={() => { setAsking(false); setWhy('') }}>
            {ar ? 'تراجع' : 'Cancel'}
          </Button>
        </div>
      )}
    </li>
  )
}
