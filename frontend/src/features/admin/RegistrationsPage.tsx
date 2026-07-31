import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Clock, CreditCard, Loader2, MailQuestion, ShieldAlert, X } from 'lucide-react'
import {
  approveRegistration, fetchRegistration, fetchRegistrations, rejectRegistration,
  requestRegistrationInfo, updateRegistrationTerms,
  type AdminRegistration,
} from './api'
import { Button } from '@/components/ui/Button'
import { TextInput } from '@/components/ui/form'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

/**
 * The registration review queue (SIGNUP-003).
 *
 * The console half of the gated path. What it deliberately does NOT have is an "activate" button:
 * approving clears the approval gate, and the server decides what happens next — an application that
 * also owes money comes back as `approved_awaiting_payment` and stays there. Putting activation on
 * this screen would make unpaid access one click away, which is the exact outcome the gate exists to
 * prevent.
 */

const COPY = {
  ar: {
    title: 'طلبات التسجيل',
    subtitle: 'الطلبات التي لم تصبح مساحات عمل بعد. الاعتماد يفتح بوابة المراجعة فقط، ولا يفعّل حسابًا.',
    open: 'قيد المعالجة', all: 'الكل',
    search: 'بحث بالبريد أو اسم المؤسسة',
    empty: 'لا توجد طلبات مطابقة.',
    applicant: 'مقدّم الطلب', workspace: 'المؤسسة', state: 'الحالة', plan: 'الباقة', applied: 'تاريخ الطلب',
    approve: 'اعتماد', reject: 'رفض', requestInfo: 'طلب معلومات', terms: 'تعديل الشروط',
    reasonLabel: 'السبب (يُعرض على مقدّم الطلب)',
    noteLabel: 'ما المطلوب من مقدّم الطلب؟',
    termsReason: 'سبب التعديل (يُحفظ في السجل)',
    planCode: 'رمز الباقة', discount: 'خصم %', trialDays: 'أيام التجربة',
    gates: 'البوابات المطلوبة',
    gateMobile: 'تأكيد الجوال', gateApproval: 'مراجعة يدوية', gatePayment: 'دفع مسبق',
    history: 'سجل القرارات',
    save: 'حفظ', cancel: 'إلغاء',
    infoRequested: 'بانتظار رد مقدّم الطلب',
    verifiedEmail: 'بريد مؤكد', verifiedMobile: 'جوال مؤكد',
    selectOne: 'اختر طلبًا لعرض تفاصيله.',
  },
  en: {
    title: 'Registration requests',
    subtitle: 'Applications that have not become workspaces. Approving clears the review gate; it does not activate an account.',
    open: 'Open', all: 'All',
    search: 'Search by email or organisation',
    empty: 'No matching applications.',
    applicant: 'Applicant', workspace: 'Organisation', state: 'State', plan: 'Plan', applied: 'Applied',
    approve: 'Approve', reject: 'Reject', requestInfo: 'Request information', terms: 'Change terms',
    reasonLabel: 'Reason (shown to the applicant)',
    noteLabel: 'What do you need from the applicant?',
    termsReason: 'Why (kept in the record)',
    planCode: 'Plan code', discount: 'Discount %', trialDays: 'Trial days',
    gates: 'Required gates',
    gateMobile: 'Mobile confirmation', gateApproval: 'Manual review', gatePayment: 'Payment up front',
    history: 'Decision history',
    save: 'Save', cancel: 'Cancel',
    infoRequested: 'Waiting on the applicant',
    verifiedEmail: 'Email confirmed', verifiedMobile: 'Mobile confirmed',
    selectOne: 'Select an application to see its detail.',
  },
} as const

const STATE_ICON: Record<string, typeof Clock> = {
  email_verification_required: MailQuestion,
  mobile_verification_required: MailQuestion,
  pending_approval: Clock,
  approved_awaiting_payment: CreditCard,
  payment_pending: Clock,
  rejected: ShieldAlert,
}

export function RegistrationsPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const c = COPY[ar ? 'ar' : 'en']
  const queryClient = useQueryClient()

  const [state, setState] = useState<'open' | 'all'>('open')
  const [q, setQ] = useState('')
  const [selected, setSelected] = useState<string | null>(null)

  const list = useQuery({
    queryKey: ['admin', 'registrations', state, q],
    queryFn: () => fetchRegistrations({ state, q: q || undefined }),
  })

  return (
    <div data-testid="admin-registrations" className="flex flex-col gap-5">
      <header>
        <h1 className="font-heading text-2xl font-extrabold text-text-primary">{c.title}</h1>
        <p className="mt-1 max-w-2xl text-sm text-text-secondary">{c.subtitle}</p>
      </header>

      <div className="flex flex-wrap items-center gap-3">
        <div className="flex rounded-xl border border-border p-0.5">
          {(['open', 'all'] as const).map((k) => (
            <button
              key={k}
              data-testid={`registrations-filter-${k}`}
              onClick={() => setState(k)}
              aria-pressed={state === k}
              className={`rounded-lg px-3.5 py-1.5 text-sm font-semibold ${state === k ? 'bg-brand-primary-soft text-brand-700' : 'text-text-secondary hover:text-text-primary'}`}
            >
              {c[k]}
            </button>
          ))}
        </div>
        <input
          data-testid="registrations-search"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder={c.search}
          className="h-10 min-w-[16rem] flex-1 rounded-xl border border-border bg-surface px-3.5 text-sm text-text-primary"
        />
      </div>

      {/* The queue's own headline: how many applications sit at each gate right now. */}
      {list.data && (
        <div data-testid="registrations-counts" className="flex flex-wrap gap-2">
          {Object.entries(list.data.counts).map(([k, n]) => (
            <span key={k} data-testid={`registrations-count-${k}`} className="rounded-lg bg-surface-secondary px-3 py-1.5 text-xs font-semibold text-text-secondary">
              {k} · {n}
            </span>
          ))}
        </div>
      )}

      <div className="grid gap-4 lg:grid-cols-[1.2fr_1fr]">
        <div className="overflow-x-auto rounded-2xl border border-border bg-surface">
          <table className="w-full min-w-[36rem] text-sm">
            <thead className="border-b border-border text-start text-xs text-text-muted">
              <tr>
                <th className="p-3 text-start font-semibold">{c.applicant}</th>
                <th className="p-3 text-start font-semibold">{c.workspace}</th>
                <th className="p-3 text-start font-semibold">{c.state}</th>
                <th className="p-3 text-start font-semibold">{c.plan}</th>
              </tr>
            </thead>
            <tbody>
              {list.isPending && (
                <tr><td colSpan={4} className="p-8 text-center"><Loader2 className="mx-auto animate-spin text-brand-600" /></td></tr>
              )}
              {list.data?.registrations.length === 0 && (
                <tr><td colSpan={4} data-testid="registrations-empty" className="p-8 text-center text-text-secondary">{c.empty}</td></tr>
              )}
              {list.data?.registrations.map((r) => {
                const Icon = STATE_ICON[r.state] ?? Clock
                return (
                  <tr
                    key={r.id}
                    data-testid="registration-row"
                    data-state={r.state}
                    onClick={() => setSelected(r.id)}
                    className={`cursor-pointer border-b border-border last:border-0 hover:bg-surface-hover ${selected === r.id ? 'bg-brand-primary-soft/40' : ''}`}
                  >
                    <td className="p-3">
                      <span className="block font-semibold text-text-primary">{r.name}</span>
                      <span className="block text-xs text-text-muted" dir="ltr">{r.email}</span>
                    </td>
                    <td className="p-3 text-text-secondary">{r.tenant_name}</td>
                    <td className="p-3">
                      <span className="inline-flex items-center gap-1.5 text-text-secondary">
                        <Icon size={14} className="shrink-0" /> {r.label}
                      </span>
                      {r.info_requested && (
                        <span data-testid="registration-info-requested" className="mt-1 block text-xs font-semibold text-brand-600">{c.infoRequested}</span>
                      )}
                    </td>
                    <td className="p-3 text-text-secondary">{r.plan_code ?? '—'}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>

        {selected
          ? <RegistrationDetail id={selected} copy={c} onChanged={() => void queryClient.invalidateQueries({ queryKey: ['admin', 'registrations'] })} />
          : <p className="rounded-2xl border border-border bg-surface p-6 text-sm text-text-secondary">{c.selectOne}</p>}
      </div>
    </div>
  )
}

type Copy = typeof COPY['en'] | typeof COPY['ar']

function RegistrationDetail({ id, copy, onChanged }: { id: string; copy: Copy; onChanged: () => void }) {
  const queryClient = useQueryClient()
  const [error, setError] = useState<string | null>(null)
  const [reason, setReason] = useState('')
  const [note, setNote] = useState('')
  const [terms, setTerms] = useState({ plan_code: '', discount_percent: '', trial_days: '', reason: '' })
  const [open, setOpen] = useState<'reject' | 'info' | 'terms' | null>(null)

  const detail = useQuery({ queryKey: ['admin', 'registration', id], queryFn: () => fetchRegistration(id) })

  const after = (registration: { registration: AdminRegistration }) => {
    void queryClient.invalidateQueries({ queryKey: ['admin', 'registration', id] })
    onChanged()
    setOpen(null)
    setError(null)
    return registration
  }
  const failed = (e: unknown) => setError(toApiError(e).message)

  const approve = useMutation({ mutationFn: () => approveRegistration(id), onSuccess: after, onError: failed })
  const reject = useMutation({ mutationFn: () => rejectRegistration(id, reason), onSuccess: after, onError: failed })
  const info = useMutation({ mutationFn: () => requestRegistrationInfo(id, note), onSuccess: after, onError: failed })
  const saveTerms = useMutation({
    mutationFn: () => updateRegistrationTerms(id, {
      plan_code: terms.plan_code || undefined,
      discount_percent: terms.discount_percent === '' ? undefined : Number(terms.discount_percent),
      trial_days: terms.trial_days === '' ? undefined : Number(terms.trial_days),
      // A gate is waived from the detail's own toggles below; this form carries the commercial terms.
      reason: terms.reason,
    }),
    onSuccess: (r) => { void queryClient.invalidateQueries({ queryKey: ['admin', 'registration', id] }); onChanged(); setOpen(null); setError(null); return r },
    onError: failed,
  })
  const toggleGate = useMutation({
    mutationFn: (change: { gate: 'requires_mobile' | 'requires_approval' | 'requires_payment'; value: boolean; why: string }) =>
      updateRegistrationTerms(id, { [change.gate]: change.value, reason: change.why }),
    onSuccess: (r) => { void queryClient.invalidateQueries({ queryKey: ['admin', 'registration', id] }); onChanged(); setError(null); return r },
    onError: failed,
  })

  if (!detail.data) {
    return <div className="flex justify-center rounded-2xl border border-border bg-surface p-8"><Loader2 className="animate-spin text-brand-600" /></div>
  }

  const { registration: r, policy, transitions } = detail.data
  const settled = r.provisioned || ['rejected', 'cancelled', 'expired'].includes(r.state)

  const GATES = [
    { key: 'requires_mobile', label: copy.gateMobile, on: policy.requires_mobile },
    { key: 'requires_approval', label: copy.gateApproval, on: policy.requires_approval },
    { key: 'requires_payment', label: copy.gatePayment, on: policy.requires_payment },
  ] as const

  return (
    <div data-testid="registration-detail" data-state={r.state} className="flex flex-col gap-4 rounded-2xl border border-border bg-surface p-5">
      <div>
        <p className="font-semibold text-text-primary">{r.tenant_name}</p>
        <p className="text-sm text-text-secondary" dir="ltr">{r.email}{r.phone ? ` · ${r.phone}` : ''}</p>
        <p className="mt-1 text-sm font-semibold text-brand-600">{r.label}</p>
      </div>

      <div className="flex flex-wrap gap-2 text-xs">
        <span data-done={r.email_verified} className={`rounded-lg px-2.5 py-1 font-semibold ${r.email_verified ? 'bg-[var(--positive-background)] text-[var(--positive-foreground)]' : 'bg-surface-secondary text-text-muted'}`}>{copy.verifiedEmail}</span>
        <span data-done={r.mobile_verified} className={`rounded-lg px-2.5 py-1 font-semibold ${r.mobile_verified ? 'bg-[var(--positive-background)] text-[var(--positive-foreground)]' : 'bg-surface-secondary text-text-muted'}`}>{copy.verifiedMobile}</span>
      </div>

      {/* Which gates THIS application must clear — the plan's answer, or a reviewer's override of it. */}
      <div>
        <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-text-muted">{copy.gates}</p>
        <div className="flex flex-col gap-1.5">
          {GATES.map((g) => (
            <label key={g.key} className="flex items-center gap-2 text-sm text-text-secondary">
              <input
                type="checkbox"
                data-testid={`registration-gate-${g.key}`}
                checked={g.on}
                disabled={settled || toggleGate.isPending}
                onChange={(e) => {
                  const why = window.prompt(copy.termsReason) ?? ''
                  // No reason, no change: a waived gate without a justification is a hole in the record.
                  if (why.trim() === '') return
                  toggleGate.mutate({ gate: g.key, value: e.target.checked, why })
                }}
              />
              {g.label}
            </label>
          ))}
        </div>
      </div>

      {!settled && (
        <div className="flex flex-wrap gap-2">
          <Button data-testid="registration-approve" onClick={() => approve.mutate()} loading={approve.isPending} size="sm">
            <Check size={15} /> {copy.approve}
          </Button>
          <button data-testid="registration-reject-open" onClick={() => setOpen('reject')} className="flex items-center gap-1.5 rounded-xl border border-border px-3.5 py-2 text-sm font-semibold text-danger hover:bg-[var(--negative-background)]">
            <X size={15} /> {copy.reject}
          </button>
          <button data-testid="registration-info-open" onClick={() => setOpen('info')} className="rounded-xl border border-border px-3.5 py-2 text-sm font-semibold text-text-secondary hover:text-text-primary">
            {copy.requestInfo}
          </button>
          <button data-testid="registration-terms-open" onClick={() => setOpen('terms')} className="rounded-xl border border-border px-3.5 py-2 text-sm font-semibold text-text-secondary hover:text-text-primary">
            {copy.terms}
          </button>
        </div>
      )}

      {/* A refusal the applicant is shown needs words: a blank reason never leaves the browser. */}
      {open === 'reject' && (
        <form className="flex flex-col gap-2" onSubmit={(e) => { e.preventDefault(); if (reason.trim()) reject.mutate() }}>
          <TextInput id="reject-reason" label={copy.reasonLabel} value={reason} onChange={(e) => setReason(e.target.value)} required />
          <div className="flex gap-2">
            <Button data-testid="registration-reject-confirm" type="submit" loading={reject.isPending} size="sm">{copy.save}</Button>
            <button type="button" onClick={() => setOpen(null)} className="text-sm text-text-secondary">{copy.cancel}</button>
          </div>
        </form>
      )}

      {open === 'info' && (
        <form className="flex flex-col gap-2" onSubmit={(e) => { e.preventDefault(); if (note.trim()) info.mutate() }}>
          <TextInput id="info-note" label={copy.noteLabel} value={note} onChange={(e) => setNote(e.target.value)} required />
          <div className="flex gap-2">
            <Button data-testid="registration-info-confirm" type="submit" loading={info.isPending} size="sm">{copy.save}</Button>
            <button type="button" onClick={() => setOpen(null)} className="text-sm text-text-secondary">{copy.cancel}</button>
          </div>
        </form>
      )}

      {open === 'terms' && (
        <form className="flex flex-col gap-2" onSubmit={(e) => { e.preventDefault(); if (terms.reason.trim()) saveTerms.mutate() }}>
          <TextInput id="terms-plan" label={copy.planCode} value={terms.plan_code} onChange={(e) => setTerms((t) => ({ ...t, plan_code: e.target.value }))} />
          <div className="grid grid-cols-2 gap-2">
            <TextInput id="terms-discount" label={copy.discount} value={terms.discount_percent} onChange={(e) => setTerms((t) => ({ ...t, discount_percent: e.target.value }))} inputMode="numeric" />
            <TextInput id="terms-trial" label={copy.trialDays} value={terms.trial_days} onChange={(e) => setTerms((t) => ({ ...t, trial_days: e.target.value }))} inputMode="numeric" />
          </div>
          <TextInput id="terms-reason" label={copy.termsReason} value={terms.reason} onChange={(e) => setTerms((t) => ({ ...t, reason: e.target.value }))} required />
          <div className="flex gap-2">
            <Button data-testid="registration-terms-confirm" type="submit" loading={saveTerms.isPending} size="sm">{copy.save}</Button>
            <button type="button" onClick={() => setOpen(null)} className="text-sm text-text-secondary">{copy.cancel}</button>
          </div>
        </form>
      )}

      {error && <p data-testid="registration-detail-error" className="rounded-xl bg-[var(--negative-background)] px-3.5 py-2.5 text-sm text-danger">{error}</p>}

      {/* Every recorded decision, in order — the audit trail read back, not a second log. */}
      <div>
        <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-text-muted">{copy.history}</p>
        <ol data-testid="registration-history" className="flex flex-col gap-1.5 text-xs text-text-secondary">
          {transitions.map((t, i) => (
            <li key={`${t.action}-${i}`} className="flex flex-wrap gap-1.5">
              <span className="font-semibold text-text-primary">{t.action}</span>
              <span dir="ltr">{t.at}</span>
              {t.reason && <span className="text-text-muted">— {t.reason}</span>}
            </li>
          ))}
        </ol>
      </div>
    </div>
  )
}
