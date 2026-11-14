import { useEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Check, Eye } from 'lucide-react'
import {
  approveRegistration, decideRegistration, fetchTenants, fetchTenantDecisionTargets, previewRegistrationDecision,
  verifyRegistrationSettlement,
  type AdminRegistration, type RegistrationDecisionInput, type RegistrationDecisionOutcome, type RegistrationDecisionPreview,
} from './api'
import { Button } from '@/components/ui/Button'
import { DateField } from '@/components/ui/DateField'
import { Num } from '@/components/ui/Num'
import { toApiError } from '@/lib/api/client'

/**
 * The one decision form (SIGNUP-DECISION-001).
 *
 * Two independent questions: what is the commercial treatment, and what access results. Anything beyond «approved, payment
 * still owed» is previewed from the server first, because the amount a reviewer gives away and the capabilities a person
 * receives must be read before they are decided. The form sends the decision; the server computes every figure, and only
 * ever compares the amount it is shown here.
 */

const COPY = {
  ar: {
    title: 'القرار',
    outcomes: {
      approve_payment_required: 'اعتماد مع طلب الدفع', approve_discount: 'اعتماد بخصم', approve_waiver: 'اعتماد بإعفاء من رسوم فترة واحدة وتفعيل فوري',
      approve_trial: 'اعتماد بفترة تجربة مؤرخة', approve_external_settlement: 'اعتماد بسداد خارجي موثّق', approve_join_existing: 'ضمّ إلى مساحة عمل قائمة',
      approve_complimentary: 'دخول مباشر دون دفع (بلا تاريخ انتهاء)',
    },
    hints: {
      approve_payment_required: 'لا تُفعَّل مساحة العمل قبل تأكيد الدفع من المزوّد.',
      approve_discount: 'يُفعَّل الحساب بعد تأكيد سداد المبلغ المتبقي بالضبط. الخصم للفترة الأولى فقط.',
      approve_waiver: 'قرار مالي منفصل: لا يُسجل دفع ولا عملية لدى مزوّد ولا فاتورة. فترة واحدة فقط، لا إعفاء دائم.',
      approve_trial: 'نشط كتجربة حتى التاريخ المحدد، ثم يصبح الدفع مستحقًا. لا خصم تلقائي.',
      approve_external_settlement: 'يحتاج دليلًا على عملية سداد حقيقية وتأكيد مسؤول آخر. لا يفعّل شيئًا قبل التأكيد.',
      approve_join_existing: 'لا تُنشأ مساحة عمل ولا اشتراك: يدخل الشخص بالدور والنطاق المحددين، ويبقى مالك المساحة مسؤولًا عنها.',
      approve_complimentary: 'دخول مباشر دون دفع: تُفعَّل مساحة العمل الآن بلا تاريخ انتهاء، ولا يُسجل دفع ولا فاتورة ولا عملية لدى مزوّد، ولا يتطلب بوابة دفع. لا يُطلب أي مبلغ عند التجديد ما دام ساريًا، ويمكنك لاحقًا إنهاؤه أو تحويله إلى مدفوع أو خصم من صفحة المستأجرين.',
    },
    discount: 'نسبة الخصم %', trialEnds: 'تاريخ انتهاء التجربة',
    reference: 'مرجع العملية', amount: 'المبلغ', currency: 'العملة', paidOn: 'تاريخ السداد', evidenceNote: 'ملاحظة',
    access: 'نطاق الوصول', portal: 'البوابة', portalDefault: 'كما طلب مقدّم الطلب', modules: 'الوحدات المسموحة',
    paidMedia: 'الإعلانات المدفوعة', influencers: 'التسويق بالمؤثرين',
    tenant: 'مساحة العمل', role: 'الدور', projects: 'المشاريع المسموحة', clients: 'العملاء المسموحون', choose: 'اختر',
    reason: 'السبب (يُحفظ في السجل)',
    preview: 'معاينة القرار', submit: 'اعتماد القرار', approve: 'اعتماد مع طلب الدفع',
    previewTitle: 'ما سيحدث بالضبط',
    listAmount: 'السعر الأصلي', amountDue: 'المستحق الآن', vat: 'ضريبة القيمة المضافة', renewal: 'سعر التجديد', renewalOn: 'تاريخ الاستحقاق التالي', waived: 'المبلغ المعفى',
    noProvider: 'بانتظار تهيئة مزوّد الدفع: لن يُفعَّل الحساب قبل تأكيد الدفع.',
    creates: 'ينشئ', createdTenant: 'مساحة عمل', createdMembership: 'عضوية', createdProject: 'مشروع', nothing: 'لا شيء',
    effectiveRole: 'الدور الفعلي', permissions: 'الصلاحيات', allOwn: 'كل الصلاحيات داخل مساحة عمله فقط', who: 'من يملك الوصول',
    confirmWaiver: (fee: string) => `أؤكد الإعفاء من ${fee} لفترة واحدة فقط، وهذا ليس دفعًا.`,
    awaiting: 'سداد خارجي بانتظار تأكيد مسؤول آخر', verify: 'تأكيد السداد', verifyReason: 'كيف تحققت من العملية؟',
    inForce: 'القرار الساري',
    openEnded: 'تاريخ الانتهاء', noEnd: 'بلا تاريخ انتهاء',
    confirmComplimentary: 'أؤكد منح دخول مباشر دون دفع وبلا تاريخ انتهاء. هذا ليس دفعًا ولا تُصدر له فاتورة.',
    strandedTitle: 'هذا الطلب معتمد بالفعل، والمتبقي هو الدفع.',
    strandedBody: 'لذلك لا يغيّر زر «اعتماد مع طلب الدفع» شيئًا هنا: الطلب معتمد مسبقًا ولا يوجد ما يُعتمد مرة أخرى. لإدخاله الآن دون دفع، أو بخصم أو بإعفاء، اختر قرارًا آخر.',
    strandedAction: 'دخول مباشر دون دفع',
    noChange: 'لم يتغير شيء: هذا الطلب معتمد مسبقًا والمتبقي هو الدفع. لم يُضف سطر جديد إلى السجل.',
  },
  en: {
    title: 'Decision',
    outcomes: {
      approve_payment_required: 'Approve, payment required', approve_discount: 'Approve with a discount', approve_waiver: 'Approve with one period waived and activate now',
      approve_trial: 'Approve with a dated trial', approve_external_settlement: 'Approve on an evidenced external settlement', approve_join_existing: 'Add to an existing workspace',
      approve_complimentary: 'Direct entry with no payment (no end date)',
    },
    hints: {
      approve_payment_required: 'The workspace stays closed until the provider confirms the payment.',
      approve_discount: 'Activates once the exact remainder is confirmed. The discount covers the first period only.',
      approve_waiver: 'A separate financial decision: no payment, provider transaction or invoice is recorded. One period, never a lifetime exemption.',
      approve_trial: 'Active as a trial until the date set, then payment is due. Nothing is charged automatically.',
      approve_external_settlement: 'Needs evidence of a real settlement and a second administrator. Activates nothing before that.',
      approve_join_existing: 'No workspace or subscription is created: the person joins with this role and scope, and the workspace owner stays accountable.',
      approve_complimentary: 'Direct entry with no payment: the workspace opens now with no end date. No payment, invoice or provider transaction is recorded, and no payment gateway is needed. Renewal asks for nothing while it stands; you can end it or convert it to paid or a discount later from Tenants.',
    },
    discount: 'Discount %', trialEnds: 'Trial ends on',
    reference: 'Transaction reference', amount: 'Amount', currency: 'Currency', paidOn: 'Paid on', evidenceNote: 'Note',
    access: 'Access scope', portal: 'Portal', portalDefault: 'As the applicant asked', modules: 'Allowed modules',
    paidMedia: 'Paid media', influencers: 'Influencer marketing',
    tenant: 'Workspace', role: 'Role', projects: 'Allowed projects', clients: 'Allowed clients', choose: 'Choose',
    reason: 'Justification (kept in the record)',
    preview: 'Preview the decision', submit: 'Take this decision', approve: 'Approve, payment required',
    previewTitle: 'Exactly what happens',
    listAmount: 'List price', amountDue: 'Due now', vat: 'VAT', renewal: 'Renewal price', renewalOn: 'Next due date', waived: 'Amount waived',
    noProvider: 'Awaiting payment provider configuration: the account stays closed until a payment is confirmed.',
    creates: 'Creates', createdTenant: 'workspace', createdMembership: 'membership', createdProject: 'project', nothing: 'nothing',
    effectiveRole: 'Effective role', permissions: 'Permissions', allOwn: 'Everything inside their own workspace only', who: 'Who has access',
    confirmWaiver: (fee: string) => `I confirm waiving ${fee} for one period only, and that this is not a payment.`,
    awaiting: 'External settlement waiting on a second administrator', verify: 'Confirm settlement', verifyReason: 'How did you check the transaction?',
    inForce: 'Decision in force',
    openEnded: 'End date', noEnd: 'No end date',
    confirmComplimentary: 'I confirm direct entry with no payment and no end date. This is not a payment and no invoice is issued.',
    strandedTitle: 'This application is already approved. What is outstanding is the payment.',
    strandedBody: 'That is why «Approve, payment required» changes nothing here: it is already approved, so there is nothing to approve again. To let them in now with no payment, a discount or a waiver, choose another decision.',
    strandedAction: 'Direct entry with no payment',
    noChange: 'Nothing changed: this application is already approved and what is outstanding is the payment. No new line was added to the record.',
  },
} as const

const OUTCOMES: RegistrationDecisionOutcome[] = [
  'approve_payment_required', 'approve_complimentary', 'approve_discount', 'approve_waiver', 'approve_trial', 'approve_external_settlement', 'approve_join_existing',
]

export function RegistrationDecisionForm({ registration, ar, onDecided }: {
  registration: AdminRegistration
  ar: boolean
  onDecided: () => void
}) {
  const c = COPY[ar ? 'ar' : 'en']
  const [outcome, setOutcome] = useState<RegistrationDecisionOutcome>('approve_payment_required')
  const [reason, setReason] = useState('')
  const [discount, setDiscount] = useState('')
  const [trialEnds, setTrialEnds] = useState('')
  const [evidence, setEvidence] = useState({ reference: '', amount: '', currency: '', paid_on: '', note: '' })
  const [portal, setPortal] = useState('')
  const [modules, setModules] = useState<string[]>([])
  const [tenantId, setTenantId] = useState('')
  const [role, setRole] = useState('')
  const [projectIds, setProjectIds] = useState<string[]>([])
  const [clientIds, setClientIds] = useState<string[]>([])
  const [preview, setPreview] = useState<RegistrationDecisionPreview | null>(null)
  const [confirmed, setConfirmed] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [noChange, setNoChange] = useState(false)

  const joining = outcome === 'approve_join_existing'
  const tenants = useQuery({ queryKey: ['admin', 'tenants', 'decision'], queryFn: () => fetchTenants({ status: 'active' }), enabled: joining })
  const targets = useQuery({
    queryKey: ['admin', 'tenant-decision-targets', tenantId],
    queryFn: () => fetchTenantDecisionTargets(tenantId),
    enabled: joining && tenantId !== '',
  })

  const current = registration.decisions?.find((d) => d.status === 'in_force') ?? null
  // Approved, and the one thing left is money: approving again cannot move it, and the form has to say why.
  const awaitingMoney = ['approved_awaiting_payment', 'payment_pending'].includes(registration.state)

  const input = useMemo<RegistrationDecisionInput>(() => ({
    outcome,
    reason,
    expected_decision_id: current?.id ?? null,
    ...(outcome === 'approve_discount' ? { discount_percent: Number(discount) } : {}),
    ...(outcome === 'approve_trial' ? { trial_ends_on: trialEnds } : {}),
    ...(outcome === 'approve_external_settlement' ? { evidence } : {}),
    access: joining
      ? { mode: 'join_existing', tenant_id: tenantId, role, project_ids: projectIds, client_ids: clientIds, ...(portal ? { portal } : {}) }
      : { mode: 'new_workspace', ...(portal ? { portal } : {}), ...(modules.length > 0 ? { modules } : {}) },
  }), [outcome, reason, current?.id, discount, trialEnds, evidence, joining, tenantId, role, projectIds, clientIds, portal, modules])

  // One key per decision as shaped: a double click replays, and changing anything is a new decision.
  const key = useRef<string>(crypto.randomUUID())
  const shape = JSON.stringify({ ...input, reason: undefined })
  useEffect(() => {
    key.current = crypto.randomUUID()
    setPreview(null)
    setConfirmed(false)
    setError(null)
    setNoChange(false)
  }, [shape])

  const failed = (e: unknown) => setError(toApiError(e).message)
  // A click that changed nothing says so, instead of refreshing a screen that looks the same.
  const settled = (r: { no_change?: boolean }) => {
    if (r.no_change) { setNoChange(true); setError(null); return }
    setNoChange(false)
    onDecided()
  }

  const previewing = useMutation({ meta: { feedback: 'none' },
    mutationFn: () => previewRegistrationDecision(registration.id, { ...input, reason: reason || 'Preview only' }),
    onSuccess: (p) => { setPreview(p); setError(null) },
    onError: failed,
  })
  const approving = useMutation({ mutationFn: () => approveRegistration(registration.id), onSuccess: settled, onError: failed })
  const deciding = useMutation({
    mutationFn: () => decideRegistration(registration.id, {
      ...input,
      idempotency_key: key.current,
      ...(outcome === 'approve_waiver' ? { confirm_waiver: confirmed, confirm_amount: preview?.terms?.fee ?? null } : {}),
      ...(outcome === 'approve_complimentary' ? { confirm_complimentary: confirmed } : {}),
    }),
    onSuccess: settled,
    onError: failed,
  })

  const needsConfirmation = outcome === 'approve_waiver' || outcome === 'approve_complimentary'
  const ready = preview !== null && reason.trim().length >= 5 && (!needsConfirmation || confirmed)
  const inputClass = 'h-10 w-full min-w-0 rounded-xl border border-border bg-surface px-3 text-sm text-text-primary'
  const labelClass = 'flex min-w-0 flex-col gap-1 text-xs font-semibold text-text-secondary'
  const toggle = (list: string[], value: string, set: (next: string[]) => void) =>
    set(list.includes(value) ? list.filter((v) => v !== value) : [...list, value])

  return (
    <section data-testid="registration-decision" className="flex min-w-0 flex-col gap-3 rounded-xl border border-border p-3">
      <p className="text-xs font-semibold uppercase tracking-wide text-text-muted">{c.title}</p>

      {current && <p data-testid="registration-decision-current" className="text-xs text-text-secondary">{c.inForce}: {c.outcomes[current.outcome as keyof typeof c.outcomes] ?? current.outcome}</p>}

      {awaitingMoney && outcome === 'approve_payment_required' && (
        <div data-testid="decision-stranded" className="flex flex-col gap-2 rounded-xl border border-warning/40 bg-warning/10 p-3 text-sm text-text-primary">
          <p className="font-semibold">{c.strandedTitle}</p>
          <p className="text-xs text-text-secondary">{c.strandedBody}</p>
          <div><Button data-testid="decision-stranded-complimentary" size="sm" variant="secondary" onClick={() => setOutcome('approve_complimentary')}>{c.strandedAction}</Button></div>
        </div>
      )}

      <fieldset className="flex flex-col gap-1.5">
        {OUTCOMES.map((o) => (
          <label key={o} className="flex items-start gap-2 text-sm text-text-primary">
            <input type="radio" name="decision-outcome" data-testid={`decision-${o}`} checked={outcome === o} onChange={() => setOutcome(o)} className="mt-1" />
            <span className="min-w-0">{c.outcomes[o]}</span>
          </label>
        ))}
      </fieldset>
      <p data-testid="decision-hint" className="text-xs text-text-secondary">{c.hints[outcome]}</p>

      {outcome === 'approve_discount' && (
        <label className={labelClass}>{c.discount}
          <input data-testid="decision-discount" dir="ltr" inputMode="numeric" value={discount} onChange={(e) => setDiscount(e.target.value)} className={inputClass} />
        </label>
      )}

      {outcome === 'approve_trial' && (
        <div className={labelClass}><label htmlFor="decision-trial-ends">{c.trialEnds}</label>
          <DateField id="decision-trial-ends" data-testid="decision-trial-ends" value={trialEnds} onChange={setTrialEnds} />
        </div>
      )}

      {outcome === 'approve_external_settlement' && (
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
          <label className={labelClass}>{c.reference}<input data-testid="decision-evidence-reference" value={evidence.reference} onChange={(e) => setEvidence({ ...evidence, reference: e.target.value })} className={inputClass} /></label>
          <label className={labelClass}>{c.amount}<input data-testid="decision-evidence-amount" dir="ltr" inputMode="decimal" value={evidence.amount} onChange={(e) => setEvidence({ ...evidence, amount: e.target.value })} className={inputClass} /></label>
          <label className={labelClass}>{c.currency}<input data-testid="decision-evidence-currency" dir="ltr" value={evidence.currency} onChange={(e) => setEvidence({ ...evidence, currency: e.target.value.toUpperCase() })} className={inputClass} /></label>
          <div className={labelClass}><label htmlFor="decision-evidence-paid-on">{c.paidOn}</label>
            <DateField id="decision-evidence-paid-on" data-testid="decision-evidence-paid-on" value={evidence.paid_on} onChange={(paid_on) => setEvidence({ ...evidence, paid_on })} />
          </div>
        </div>
      )}

      <div className="flex flex-col gap-2">
        <p className="text-xs font-semibold text-text-muted">{c.access}</p>
        {joining ? (
          <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
            <label className={labelClass}>{c.tenant}
              <select data-testid="decision-tenant" value={tenantId} onChange={(e) => { setTenantId(e.target.value); setRole(''); setProjectIds([]); setClientIds([]) }} className={inputClass}>
                <option value="">{c.choose}</option>
                {tenants.data?.tenants.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
              </select>
            </label>
            <label className={labelClass}>{c.role}
              <select data-testid="decision-role" value={role} onChange={(e) => setRole(e.target.value)} className={inputClass}>
                <option value="">{c.choose}</option>
                {targets.data?.roles.map((r) => <option key={r.slug} value={r.slug}>{r.name}</option>)}
              </select>
            </label>
            {targets.data && (
              <>
                <div data-testid="decision-projects" className={labelClass}>{c.projects}
                  {targets.data.projects.map((p) => (
                    <label key={p.id} className="flex items-center gap-2 font-normal"><input type="checkbox" checked={projectIds.includes(p.id)} onChange={() => toggle(projectIds, p.id, setProjectIds)} />{p.name}</label>
                  ))}
                </div>
                <div className={labelClass}>{c.clients}
                  {targets.data.clients.map((p) => (
                    <label key={p.id} className="flex items-center gap-2 font-normal"><input type="checkbox" checked={clientIds.includes(p.id)} onChange={() => toggle(clientIds, p.id, setClientIds)} />{p.name}</label>
                  ))}
                </div>
              </>
            )}
          </div>
        ) : (
          <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
            <label className={labelClass}>{c.portal}
              <select data-testid="decision-portal" value={portal} onChange={(e) => setPortal(e.target.value)} className={inputClass}>
                <option value="">{c.portalDefault}</option>
                <option value="app">app</option>
                <option value="agency">agency</option>
              </select>
            </label>
            <div className={labelClass}>{c.modules}
              <label className="flex items-center gap-2 font-normal"><input type="checkbox" data-testid="decision-module-paid_media" checked={modules.includes('paid_media')} onChange={() => toggle(modules, 'paid_media', setModules)} />{c.paidMedia}</label>
              <label className="flex items-center gap-2 font-normal"><input type="checkbox" data-testid="decision-module-influencer_marketing" checked={modules.includes('influencer_marketing')} onChange={() => toggle(modules, 'influencer_marketing', setModules)} />{c.influencers}</label>
            </div>
          </div>
        )}
      </div>

      <label className={labelClass}>{c.reason}
        <input data-testid="decision-reason" value={reason} onChange={(e) => setReason(e.target.value)} className={inputClass} />
      </label>

      <div className="flex flex-wrap gap-2">
        {/* The plain answer concedes nothing and creates no special access, so it does not need a preview first. */}
        {outcome === 'approve_payment_required' && portal === '' && modules.length === 0 ? (
          <Button data-testid="registration-approve" onClick={() => approving.mutate()} loading={approving.isPending} size="sm">
            <Check size={15} /> {c.approve}
          </Button>
        ) : (
          <>
            <Button data-testid="decision-preview" variant="secondary" onClick={() => previewing.mutate()} loading={previewing.isPending} size="sm">
              <Eye size={15} /> {c.preview}
            </Button>
            <Button data-testid="decision-submit" onClick={() => deciding.mutate()} loading={deciding.isPending} disabled={!ready} size="sm">
              <Check size={15} /> {c.submit}
            </Button>
          </>
        )}
      </div>

      {preview && <PreviewPanel preview={preview} copy={c} />}

      {preview?.terms?.fee && (
        <label className="flex items-start gap-2 rounded-xl border border-warning/40 bg-warning/10 p-3 text-sm font-semibold text-text-primary">
          <input type="checkbox" data-testid="decision-confirm-waiver" checked={confirmed} onChange={(e) => setConfirmed(e.target.checked)} className="mt-0.5" />
          <span className="min-w-0">{c.confirmWaiver(`${preview.terms.fee} ${preview.terms.currency ?? ''}`)}</span>
        </label>
      )}

      {preview?.terms?.complimentary && (
        <label className="flex items-start gap-2 rounded-xl border border-warning/40 bg-warning/10 p-3 text-sm font-semibold text-text-primary">
          <input type="checkbox" data-testid="decision-confirm-complimentary" checked={confirmed} onChange={(e) => setConfirmed(e.target.checked)} className="mt-0.5" />
          <span className="min-w-0">{c.confirmComplimentary}</span>
        </label>
      )}

      {noChange && <p role="status" data-testid="decision-no-change" className="rounded-xl bg-surface-secondary px-3.5 py-2.5 text-sm font-semibold text-text-primary">{c.noChange}</p>}

      {error && <p data-testid="decision-error" className="rounded-xl bg-[var(--negative-background)] px-3.5 py-2.5 text-sm text-danger">{error}</p>}

      {registration.decisions?.filter((d) => d.status === 'awaiting_finance').map((d) => (
        <AwaitingSettlement key={d.id} registrationId={registration.id} decision={d} copy={c} onDone={onDecided} />
      ))}
    </section>
  )
}

type Copy = typeof COPY['en'] | typeof COPY['ar']

function PreviewPanel({ preview, copy: c }: { preview: RegistrationDecisionPreview; copy: Copy }) {
  const t = preview.terms
  const a = preview.access
  const line = (label: string, value: string | null | undefined, testId?: string) => value == null ? null : (
    <div className="flex flex-wrap justify-between gap-2">
      <dt>{label}</dt><dd data-testid={testId}><Num>{value}</Num></dd>
    </div>
  )
  const withCurrency = (v?: string | null) => (v == null ? null : `${v} ${t?.currency ?? ''}`)
  const made = [
    a.creates.tenant ? c.createdTenant : null, a.creates.membership ? c.createdMembership : null, a.creates.project ? c.createdProject : null,
  ].filter(Boolean).join(' + ')

  return (
    <div data-testid="decision-preview-panel" className="rounded-xl bg-surface-secondary p-3 text-xs text-text-secondary">
      <p className="mb-1.5 font-semibold text-text-primary">{c.previewTitle}</p>
      <dl className="flex flex-col gap-1">
        {line(c.listAmount, withCurrency(t?.list_amount), 'preview-list')}
        {line(c.waived, withCurrency(t?.fee), 'preview-waived')}
        {line(c.amountDue, withCurrency(t?.amount_due), 'preview-due')}
        {line(c.vat, t?.vat ? `${t.vat.tax} ${t.currency ?? ''}` : null, 'preview-vat')}
        {line(c.renewal, withCurrency(t?.renewal_amount), 'preview-renewal')}
        {line(c.renewalOn, t?.renewal_on, 'preview-renewal-on')}
        {t?.complimentary && <div className="flex flex-wrap justify-between gap-2"><dt>{c.openEnded}</dt><dd data-testid="preview-open-ended">{c.noEnd}</dd></div>}
        <div className="flex flex-wrap justify-between gap-2"><dt>{c.creates}</dt><dd data-testid="preview-creates">{made || c.nothing}</dd></div>
        <div className="flex flex-wrap justify-between gap-2"><dt>{c.effectiveRole}</dt><dd data-testid="preview-role"><Num>{a.role}</Num></dd></div>
        <div className="flex flex-wrap justify-between gap-2">
          <dt>{c.permissions}</dt>
          <dd data-testid="preview-permissions" className="min-w-0 break-words">{a.permissions === 'all_in_own_workspace' ? c.allOwn : <Num>{a.permissions.join(', ')}</Num>}</dd>
        </div>
        {a.projects && a.projects.length > 0 && <div className="flex flex-wrap justify-between gap-2"><dt>{c.projects}</dt><dd data-testid="preview-projects">{a.projects.join('، ')}</dd></div>}
        <div className="flex flex-wrap justify-between gap-2"><dt>{c.who}</dt><dd><Num>{a.who_has_access.join(', ')}</Num></dd></div>
      </dl>
      {!preview.payment_provider_ready && (preview.outcome === 'approve_payment_required' || preview.outcome === 'approve_discount') && (
        <p data-testid="preview-no-provider" className="mt-2 font-semibold text-text-primary">{c.noProvider}</p>
      )}
    </div>
  )
}

function AwaitingSettlement({ registrationId, decision, copy: c, onDone }: {
  registrationId: string
  decision: NonNullable<AdminRegistration['decisions']>[number]
  copy: Copy
  onDone: () => void
}) {
  const [why, setWhy] = useState('')
  const [error, setError] = useState<string | null>(null)
  const verify = useMutation({ meta: { feedback: 'none' },
    mutationFn: () => verifyRegistrationSettlement(registrationId, decision.id, why),
    onSuccess: onDone,
    onError: (e: unknown) => setError(toApiError(e).message),
  })
  const evidence = (decision.terms?.evidence ?? {}) as Record<string, string>

  return (
    <div data-testid="decision-awaiting-finance" className="flex flex-col gap-2 rounded-xl border border-border p-3 text-sm">
      <p className="font-semibold text-text-primary">{c.awaiting}</p>
      <p className="text-xs text-text-secondary"><Num>{evidence.reference} · {evidence.amount} {evidence.currency} · {evidence.paid_on}</Num></p>
      <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">{c.verifyReason}
        <input data-testid="decision-verify-reason" value={why} onChange={(e) => setWhy(e.target.value)} className="h-10 rounded-xl border border-border bg-surface px-3 text-sm text-text-primary" />
      </label>
      <div><Button data-testid="decision-verify" size="sm" disabled={why.trim().length < 5} loading={verify.isPending} onClick={() => verify.mutate()}>{c.verify}</Button></div>
      {error && <p data-testid="decision-verify-error" className="text-sm text-danger">{error}</p>}
    </div>
  )
}
