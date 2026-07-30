import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, CheckCircle2, KeyRound, RefreshCw, ShieldAlert } from 'lucide-react'
import {
  fetchCutoverReadiness, fetchPortalConflicts, resolvePortalConflict,
  type PortalConflict,
} from './api'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

/**
 * `/admin/cutover` — is it safe to retire the legacy client-portal engine yet? (PORTAL-AUTH-001)
 *
 * The page shows EVIDENCE and offers no cutover button, because there is no such endpoint. Retiring
 * the engine is a code change with a review, not something anyone should be able to do by reading a
 * green light. What the "Run check" button does is re-read the three conditions — it changes nothing.
 *
 * The conditions block independently, and each is stated in the language of consequence rather than
 * as a metric: an open conflict is a person nobody has decided about; a live legacy session belongs
 * to someone with no password, so signing them out is not recoverable by them.
 */

/** Latin digits everywhere, per the product's standing rule. */
const num = (n: number) => n.toLocaleString('en-US')

const REASON_LABELS: Record<string, { ar: string; en: string }> = {
  email_belongs_to_staff: { ar: 'البريد يخص حسابًا داخليًا', en: 'Address belongs to a staff account' },
  phone_only_no_email: { ar: 'جوال بلا بريد', en: 'Phone with no email address' },
  tenant_missing: { ar: 'المستأجر غير موجود', en: 'Tenant no longer exists' },
  no_client_space: { ar: 'بلا مساحة عميل', en: 'No client space' },
}

export function CutoverPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const qc = useQueryClient()
  const [resolving, setResolving] = useState<PortalConflict | null>(null)
  const [note, setNote] = useState('')

  const readiness = useQuery({ queryKey: ['admin', 'cutover'], queryFn: fetchCutoverReadiness })
  const conflicts = useQuery({ queryKey: ['admin', 'portal-conflicts'], queryFn: () => fetchPortalConflicts(true) })

  const resolve = useMutation({
    mutationFn: ({ id, resolution, why }: { id: string; resolution: 'link' | 'separate'; why?: string }) =>
      resolvePortalConflict(id, resolution, why),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['admin'] })
      setResolving(null)
      setNote('')
    },
  })

  const error = resolve.isError ? toApiError(resolve.error) : null

  if (readiness.isPending) {
    return <div className="grid gap-3"><Skeleton className="h-10 w-72" /><Skeleton className="h-32" /><Skeleton className="h-56" /></div>
  }

  if (readiness.isError || !readiness.data) {
    return (
      <ErrorState
        title={ar ? 'تعذّر قراءة جاهزية الانتقال.' : 'Cutover readiness could not be read.'}
        onRetry={() => void readiness.refetch()}
      />
    )
  }

  const d = readiness.data

  return (
    <div className="w-full">
      <header className="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="font-heading text-3xl font-extrabold tracking-tight text-text-primary">
            {ar ? 'جاهزية انتقال بوابة العملاء' : 'Client-portal cutover readiness'}
          </h1>
          <p className="mt-1 text-sm text-text-secondary">
            {ar
              ? 'المحركان يعملان معًا. لا يُلغى القديم إلا بعد أن تصبح الشروط الثلاثة صفرًا.'
              : 'Both engines are running. The old one is not retired until all three conditions are zero.'}
          </p>
        </div>
        <Button variant="secondary" disabled={readiness.isFetching} onClick={() => void readiness.refetch()}>
          <RefreshCw size={15} className={readiness.isFetching ? 'animate-spin' : ''} />
          {ar ? 'فحص الآن' : 'Run check'}
        </Button>
      </header>

      {/* The verdict, and the reason it is not the other one. */}
      <div
        data-testid="cutover-verdict"
        className={`mb-4 flex items-start gap-3 rounded-2xl border px-5 py-4 ${
          d.ready ? 'border-success/40 bg-success/10' : 'border-warning/40 bg-warning/10'
        }`}
      >
        {d.ready
          ? <CheckCircle2 size={20} className="mt-0.5 shrink-0 text-success" aria-hidden />
          : <ShieldAlert size={20} className="mt-0.5 shrink-0 text-warning" aria-hidden />}
        <div>
          <p className="font-heading text-[15px] font-bold text-text-primary">
            {d.ready
              ? (ar ? 'الشروط مستوفاة — الإلغاء آمن' : 'Conditions met — retiring is safe')
              : (ar ? 'الإلغاء غير آمن بعد' : 'Not safe to retire yet')}
          </p>
          {!d.ready ? (
            // Composed HERE from the numbers, not echoed from the server's English. A server string
            // dropped into an Arabic sentence renders with its digits stranded at the wrong end, and
            // the reader has to reassemble the meaning.
            <ul data-testid="cutover-blockers" className="mt-1.5 list-disc space-y-0.5 text-sm text-text-primary ps-5">
              {d.open_conflicts > 0 && (
                <li>
                  {ar
                    ? `${num(d.open_conflicts)} تعارض هوية ما زال مفتوحًا — أشخاص لم يُبتّ في هويتهم.`
                    : `${num(d.open_conflicts)} identity conflict(s) still open — people nobody has decided about.`}
                </li>
              )}
              {d.legacy_sessions > 0 && (
                <li>
                  {ar
                    ? `${num(d.legacy_sessions)} جلسة ما زالت تعتمد على التوكن القديم — أصحابها بلا كلمة مرور.`
                    : `${num(d.legacy_sessions)} session(s) still depend on the legacy token — their holders have no password.`}
                </li>
              )}
              {d.parity.mismatched > 0 && (
                <li>
                  {ar
                    ? `${num(d.parity.mismatched)} جهة يختلف عليها المحركان — القطع الآن يغيّر ما تراه.`
                    : `${num(d.parity.mismatched)} contact(s) where the engines disagree — cutting over would change what they see.`}
                </li>
              )}
            </ul>
          ) : (
            <p className="mt-1 text-sm text-text-secondary">
              {ar
                ? 'إلغاء المحرك القديم تغيير في الكود يُراجَع — لا يوجد زر هنا يقوم به، وهذا مقصود.'
                : 'Retiring the old engine is a reviewed code change — there is deliberately no button here that does it.'}
            </p>
          )}
        </div>
      </div>

      <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <Condition
          label={ar ? 'تعارضات مفتوحة' : 'Open conflicts'}
          value={d.open_conflicts}
          hint={ar ? 'أشخاص لم يُبتّ في هويتهم' : 'people nobody has decided about'}
        />
        <Condition
          label={ar ? 'جلسات على المحرك القديم' : 'Sessions on the legacy engine'}
          value={d.legacy_sessions}
          hint={ar ? 'أصحابها بلا كلمة مرور' : 'their holders have no password'}
        />
        <Condition
          label={ar ? 'اختلافات Parity' : 'Parity disagreements'}
          value={d.parity.mismatched}
          hint={ar ? `من ${d.parity.checked} تم فحصهم` : `of ${d.parity.checked} checked`}
        />
      </div>

      <p className="mb-5 text-xs text-text-muted">
        {ar ? 'آخر فحص: ' : 'Last checked: '}
        <span className="tnum" dir="ltr">
          {d.last_checked_at ? d.last_checked_at.slice(0, 19).replace('T', ' ') : (ar ? 'لم يُنفَّذ' : 'never')}
        </span>
      </p>

      {error && (
        <p role="alert" className="mb-4 rounded-xl bg-[var(--negative-background)] px-4 py-3 text-sm text-danger">
          {error.message}
        </p>
      )}

      {/* Named, with both sides — so the reader sees WHAT would change, not that something would. */}
      {d.parity.mismatches.length > 0 && (
        <section className="mb-5 rounded-2xl border border-warning/40 bg-surface p-5">
          <h2 className="font-heading text-lg font-extrabold text-text-primary">
            {ar ? 'المحركان لا يتفقان على هؤلاء' : 'The two engines disagree about these people'}
          </h2>
          <p className="mt-1 text-sm text-text-secondary">
            {ar
              ? 'القطع الآن يغيّر ما يراه كل منهم دون أن يلاحظ أحد. أعد تشغيل الترحيل لمعالجة الفارق.'
              : 'Cutting over now would change what each of them sees, unnoticed. Re-run the backfill to reconcile.'}
          </p>
          <ul data-testid="parity-mismatches" className="mt-3 grid gap-2">
            {d.parity.mismatches.map((m) => (
              <li key={m.contact} className="rounded-xl bg-surface-secondary px-3.5 py-2.5 text-[13px]">
                <span className="font-semibold text-text-primary" dir="ltr">{m.contact}</span>
                <span className="tnum mt-1 block text-text-secondary" dir="ltr">
                  {ar ? 'العضوية' : 'membership'}: {m.membership.length} · {ar ? 'التوكن' : 'token'}: {m.token.length}
                </span>
              </li>
            ))}
          </ul>
        </section>
      )}

      {d.legacy_holders.length > 0 && (
        <section className="mb-5 rounded-2xl border border-border bg-surface p-5">
          <h2 className="font-heading text-lg font-extrabold text-text-primary">
            {ar ? 'جلسات ما زالت على المحرك القديم' : 'Sessions still on the legacy engine'}
          </h2>
          <ul data-testid="legacy-holders" className="mt-3 grid gap-1.5">
            {d.legacy_holders.map((h) => (
              <li key={h.contact + h.expires_at} className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-surface-secondary px-3 py-2 text-[13px]">
                <span className="font-semibold text-text-primary" dir="ltr">{h.contact}</span>
                <span className="flex items-center gap-2.5 text-[12px]">
                  {/* Two different problems: one upgrades itself, the other needs a decision. */}
                  <span className={h.has_membership ? 'text-text-muted' : 'font-semibold text-warning'}>
                    {h.has_membership
                      ? (ar ? 'يترقّى عند الدخول التالي' : 'upgrades on next sign-in')
                      : (ar ? 'بلا عضوية — يحتاج معالجة تعارض' : 'no membership — needs a conflict resolved')}
                  </span>
                  {h.expires_at && (
                    <span className="tnum text-text-muted" dir="ltr">{h.expires_at.slice(0, 10)}</span>
                  )}
                </span>
              </li>
            ))}
          </ul>
        </section>
      )}

      <section className="rounded-2xl border border-border bg-surface p-5">
        <h2 className="font-heading text-lg font-extrabold text-text-primary">
          {ar ? 'تعارضات الهوية' : 'Identity conflicts'}
        </h2>
        <p className="mt-1 text-sm text-text-secondary">
          {ar
            ? 'كل صف شخص رفض الترحيل تخمين هويته. الاختيار الخاطئ يمنح موظفًا رؤية عميل أو العكس — لذلك لا توجد معالجة جماعية.'
            : 'Each row is someone the migration refused to guess at. Choosing wrong gives an employee a client’s view, or the reverse — so there is no bulk resolve.'}
        </p>

        {conflicts.isPending && <Skeleton className="mt-4 h-24" />}

        {conflicts.data && conflicts.data.conflicts.length === 0 && (
          <p className="mt-4 rounded-xl border border-dashed border-border px-4 py-8 text-center text-sm text-text-muted">
            {ar ? 'لا تعارضات مفتوحة.' : 'No open conflicts.'}
          </p>
        )}

        {conflicts.data && conflicts.data.conflicts.length > 0 && (
          <ul data-testid="conflict-list" className="mt-4 grid gap-2">
            {conflicts.data.conflicts.map((c) => (
              <li key={c.id} data-testid={`conflict-${c.id}`} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border px-4 py-3">
                <div className="min-w-0">
                  <p className="text-[14px] font-bold text-text-primary" dir="ltr">{c.contact_email ?? c.contact_phone}</p>
                  <p className="mt-0.5 text-[12.5px] text-text-muted">
                    {REASON_LABELS[c.reason] ? (ar ? REASON_LABELS[c.reason].ar : REASON_LABELS[c.reason].en) : c.reason}
                    {c.tenant_name && ` · ${c.tenant_name}`}
                    {/* The consequence, before the choice. */}
                    <span className="tnum" dir="ltr"> · {c.client_ids.length} {ar ? 'مساحة' : 'space(s)'}</span>
                  </p>
                </div>
                <div className="flex gap-2">
                  <Button size="sm" variant="secondary" onClick={() => setResolving(c)}>
                    <KeyRound size={14} /> {ar ? 'نفس الشخص' : 'Same person'}
                  </Button>
                  <Button
                    size="sm"
                    variant="ghost"
                    disabled={resolve.isPending}
                    onClick={() => resolve.mutate({ id: c.id, resolution: 'separate' })}
                  >
                    {ar ? 'شخصان مختلفان' : 'Different people'}
                  </Button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </section>

      {resolving && (
        <Modal open onClose={() => { setResolving(null); setNote('') }}
          title={ar ? 'ربط الحسابين' : 'Link the accounts'}>
          <div className="flex items-start gap-2.5 rounded-xl border border-warning/40 bg-warning/10 px-4 py-3 text-sm">
            <AlertTriangle size={17} className="mt-0.5 shrink-0 text-warning" aria-hidden />
            <span className="text-text-primary">
              {ar
                ? `سيحصل هذا الحساب على عضوية بوابة العملاء لـ${resolving.client_ids.length} مساحة، إضافة إلى ما يملكه الآن. لا يُلغى شيء.`
                : `This account gains a client-portal membership for ${resolving.client_ids.length} space(s), on top of what it already holds. Nothing is removed.`}
            </span>
          </div>

          <label className="mt-4 block text-sm font-semibold text-text-primary" htmlFor="link-note">
            {ar ? 'السبب (مطلوب)' : 'Reason (required)'}
          </label>
          <textarea
            id="link-note"
            value={note}
            onChange={(e) => setNote(e.target.value)}
            rows={3}
            placeholder={ar ? 'كيف تم التأكد أنه نفس الشخص؟' : 'How was it confirmed that this is the same person?'}
            className="mt-1.5 w-full rounded-xl border border-border bg-surface px-3.5 py-2.5 text-sm outline-none focus:border-brand-500"
          />

          <div className="mt-4 flex justify-end gap-2">
            <Button variant="ghost" onClick={() => { setResolving(null); setNote('') }}>
              {ar ? 'إلغاء' : 'Cancel'}
            </Button>
            <Button
              disabled={note.trim() === '' || resolve.isPending}
              onClick={() => resolve.mutate({ id: resolving.id, resolution: 'link', why: note.trim() })}
            >
              {ar ? 'ربط ومنح العضوية' : 'Link and grant'}
            </Button>
          </div>
        </Modal>
      )}
    </div>
  )
}

function Condition({ label, value, hint }: { label: string; value: number; hint: string }) {
  return (
    <div className={`rounded-2xl border bg-surface p-5 ${value === 0 ? 'border-border' : 'border-warning/40'}`}>
      <span className={`tnum block font-heading text-3xl font-extrabold ${value === 0 ? 'text-success' : 'text-warning'}`} dir="ltr">
        {num(value)}
      </span>
      <span className="mt-0.5 block text-sm font-semibold text-text-primary">{label}</span>
      <span className="mt-1 block text-xs text-text-muted">{hint}</span>
    </div>
  )
}
