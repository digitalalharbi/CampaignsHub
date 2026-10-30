import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { CheckCircle2, Circle } from 'lucide-react'
import { closeMetaSaasTestWorkspace, createMetaSaasTestWorkspace, type MetaCandidateState, type MetaSaasJourneyStep, type MetaSaasTestWorkspaceState } from './api'
import { Button } from '@/components/ui/Button'
import { toApiError } from '@/lib/api/client'

/**
 * META-SAAS-APP-001 — the isolated workspace where the new Meta app is tried end to end before Meta approves it.
 *
 * Created here with a NEW login address the Owner owns; its password is set from the reset email, so nothing secret passes through
 * this page. The journey below is the server's reading of real rows in that workspace (a SaaS connection, discovered accounts, a
 * project binding, a sync, metrics, a report, a disconnect); a simulated test never ticks it.
 */
const COPY = {
  ar: {
    title: 'مساحة اختبار التطبيق الجديد',
    note: 'مساحة عمل جديدة ومعزولة عن كل العملاء، تربط عبر تطبيق المنصة الجديد فقط. لا يُنسخ إليها أي اتصال أو رمز أو حساب، ولا يُستخدم التطبيق الجديد في أي مساحة أخرى قبل اعتماد ميتا.',
    email: 'بريد دخول جديد لمساحة الاختبار (يصلك عليه رابط تعيين كلمة المرور)',
    create: 'إنشاء مساحة الاختبار', close: 'إغلاق مساحة الاختبار أمام التطبيق',
    active: 'مفعّلة', inactive: 'مغلقة', login: 'الدخول', signIn: 'تسجيل الدخول إلى مساحة الاختبار',
    journey: 'رحلة الاختبار الفعلية',
    steps: {
      oauth: 'ربط عبر التطبيق الجديد (OAuth)', discovery: 'اكتشاف الحسابات الإعلانية', binding: 'ربط حساب بمشروع الاختبار',
      sync: 'مزامنة فعلية', metrics: 'وصول المقاييس', reports: 'إنشاء تقرير', disconnect: 'فصل الاتصال (بعد إعادة الربط)',
    } as Record<MetaSaasJourneyStep, string>,
    manual: 'إعادة الربط تُنفَّذ يدويًا: اربط مرة أخرى من صفحة التكاملات قبل الفصل، ويبقى الاتصال نفسه.',
    errors: { test_workspace_exists: 'مساحة الاختبار موجودة بالفعل.', email_already_registered: 'هذا البريد مسجّل لحساب قائم؛ استخدم بريدًا جديدًا.' } as Record<string, string>,
  },
  en: {
    title: 'New app test workspace',
    note: 'A new workspace, isolated from every customer, that connects through the new platform app only. No connection, token or account is copied into it, and the new app is used nowhere else before Meta approves it.',
    email: 'New login address for the test workspace (a password link is emailed to it)',
    create: 'Create the test workspace', close: 'Close the test workspace to the app',
    active: 'Active', inactive: 'Closed', login: 'Login', signIn: 'Sign in to the test workspace',
    journey: 'Real test journey',
    steps: {
      oauth: 'Connect through the new app (OAuth)', discovery: 'Ad accounts discovered', binding: 'An account bound to the test project',
      sync: 'A real sync', metrics: 'Metrics arrived', reports: 'A report created', disconnect: 'Disconnected (after a reconnect)',
    } as Record<MetaSaasJourneyStep, string>,
    manual: 'Reconnect is done by hand: connect again from Integrations before disconnecting; it stays the same connection.',
    errors: { test_workspace_exists: 'The test workspace already exists.', email_already_registered: 'That address already has an account; use a new one.' } as Record<string, string>,
  },
}

const STEPS: MetaSaasJourneyStep[] = ['oauth', 'discovery', 'binding', 'sync', 'metrics', 'reports', 'disconnect']

export function MetaSaasTestWorkspace({ state, ar }: { state: MetaSaasTestWorkspaceState; ar: boolean }) {
  const c = COPY[ar ? 'ar' : 'en']
  const qc = useQueryClient()
  const onSuccess = (data: MetaCandidateState) => qc.setQueryData(['admin', 'meta-saas'], data)
  const [email, setEmail] = useState('')
  const create = useMutation({ mutationFn: () => createMetaSaasTestWorkspace(email.trim()), onSuccess })
  const close = useMutation({ mutationFn: closeMetaSaasTestWorkspace, onSuccess })
  const error = create.error ?? close.error
  const code = error ? (toApiError(error).errors?.email?.[0] ?? null) : null

  return (
    <section data-testid="meta-saas-test-workspace" className="rounded-2xl border border-border bg-surface p-5">
      <h2 className="font-heading text-[15px] font-bold text-text-primary">{c.title}</h2>
      <p className="mt-1 max-w-3xl text-xs text-text-secondary">{c.note}</p>

      {!state.exists ? (
        <form className="mt-4 flex flex-col gap-2 sm:flex-row sm:items-end" onSubmit={(e) => { e.preventDefault(); create.mutate() }}>
          <label className="flex min-w-0 flex-1 flex-col gap-1 text-sm">
            <span className="font-semibold text-text-primary">{c.email}</span>
            <input
              data-testid="meta-saas-test-workspace-email"
              type="email"
              required
              dir="ltr"
              autoComplete="off"
              className="rounded-lg border border-border bg-surface px-3 py-2 text-sm"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
            />
          </label>
          <Button data-testid="meta-saas-test-workspace-create" type="submit" loading={create.isPending} disabled={email.trim() === ''}>{c.create}</Button>
        </form>
      ) : (
        <div className="mt-3 flex flex-col gap-3">
          <p className="text-sm text-text-primary" data-testid="meta-saas-test-workspace-status">
            <b>{state.active ? c.active : c.inactive}</b>
            {state.login_email && <span className="ms-2 text-xs text-text-secondary">{c.login}: <bdi dir="ltr">{state.login_email}</bdi></span>}
          </p>
          <ol className="grid gap-1.5" data-testid="meta-saas-test-journey" aria-label={c.journey}>
            {STEPS.map((step) => {
              const done = state.steps[step] === true
              return (
                <li key={step} data-testid={`meta-saas-journey-${step}`} data-done={done} className="flex items-center gap-2 text-sm">
                  {done ? <CheckCircle2 size={16} className="text-success" aria-hidden /> : <Circle size={16} className="text-text-muted" aria-hidden />}
                  <span className={done ? 'text-text-primary' : 'text-text-secondary'}>{c.steps[step]}</span>
                </li>
              )
            })}
          </ol>
          <p className="text-xs text-text-muted">{c.manual}</p>
          <div className="flex flex-wrap gap-2">
            <a href="/login" target="_blank" rel="noreferrer" className="inline-flex items-center rounded-lg border border-border px-3 py-2 text-sm font-semibold text-brand-700">{c.signIn}</a>
            {state.active && <Button data-testid="meta-saas-test-workspace-close" variant="secondary" loading={close.isPending} onClick={() => close.mutate()}>{c.close}</Button>}
          </div>
        </div>
      )}
      {error && <p className="mt-2 text-xs text-danger">{code && c.errors[code] ? c.errors[code] : toApiError(error).message}</p>}
    </section>
  )
}
