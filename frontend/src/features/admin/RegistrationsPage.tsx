import { useState } from 'react'
import { promptDialog } from '@/stores/dialogs'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Clock, CreditCard, Loader2, MailQuestion, Send, ShieldAlert, X } from 'lucide-react'
import {
  decideRegistration, fetchRegistration, fetchRegistrations, reevaluateRegistrationVerification, rejectRegistration,
  requestRegistrationInfo, resendRegistrationVerification, updateRegistrationTerms,
  type AdminRegistration,
} from './api'
import { RegistrationDecisionForm } from './RegistrationDecisionForm'
import { accountStateLabel } from './labels'
import { Button } from '@/components/ui/Button'
import { TextInput } from '@/components/ui/form'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'
import { Num } from '@/components/ui/Num'
import { fmtDateTime } from '@/lib/datetime'

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
    subtitle: 'الطلبات التي لم تصبح مساحات عمل بعد. الاعتماد وحده لا يفعّل حسابًا: التفعيل يأتي بعد دفع مؤكد أو قرار تجاري صريح.',
    open: 'قيد المعالجة', all: 'الكل',
    search: 'بحث بالبريد أو اسم المؤسسة',
    empty: 'لا توجد طلبات مطابقة.',
    applicant: 'مقدّم الطلب', workspace: 'المؤسسة', state: 'الحالة', plan: 'الباقة', applied: 'تاريخ الطلب',
    reject: 'رفض', requestInfo: 'طلب معلومات', terms: 'تعديل الشروط',
    reasonLabel: 'السبب (يُعرض على مقدّم الطلب)',
    noteLabel: 'ما المطلوب من مقدّم الطلب؟',
    termsReason: 'سبب التعديل (يُحفظ في السجل)',
    planCode: 'رمز الباقة',
    gates: 'البوابات المطلوبة',
    gateMobile: 'تأكيد الجوال', gateApproval: 'مراجعة يدوية',
    history: 'سجل القرارات',
    save: 'حفظ', cancel: 'إلغاء',
    infoRequested: 'بانتظار رد مقدّم الطلب',
    verifiedEmail: 'بريد مؤكد', verifiedMobile: 'جوال مؤكد',
    notRequiredEmail: 'البريد غير مؤكد: لا تطلبه سياسة هذا الطلب', notRequiredMobile: 'الجوال غير مؤكد: لا تطلبه سياسة هذا الطلب',
    defer: 'تأجيل القرار',
    reevaluate: 'إعادة تقييم التحقق', reevaluateHint: 'يطبق سياسة التحقق الحالية على هذا الطلب فقط. لا يغيّر الدفع أو الاعتماد ولا يؤكد شيئًا.',
    selectOne: 'اختر طلبًا لعرض تفاصيله.',
    pendingEmail: 'البريد لم يتأكد بعد', pendingMobile: 'الجوال لم يتأكد بعد',
    stuckAt: 'متوقف عند', stageEmail: 'تأكيد البريد', stageMobile: 'تأكيد الجوال', since: 'منذ', sends: 'مرات الإرسال', lastOutcome: 'آخر نتيجة',
    resend: 'أعد الإرسال', resendHint: 'يرسل رمزًا جديدًا لصاحب الطلب. ما يأكّد شي ولا يفتح حساب.', resent: 'انطلب الإرسال',
  },
  en: {
    title: 'Registration requests',
    subtitle: 'Applications that have not become workspaces. Approval alone activates nothing: that takes a confirmed payment or an explicit commercial decision.',
    open: 'Open', all: 'All',
    search: 'Search by email or organisation',
    empty: 'No matching applications.',
    applicant: 'Applicant', workspace: 'Organisation', state: 'State', plan: 'Plan', applied: 'Applied',
    reject: 'Reject', requestInfo: 'Request information', terms: 'Change terms',
    reasonLabel: 'Reason (shown to the applicant)',
    noteLabel: 'What do you need from the applicant?',
    termsReason: 'Why (kept in the record)',
    planCode: 'Plan code',
    gates: 'Required gates',
    gateMobile: 'Mobile confirmation', gateApproval: 'Manual review',
    history: 'Decision history',
    save: 'Save', cancel: 'Cancel',
    infoRequested: 'Waiting on the applicant',
    verifiedEmail: 'Email confirmed', verifiedMobile: 'Mobile confirmed',
    notRequiredEmail: 'Email unverified: not required for this application', notRequiredMobile: 'Mobile unverified: not required for this application',
    defer: 'Defer',
    reevaluate: 'Re-evaluate verification', reevaluateHint: 'Applies the current verification policy to this application only. It changes no payment or approval, and proves nothing.',
    selectOne: 'Select an application to see its detail.',
    pendingEmail: 'Email not confirmed yet', pendingMobile: 'Mobile not confirmed yet',
    stuckAt: 'Held at', stageEmail: 'Email confirmation', stageMobile: 'Mobile confirmation', since: 'Since', sends: 'Sends', lastOutcome: 'Last outcome',
    resend: 'Resend', resendHint: 'Sends the applicant a fresh code. It confirms nothing and opens no account.', resent: 'Resend requested',
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

/** PLATFORM-INBOX-ATTENTION-001 — what the last send did, in words. An unknown outcome is shown as it is. */
const OUTCOME: Record<string, { ar: string; en: string }> = {
  sent: { ar: 'انرسل', en: 'Sent' },
  queued: { ar: 'بالطابور', en: 'Queued' },
  pending: { ar: 'عند المزوّد', en: 'With the provider' },
  failed: { ar: 'فشل الإرسال', en: 'Failed' },
  awaiting_provider_credentials: { ar: 'مزوّد الإرسال غير مفعّل', en: 'Provider not set up' },
  blocked_by_send_limit: { ar: 'بلغ حد الإرسال', en: 'Send limit reached' },
  blocked_global_cap: { ar: 'بلغ الحد اليومي للمنصة', en: 'Platform daily cap reached' },
  blocked_application_cap: { ar: 'بلغ حد هذا الطلب', en: 'This application\'s cap reached' },
  reserved: { ar: 'قيد الإرسال', en: 'Sending' },
}
const outcomeWord = (key: string, ar: boolean) => (OUTCOME[key] ? (ar ? OUTCOME[key].ar : OUTCOME[key].en) : key)

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
            <span key={k} data-testid={`registrations-count-${k}`} data-state={k} className="rounded-lg bg-surface-secondary px-3 py-1.5 text-xs font-semibold text-text-secondary">
              {accountStateLabel(k, ar)} · <Num>{n}</Num>
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
                      <span className="text-start block text-xs text-text-muted"><Num>{r.email}</Num></span>
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
          ? <RegistrationDetail key={selected} id={selected} ar={ar} copy={c} onChanged={() => void queryClient.invalidateQueries({ queryKey: ['admin', 'registrations'] })} />
          : <p className="rounded-2xl border border-border bg-surface p-6 text-sm text-text-secondary">{c.selectOne}</p>}
      </div>
    </div>
  )
}

type Copy = typeof COPY['en'] | typeof COPY['ar']

function RegistrationDetail({ id, ar, copy, onChanged }: { id: string; ar: boolean; copy: Copy; onChanged: () => void }) {
  const queryClient = useQueryClient()
  const [error, setError] = useState<string | null>(null)
  const [reason, setReason] = useState('')
  const [note, setNote] = useState('')
  const [terms, setTerms] = useState({ plan_code: '', reason: '' })
  const [why, setWhy] = useState('')
  const [deferWhy, setDeferWhy] = useState('')
  const [open, setOpen] = useState<'reject' | 'info' | 'terms' | 'reevaluate' | 'defer' | null>(null)

  const detail = useQuery({ queryKey: ['admin', 'registration', id], queryFn: () => fetchRegistration(id) })

  const after = (registration: { registration: AdminRegistration }) => {
    void queryClient.invalidateQueries({ queryKey: ['admin', 'registration', id] })
    onChanged()
    setOpen(null)
    setError(null)
    return registration
  }
  const failed = (e: unknown) => setError(toApiError(e).message)

  const defer = useMutation({ mutationFn: () => decideRegistration(id, { outcome: 'defer', reason: deferWhy }), onSuccess: after, onError: failed })
  const reject = useMutation({ mutationFn: () => rejectRegistration(id, reason), onSuccess: after, onError: failed })
  const info = useMutation({ mutationFn: () => requestRegistrationInfo(id, note), onSuccess: after, onError: failed })
  const reevaluate = useMutation({ mutationFn: () => reevaluateRegistrationVerification(id, why), onSuccess: after, onError: failed })
  // Its own error line inside the stage block: a cooldown refusal belongs beside the button that was refused.
  const resend = useMutation({ mutationFn: () => resendRegistrationVerification(id), onSuccess: after })
  const saveTerms = useMutation({
    mutationFn: () => updateRegistrationTerms(id, {
      plan_code: terms.plan_code || undefined,
      // Discounts, waivers and trials are a decision with exact terms: see RegistrationDecisionForm.
      reason: terms.reason,
    }),
    onSuccess: (r) => { void queryClient.invalidateQueries({ queryKey: ['admin', 'registration', id] }); onChanged(); setOpen(null); setError(null); return r },
    onError: failed,
  })
  const toggleGate = useMutation({
    mutationFn: (change: { gate: 'requires_mobile' | 'requires_approval'; value: boolean; why: string }) =>
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
  ] as const

  return (
    <div data-testid="registration-detail" data-state={r.state} className="flex flex-col gap-4 rounded-2xl border border-border bg-surface p-5">
      <div>
        <p className="font-semibold text-text-primary">{r.tenant_name}</p>
        <p className="text-sm text-text-secondary" dir="ltr">{r.email}{r.phone ? ` · ${r.phone}` : ''}</p>
        <p className="mt-1 text-sm font-semibold text-brand-600">{r.label}</p>
      </div>

      <div className="flex flex-wrap gap-2 text-xs">
        <span data-testid="registration-standing-email" data-done={r.email_verified} data-standing={r.verification?.email} className={`rounded-lg px-2.5 py-1 font-semibold ${r.email_verified ? 'bg-[var(--positive-background)] text-[var(--positive-foreground)]' : 'bg-surface-secondary text-text-muted'}`}>
          {r.verification?.email === 'not_required_by_policy' ? copy.notRequiredEmail : r.email_verified ? copy.verifiedEmail : copy.pendingEmail}
        </span>
        <span data-testid="registration-standing-mobile" data-done={r.mobile_verified} data-standing={r.verification?.mobile} className={`rounded-lg px-2.5 py-1 font-semibold ${r.mobile_verified ? 'bg-[var(--positive-background)] text-[var(--positive-foreground)]' : 'bg-surface-secondary text-text-muted'}`}>
          {r.verification?.mobile === 'not_required_by_policy' ? copy.notRequiredMobile : r.mobile_verified ? copy.verifiedMobile : copy.pendingMobile}
        </span>
      </div>

      {/* PLATFORM-INBOX-ATTENTION-001 — held at a verification step: which one, since when, what the last send did. */}
      {r.verification_stage && !settled && (
        <div data-testid="registration-stage" data-channel={r.verification_stage.channel} className="rounded-xl border border-border bg-surface-secondary p-3 text-sm">
          <p className="font-semibold text-text-primary">
            {copy.stuckAt}: {r.verification_stage.channel === 'mobile' ? copy.stageMobile : copy.stageEmail}
          </p>
          <dl className="mt-1 grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-0.5 text-xs text-text-secondary">
            <dt>{copy.since}</dt><dd><Num>{fmtDateTime(r.verification_stage.since)}</Num></dd>
            <dt>{copy.sends}</dt><dd><Num>{r.verification_stage.sends}</Num></dd>
            {r.verification_stage.last_outcome && (<><dt>{copy.lastOutcome}</dt><dd data-testid="registration-stage-outcome" data-outcome={r.verification_stage.last_outcome}>{outcomeWord(r.verification_stage.last_outcome, ar)}</dd></>)}
          </dl>
          <div className="mt-2 flex flex-wrap items-center gap-2">
            <Button data-testid="registration-resend" size="sm" loading={resend.isPending} onClick={() => resend.mutate()}>
              <Send size={14} aria-hidden /> {copy.resend}
            </Button>
            <span className="text-xs text-text-muted">{copy.resendHint}</span>
          </div>
          {resend.isSuccess && <p role="status" className="mt-1 text-xs font-semibold text-success">{copy.resent} · {outcomeWord(resend.data.verification.delivery_status, ar)}</p>}
          {resend.isError && (
            <p role="alert" data-testid="registration-resend-error" className="mt-1 text-xs font-semibold text-danger">
              {toApiError(resend.error).errors?.channel?.[0] ?? toApiError(resend.error).message}
            </p>
          )}
        </div>
      )}

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
                  const value = e.target.checked
                  // DIALOG-001 — asked in an in-app dialog. No reason, no change: a waived gate without a
                  // justification is a hole in the record.
                  void promptDialog({ title: g.label, label: copy.termsReason, required: true }).then((why) => {
                    if (why === null || why.trim() === '') return
                    toggleGate.mutate({ gate: g.key, value, why })
                  })
                }}
              />
              {g.label}
            </label>
          ))}
        </div>
      </div>

      {!settled && <RegistrationDecisionForm registration={r} ar={ar} onDecided={() => { void queryClient.invalidateQueries({ queryKey: ['admin', 'registration', id] }); onChanged() }} />}

      {!settled && (
        <div className="flex flex-wrap gap-2">
          <button data-testid="registration-reject-open" onClick={() => setOpen('reject')} className="flex items-center gap-1.5 rounded-xl border border-border px-3.5 py-2 text-sm font-semibold text-danger hover:bg-[var(--negative-background)]">
            <X size={15} /> {copy.reject}
          </button>
          <button data-testid="registration-info-open" onClick={() => setOpen('info')} className="rounded-xl border border-border px-3.5 py-2 text-sm font-semibold text-text-secondary hover:text-text-primary">
            {copy.requestInfo}
          </button>
          <button data-testid="registration-terms-open" onClick={() => setOpen('terms')} className="rounded-xl border border-border px-3.5 py-2 text-sm font-semibold text-text-secondary hover:text-text-primary">
            {copy.terms}
          </button>
          <button data-testid="registration-defer-open" onClick={() => setOpen('defer')} className="rounded-xl border border-border px-3.5 py-2 text-sm font-semibold text-text-secondary hover:text-text-primary">
            {copy.defer}
          </button>
          <button data-testid="registration-reevaluate-open" onClick={() => setOpen('reevaluate')} className="rounded-xl border border-border px-3.5 py-2 text-sm font-semibold text-text-secondary hover:text-text-primary">
            {copy.reevaluate}
          </button>
        </div>
      )}

      {open === 'defer' && (
        <form className="flex flex-col gap-2" onSubmit={(e) => { e.preventDefault(); if (deferWhy.trim().length >= 5) defer.mutate() }}>
          <TextInput id="defer-reason" label={copy.termsReason} value={deferWhy} onChange={(e) => setDeferWhy(e.target.value)} required />
          <div className="flex gap-2">
            <Button data-testid="registration-defer-confirm" type="submit" loading={defer.isPending} size="sm">{copy.save}</Button>
            <button type="button" onClick={() => setOpen(null)} className="text-sm text-text-secondary">{copy.cancel}</button>
          </div>
        </form>
      )}

      {open === 'reevaluate' && (
        <form className="flex flex-col gap-2" onSubmit={(e) => { e.preventDefault(); if (why.trim().length >= 5) reevaluate.mutate() }}>
          <p className="text-xs text-text-secondary">{copy.reevaluateHint}</p>
          <TextInput id="reevaluate-reason" label={copy.termsReason} value={why} onChange={(e) => setWhy(e.target.value)} required />
          <div className="flex gap-2">
            <Button data-testid="registration-reevaluate-confirm" type="submit" loading={reevaluate.isPending} size="sm">{copy.save}</Button>
            <button type="button" onClick={() => setOpen(null)} className="text-sm text-text-secondary">{copy.cancel}</button>
          </div>
        </form>
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
