import { useMemo, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { CheckCircle2, Rocket, XCircle } from 'lucide-react'
import { ProviderMark } from '@/components/ProviderMark'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { PageIntro } from '@/components/ui/PageIntro'
import { Skeleton } from '@/components/ui/States'
import { ErrorSummary, FormStepper, ReviewList, SelectField, type FieldError } from '@/components/forms'
import { ProjectChooser } from '@/features/projects/ProjectChooser'
import { objectiveLabel, providerLabel } from '@/features/campaigns/labels'
import { Field } from '@/components/ui/Field'
import { DateField } from '@/components/ui/DateField'
import { toApiError } from '@/lib/api/client'
import { usePortalPath } from '@/app/portalPath'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'
import { publishPlan, savePlan, useLaunchOptions, useLaunchPlans, type LaunchPlan, type LaunchReviewRow, type LaunchSpec, type LaunchStep, type StepState } from './api'

/**
 * LAUNCH-001 — «إطلاق الحملات»: a real launch on the platforms' own APIs, not an internal draft.
 *
 * Project → accounts → objective and budget → ad group → ad → tracking → review → publish. Every step the
 * builder offers is one the server's write services perform; every refusal the review shows is the one the
 * server would raise. Campaigns are created PAUSED and run only when the reader asks and may; nothing is sent
 * before the confirmation dialog, and the results page shows the provider's own ids, statuses and errors.
 */
const STEPS = (ar: boolean) => [
  { id: 'accounts', label: ar ? 'الحسابات' : 'Accounts' },
  { id: 'objective', label: ar ? 'الهدف والميزانية' : 'Objective & budget' },
  { id: 'ad_set', label: ar ? 'المجموعة' : 'Ad group' },
  { id: 'ad', label: ar ? 'الإعلان' : 'Ad' },
  { id: 'tracking', label: ar ? 'التتبع' : 'Tracking' },
  { id: 'review', label: ar ? 'المراجعة' : 'Review' },
  { id: 'publish', label: ar ? 'النشر' : 'Publish' },
]

const PROBLEM: Record<string, { ar: string; en: string }> = {
  account_not_selected: { ar: 'الحساب غير محدد لهذا المشروع', en: 'Account not selected for this project' },
  awaiting_credentials: { ar: 'المنصة بانتظار بيانات اعتماد التطبيق', en: 'Platform awaiting app credentials' },
  not_connected: { ar: 'الاتصال غير مصرَّح — أعد ربط الحساب', en: 'Connection not authorised — reconnect' },
  not_implemented: { ar: 'غير منفَّذ لهذه المنصة بعد', en: 'Not implemented for this platform yet' },
  provider_unsupported: { ar: 'لا تسمح به المنصة عبر واجهتها', en: 'The platform does not allow it through its API' },
  no_permission: { ar: 'لا تملك الصلاحية', en: 'You lack the permission' },
  invalid_objective: { ar: 'الهدف غير متاح على هذه المنصة', en: 'Objective not offered on this platform' },
  demo: { ar: 'بيانات تجريبية — لا منصة خلفها', en: 'Demo data — no platform behind it' },
  'launch:no_permission': { ar: 'تشغيل الحملات يحتاج صلاحية الإطلاق', en: 'Running campaigns needs the launch permission' },
}
const problemText = (p: string, ar: boolean): string => {
  const [head, ...rest] = p.split(':')
  const known = PROBLEM[p] ?? PROBLEM[rest.join(':')] ?? PROBLEM[head]
  const scope = head === 'ad_set' ? (ar ? 'المجموعة: ' : 'Ad group: ') : head === 'ad' ? (ar ? 'الإعلان: ' : 'Ad: ') : ''
  return scope + (known ? (ar ? known.ar : known.en) : rest.join(':') || p)
}

const TIKTOK_CALLS = ['LEARN_MORE', 'SHOP_NOW', 'ORDER_NOW', 'SIGN_UP', 'CONTACT_US', 'BOOK_NOW', 'APPLY_NOW', 'VISIT_STORE']

const empty = (): LaunchSpec => ({ name: '', objective: '', objectives: {}, accounts: [], ad_set: null, ad: null, tracking: { url: '' }, launch: false })

export function LaunchPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const portal = usePortalPath()
  const qc = useQueryClient()
  const { currentProjectId } = useProject()
  const options = useLaunchOptions(currentProjectId)
  const plans = useLaunchPlans(currentProjectId)
  const [step, setStep] = useState(0)
  const [spec, setSpec] = useState<LaunchSpec>(empty)
  const [plan, setPlan] = useState<LaunchPlan | null>(null)
  const [confirming, setConfirming] = useState(false)
  const [errors, setErrors] = useState<FieldError[]>([])

  const title = ar ? 'إطلاق الحملات' : 'Launch'
  const purpose = ar
    ? 'أنشئ حملة على منصة أو أكثر من مكان واحد عبر واجهات المنصات الرسمية — تُنشأ موقوفة، ولا يُرسل شيء قبل تأكيدك.'
    : 'Create a campaign on one or more platforms from one place, through the platforms’ official APIs — created paused, and nothing is sent before you confirm.'

  const providers = useMemo(() => {
    const selected = new Set(spec.accounts.map((a) => a.external_account_id))
    const keys = new Set((options.data?.accounts ?? []).filter((a) => selected.has(a.id)).map((a) => a.provider))
    return (options.data?.providers ?? []).filter((p) => keys.has(p.provider))
  }, [spec.accounts, options.data])

  const save = useMutation({
    mutationFn: () => savePlan(currentProjectId!, spec, plan?.status === 'draft' ? plan.id : null),
    onSuccess: (p) => { setPlan(p); setErrors([]); setStep(5); void qc.invalidateQueries({ queryKey: ['launch', 'plans'] }) },
    onError: (e) => {
      const err = toApiError(e)
      setErrors(Object.entries(err.errors ?? {}).map(([field, m]) => ({ field, message: Array.isArray(m) ? String(m[0]) : String(m) })).concat(err.errors ? [] : [{ field: 'plan', message: err.message }]))
    },
  })
  const publish = useMutation({
    mutationFn: () => publishPlan(currentProjectId!, plan!.id),
    onSuccess: (p) => { setPlan(p); setConfirming(false); setStep(6); void qc.invalidateQueries({ queryKey: ['launch', 'plans'] }); void qc.invalidateQueries({ queryKey: ['project', currentProjectId, 'campaigns'] }) },
  })

  if (!currentProjectId) {
    return <div className="space-y-6"><PageIntro title={title} purpose={purpose} /><ProjectChooser purpose="recommendations" /></div>
  }

  const accounts = options.data?.accounts ?? []
  const usable = accounts.filter((a) => a.state === 'available')
  const chosen = (id: string) => spec.accounts.some((a) => a.external_account_id === id)
  const toggleAccount = (id: string) => setSpec((s) => ({ ...s, accounts: chosen(id) ? s.accounts.filter((a) => a.external_account_id !== id) : [...s.accounts, { external_account_id: id, daily_budget: null }] }))
  const setBudget = (id: string, v: string) => setSpec((s) => ({ ...s, accounts: s.accounts.map((a) => (a.external_account_id === id ? { ...a, daily_budget: v === '' ? null : Number(v) } : a)) }))
  const canNext = [
    spec.accounts.length > 0,
    spec.name.trim() !== '' && providers.every((p) => (spec.objectives?.[p.provider] ?? spec.objective) !== ''),
    spec.ad_set === null || (spec.ad_set.name.trim() !== ''),
    spec.ad === null || spec.ad.name.trim() !== '',
    true, true, true,
  ]
  const problemsTotal = (plan?.review ?? []).reduce((n, r) => n + r.problems.length, 0)
  const publishable = plan !== null && plan.status === 'draft' && problemsTotal === 0

  return (
    <div className="space-y-4" data-testid="launch-page">
      <PageIntro title={title} purpose={purpose} />
      <FormStepper steps={STEPS(ar)} current={step} onStepClick={(i) => { if (i < step || canNext.slice(0, i).every(Boolean)) setStep(i) }} />
      <ErrorSummary errors={errors} title={ar ? 'لم تُحفظ الخطة' : 'The plan was not saved'} />

      {options.isPending ? <Skeleton className="h-40" /> : options.isError ? (
        <p role="alert" className="text-sm text-danger">{ar ? 'تعذّرت قراءة حسابات المشروع.' : 'The project’s accounts could not be read.'}</p>
      ) : (
        <section className="rounded-2xl border border-border bg-surface p-4" data-testid={`launch-step-${STEPS(ar)[step]!.id}`}>
          {step === 0 && (
            <div className="space-y-3">
              <h2 className="text-sm font-bold text-text-primary">{ar ? 'اختر الحسابات الإعلانية' : 'Choose the ad accounts'}</h2>
              {accounts.length === 0 && (
                <p className="text-sm text-text-muted">{ar ? 'لا حسابات محددة لهذا المشروع. ' : 'No accounts are selected for this project. '}
                  <Link to={portal(`/projects/${currentProjectId}/integrations`)} className="font-bold text-brand-600 hover:underline">{ar ? 'اختر حسابات المشروع' : 'Choose the project’s accounts'}</Link>
                </p>
              )}
              <ul className="grid grid-cols-1 gap-2 md:grid-cols-2 [&>*]:min-w-0" data-testid="launch-accounts">
                {accounts.map((a) => (
                  <li key={a.id}>
                    <label className={`flex items-center gap-3 rounded-xl border p-3 ${a.state !== 'available' ? 'opacity-70' : chosen(a.id) ? 'border-brand-500 bg-brand-500/10' : 'border-border hover:border-brand-400'}`}>
                      <input type="checkbox" className="accent-brand-600" disabled={a.state !== 'available'} checked={chosen(a.id)} onChange={() => toggleAccount(a.id)} data-testid={`launch-account-${a.id}`} />
                      <ProviderMark provider={a.provider} size={22} className="shrink-0" />
                      <span className="flex min-w-0 flex-col">
                        <span className="truncate text-sm font-semibold text-text-primary">{a.name ?? providerLabel(a.provider, ar ? 'ar' : 'en')}</span>
                        <span className="text-xs text-text-secondary">{providerLabel(a.provider, ar ? 'ar' : 'en')}{a.currency ? ` · ${a.currency}` : ''}</span>
                      </span>
                      <span className="ms-auto">
                        {a.state === 'available'
                          ? <Badge tone="success">{ar ? 'جاهز' : 'Ready'}</Badge>
                          : <Badge tone="warning">{problemText(a.state, ar)}</Badge>}
                      </span>
                    </label>
                  </li>
                ))}
              </ul>
              {usable.length === 0 && accounts.length > 0 && (
                <p className="text-xs text-text-muted" data-testid="launch-no-usable">{ar ? 'لا حساب جاهز للنشر الآن — الحالات أعلاه تقول السبب.' : 'No account is ready to publish to — the states above say why.'}</p>
              )}
            </div>
          )}

          {step === 1 && (
            <div className="space-y-4">
              <Field label={ar ? 'اسم الحملة' : 'Campaign name'}>
                <Input value={spec.name} onChange={(e) => setSpec((s) => ({ ...s, name: e.target.value }))} data-testid="launch-name" />
              </Field>
              <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                {providers.map((p) => (
                  <SelectField
                    key={p.provider}
                    label={`${ar ? 'الهدف' : 'Objective'} — ${providerLabel(p.provider, ar ? 'ar' : 'en')}`}
                    value={spec.objectives?.[p.provider] ?? ''}
                    options={p.objectives.map((o) => ({ value: o, label: objectiveLabel(o, ar ? 'ar' : 'en') }))}
                    placeholder={ar ? 'اختر الهدف' : 'Choose an objective'}
                    onChange={(v) => setSpec((s) => ({ ...s, objective: s.objective || (v ?? ''), objectives: { ...(s.objectives ?? {}), [p.provider]: v ?? '' } }))}
                  />
                ))}
              </div>
              <h3 className="text-sm font-bold text-text-primary">{ar ? 'الميزانية اليومية لكل حساب' : 'Daily budget per account'}</h3>
              <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                {spec.accounts.map((a) => {
                  const acc = accounts.find((x) => x.id === a.external_account_id)
                  return (
                    <Field key={a.external_account_id} label={`${acc?.name ?? ''} (${acc?.currency ?? ''})`}>
                      <Input type="number" min={0} step="0.01" inputMode="decimal" value={a.daily_budget ?? ''} onChange={(e) => setBudget(a.external_account_id, e.target.value)} data-testid={`launch-budget-${a.external_account_id}`} />
                    </Field>
                  )
                })}
              </div>
              <p className="text-xs text-text-muted">{ar ? 'تُنشأ الحملة موقوفة بميزانية يومية على مستوى الحملة؛ لا يُنفق شيء قبل تشغيلها.' : 'The campaign is created paused with a campaign-level daily budget; nothing is spent before it runs.'}</p>
            </div>
          )}

          {step === 2 && (
            <div className="space-y-4">
              <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="accent-brand-600" checked={spec.ad_set !== null} onChange={(e) => setSpec((s) => ({ ...s, ad_set: e.target.checked ? { name: '', countries: ['SA'], optimization_goal: {}, bid_amount: {} } : null, ad: e.target.checked ? s.ad : null }))} data-testid="launch-adset-on" /> {ar ? 'أنشئ مجموعة إعلانية مع الحملة' : 'Create an ad group with the campaign'}</label>
              {spec.ad_set && (
                <>
                  <Field label={ar ? 'اسم المجموعة' : 'Ad group name'}>
                    <Input value={spec.ad_set.name} onChange={(e) => setSpec((s) => ({ ...s, ad_set: { ...s.ad_set!, name: e.target.value } }))} data-testid="launch-adset-name" />
                  </Field>
                  <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <Field label={ar ? 'الدول (رموز ISO مفصولة بفاصلة)' : 'Countries (ISO codes, comma-separated)'}>
                      <Input value={(spec.ad_set.countries ?? []).join(', ')} onChange={(e) => setSpec((s) => ({ ...s, ad_set: { ...s.ad_set!, countries: e.target.value.split(',').map((c) => c.trim().toUpperCase()).filter(Boolean) } }))} data-testid="launch-adset-countries" />
                    </Field>
                    <Field label={ar ? 'ميزانية المجموعة اليومية (اختياري)' : 'Ad group daily budget (optional)'}>
                      <Input type="number" min={0} step="0.01" value={spec.ad_set.daily_budget ?? ''} onChange={(e) => setSpec((s) => ({ ...s, ad_set: { ...s.ad_set!, daily_budget: e.target.value === '' ? null : Number(e.target.value) } }))} data-testid="launch-adset-budget" />
                    </Field>
                    <Field label={ar ? 'تاريخ البدء (اختياري)' : 'Start date (optional)'}>
                      <DateField value={spec.ad_set.starts_at ?? ''} onChange={(v) => setSpec((s) => ({ ...s, ad_set: { ...s.ad_set!, starts_at: v || null } }))} />
                    </Field>
                  </div>
                  {providers.map((p) => (
                    <div key={p.provider} className="grid grid-cols-1 gap-3 rounded-xl border border-border p-3 md:grid-cols-2" data-testid={`launch-adset-${p.provider}`}>
                      <div className="flex items-center gap-2 md:col-span-2"><ProviderMark provider={p.provider} size={18} /><span className="text-sm font-semibold">{providerLabel(p.provider, ar ? 'ar' : 'en')}</span>
                        {p.ad_set_refusal && <Badge tone="warning">{problemText(p.ad_set_refusal, ar)}</Badge>}
                      </div>
                      {p.optimization_goals.length > 0 && (
                        <SelectField label={ar ? 'هدف التحسين' : 'Optimisation goal'} value={spec.ad_set!.optimization_goal?.[p.provider] ?? ''} placeholder={ar ? 'اختر' : 'Choose'}
                          options={p.optimization_goals.map((g) => ({ value: g, label: g }))}
                          onChange={(v) => setSpec((s) => ({ ...s, ad_set: { ...s.ad_set!, optimization_goal: { ...(s.ad_set!.optimization_goal ?? {}), [p.provider]: v ?? '' } } }))} />
                      )}
                      {p.bid_required && (
                        <Field label={ar ? 'المزايدة (مطلوبة)' : 'Bid (required)'}>
                          <Input type="number" min={0} step="0.01" value={spec.ad_set!.bid_amount?.[p.provider] ?? ''} onChange={(e) => setSpec((s) => ({ ...s, ad_set: { ...s.ad_set!, bid_amount: { ...(s.ad_set!.bid_amount ?? {}), [p.provider]: Number(e.target.value) } } }))} />
                        </Field>
                      )}
                      {!p.countries_in_ad_set && <p className="text-xs text-text-muted md:col-span-2">{ar ? 'الاستهداف الجغرافي يُضبط بعد الإنشاء على هذه المنصة.' : 'Geographic targeting is set after creation on this platform.'}</p>}
                    </div>
                  ))}
                </>
              )}
            </div>
          )}

          {step === 3 && (
            <div className="space-y-4">
              {spec.ad_set === null ? (
                <p className="text-sm text-text-muted">{ar ? 'الإعلان يحتاج مجموعة — فعّل المجموعة في الخطوة السابقة.' : 'An ad needs an ad group — switch the ad group on in the previous step.'}</p>
              ) : (
                <>
                  <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="accent-brand-600" checked={spec.ad !== null} onChange={(e) => setSpec((s) => ({ ...s, ad: e.target.checked ? { name: '', creative_id: {} } : null }))} data-testid="launch-ad-on" /> {ar ? 'أنشئ إعلانًا داخل المجموعة' : 'Create an ad in the ad group'}</label>
                  {spec.ad && (
                    <>
                      <Field label={ar ? 'اسم الإعلان' : 'Ad name'}><Input value={spec.ad.name} onChange={(e) => setSpec((s) => ({ ...s, ad: { ...s.ad!, name: e.target.value } }))} data-testid="launch-ad-name" /></Field>
                      {providers.map((p) => (
                        <div key={p.provider} className="space-y-3 rounded-xl border border-border p-3" data-testid={`launch-ad-${p.provider}`}>
                          <div className="flex items-center gap-2"><ProviderMark provider={p.provider} size={18} /><span className="text-sm font-semibold">{providerLabel(p.provider, ar ? 'ar' : 'en')}</span>
                            {p.ad_refusal && <Badge tone="warning">{problemText(p.ad_refusal, ar)}</Badge>}
                          </div>
                          {p.ad_fields === 'search_ad' ? (
                            <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                              <Field label={ar ? 'العناوين (3–15، سطر لكل عنوان)' : 'Headlines (3–15, one per line)'}><textarea className="w-full rounded-lg border border-border bg-surface p-2 text-sm" rows={4} value={(spec.ad!.google?.headlines ?? []).join('\n')} onChange={(e) => setSpec((s) => ({ ...s, ad: { ...s.ad!, google: { headlines: e.target.value.split('\n'), descriptions: s.ad!.google?.descriptions ?? [] } } }))} /></Field>
                              <Field label={ar ? 'الأوصاف (2–4، سطر لكل وصف)' : 'Descriptions (2–4, one per line)'}><textarea className="w-full rounded-lg border border-border bg-surface p-2 text-sm" rows={4} value={(spec.ad!.google?.descriptions ?? []).join('\n')} onChange={(e) => setSpec((s) => ({ ...s, ad: { ...s.ad!, google: { headlines: s.ad!.google?.headlines ?? [], descriptions: e.target.value.split('\n') } } }))} /></Field>
                            </div>
                          ) : (
                            <ul className="grid grid-cols-2 gap-2 md:grid-cols-4 [&>*]:min-w-0">
                              {(options.data?.creatives[p.provider] ?? []).map((c) => (
                                <li key={c.id}>
                                  <button type="button" onClick={() => setSpec((s) => ({ ...s, ad: { ...s.ad!, creative_id: { ...(s.ad!.creative_id ?? {}), [p.provider]: c.id } } }))}
                                    className={`flex w-full flex-col gap-1 rounded-xl border p-2 text-start ${spec.ad!.creative_id?.[p.provider] === c.id ? 'border-brand-500 bg-brand-500/10' : 'border-border hover:border-brand-400'}`}
                                    data-testid={`launch-creative-${c.id}`}>
                                    {c.thumbnail_url ? <img src={c.thumbnail_url} alt="" className="aspect-video w-full rounded-lg object-cover" /> : <div className="aspect-video w-full rounded-lg bg-surface-secondary" />}
                                    <span className="truncate text-xs font-semibold text-text-primary">{c.name ?? c.id}</span>
                                  </button>
                                </li>
                              ))}
                              {(options.data?.creatives[p.provider] ?? []).length === 0 && <li className="col-span-full text-xs text-text-muted">{ar ? 'لا إبداعات مزامَنة لهذه المنصة — تُنشأ الإعلانات من إبداع سبق مزامنته.' : 'No synced creatives on this platform — ads are made from an already-synced creative.'}</li>}
                            </ul>
                          )}
                          {p.ad_fields === 'tiktok_ad' && (
                            <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                              <Field label={ar ? 'نص الإعلان' : 'Ad text'}><Input maxLength={100} value={spec.ad!.tiktok?.ad_text ?? ''} onChange={(e) => setSpec((s) => ({ ...s, ad: { ...s.ad!, tiktok: { ad_text: e.target.value, call_to_action: s.ad!.tiktok?.call_to_action ?? 'LEARN_MORE' } } }))} /></Field>
                              <SelectField label={ar ? 'زر الإجراء' : 'Call to action'} value={spec.ad!.tiktok?.call_to_action ?? 'LEARN_MORE'} options={TIKTOK_CALLS.map((c) => ({ value: c, label: c }))} onChange={(v) => setSpec((s) => ({ ...s, ad: { ...s.ad!, tiktok: { ad_text: s.ad!.tiktok?.ad_text ?? '', call_to_action: v ?? 'LEARN_MORE' } } }))} />
                            </div>
                          )}
                        </div>
                      ))}
                    </>
                  )}
                </>
              )}
            </div>
          )}

          {step === 4 && (
            <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
              <div className="md:col-span-2"><Field label={ar ? 'رابط الوجهة (https)' : 'Destination URL (https)'}>
                <Input value={spec.tracking?.url ?? ''} onChange={(e) => setSpec((s) => ({ ...s, tracking: { ...(s.tracking ?? {}), url: e.target.value } }))} data-testid="launch-url" />
              </Field></div>
              <Field label="utm_source"><Input value={spec.tracking?.utm_source ?? ''} onChange={(e) => setSpec((s) => ({ ...s, tracking: { ...(s.tracking ?? {}), utm_source: e.target.value } }))} /></Field>
              <Field label="utm_medium"><Input value={spec.tracking?.utm_medium ?? ''} onChange={(e) => setSpec((s) => ({ ...s, tracking: { ...(s.tracking ?? {}), utm_medium: e.target.value } }))} /></Field>
              <Field label="utm_campaign"><Input value={spec.tracking?.utm_campaign ?? ''} onChange={(e) => setSpec((s) => ({ ...s, tracking: { ...(s.tracking ?? {}), utm_campaign: e.target.value } }))} /></Field>
              <p className="text-xs text-text-muted md:col-span-2" dir="ltr" data-testid="launch-url-preview">{composed(spec) ?? (ar ? '— لا وجهة' : '— no destination')}</p>
            </div>
          )}

          {step === 5 && (
            <div className="space-y-4">
              {plan === null || plan.status !== 'draft' ? (
                <p className="text-sm text-text-muted">{ar ? 'احفظ الخطة لمراجعتها.' : 'Save the plan to review it.'}</p>
              ) : (
                <>
                  <ReviewList items={[
                    { label: ar ? 'الحملة' : 'Campaign', value: plan.name },
                    { label: ar ? 'الحسابات' : 'Accounts', value: String(plan.steps.length) },
                    { label: ar ? 'المجموعة' : 'Ad group', value: plan.spec.ad_set?.name ?? (ar ? 'بدون' : 'None') },
                    { label: ar ? 'الإعلان' : 'Ad', value: plan.spec.ad?.name ?? (ar ? 'بدون' : 'None') },
                    { label: ar ? 'الوجهة' : 'Destination', value: composed(plan.spec) ?? '—' },
                  ]} />
                  <ul className="space-y-2" data-testid="launch-review">
                    {(plan.review ?? []).map((r) => <ReviewRow key={r.account_id} row={r} ar={ar} />)}
                  </ul>
                  {options.data?.launch_permitted && (
                    <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="accent-brand-600" checked={spec.launch} onChange={(e) => setSpec((s) => ({ ...s, launch: e.target.checked }))} data-testid="launch-run" /> {ar ? 'شغّل الحملات فور إنشائها (يبدأ الإنفاق)' : 'Run the campaigns as soon as they are created (spend starts)'}</label>
                  )}
                  {!spec.launch && <p className="text-xs text-text-muted">{ar ? 'تُنشأ الحملات موقوفة؛ تشغيلها قرار لاحق من صفحة الحملة.' : 'Campaigns are created paused; running them is a later decision from the campaign page.'}</p>}
                </>
              )}
            </div>
          )}

          {step === 6 && plan && <Results plan={plan} ar={ar} projectId={currentProjectId} accounts={accounts} onRetry={() => publish.mutate()} retrying={publish.isPending} />}
        </section>
      )}

      <div className="flex flex-wrap items-center justify-between gap-2">
        <Button variant="secondary" disabled={step === 0 || step === 6} onClick={() => setStep((s) => Math.max(0, s - 1))}>{ar ? 'السابق' : 'Back'}</Button>
        <div className="flex gap-2">
          {step < 4 && <Button disabled={!canNext[step]} onClick={() => setStep((s) => s + 1)} data-testid="launch-next">{ar ? 'التالي' : 'Next'}</Button>}
          {step === 4 && <Button loading={save.isPending} onClick={() => save.mutate()} data-testid="launch-save">{ar ? 'حفظ ومراجعة' : 'Save & review'}</Button>}
          {step === 5 && (
            <>
              <Button variant="secondary" loading={save.isPending} onClick={() => save.mutate()}>{ar ? 'إعادة الحفظ' : 'Save again'}</Button>
              <Button disabled={!publishable} onClick={() => setConfirming(true)} data-testid="launch-publish">
                <Rocket size={14} aria-hidden /> {ar ? 'النشر على المنصات' : 'Publish to the platforms'}
              </Button>
            </>
          )}
        </div>
      </div>
      {step === 5 && plan && plan.status === 'draft' && problemsTotal > 0 && (
        <p className="text-xs text-warning" data-testid="launch-blocked">{ar ? `لا يمكن النشر: ${problemsTotal} مشكلة في المراجعة أعلاه.` : `Cannot publish: ${problemsTotal} problem(s) in the review above.`}</p>
      )}

      {confirming && plan && (
        <Modal open onClose={() => setConfirming(false)} title={ar ? 'تأكيد النشر' : 'Confirm publishing'} size="md"
          footer={<><Button variant="secondary" onClick={() => setConfirming(false)}>{ar ? 'إلغاء' : 'Cancel'}</Button><Button loading={publish.isPending} onClick={() => publish.mutate()} data-testid="launch-confirm">{ar ? 'نعم، انشر' : 'Yes, publish'}</Button></>}>
          <div className="space-y-2 text-sm" data-testid="launch-confirm-dialog">
            <p>{ar ? `سيُنشأ «${plan.name}» على ${plan.steps.length} حساب(ات) عبر واجهات المنصات الرسمية.` : `«${plan.name}» will be created on ${plan.steps.length} account(s) through the platforms’ official APIs.`}</p>
            <p className="font-semibold">{spec.launch ? (ar ? 'ستُشغَّل الحملات فور إنشائها وسيبدأ الإنفاق.' : 'The campaigns will run at once and spend will start.') : (ar ? 'تُنشأ موقوفة — لا إنفاق قبل تشغيلها.' : 'Created paused — no spend before they run.')}</p>
            {publish.isError && <p className="text-danger">{toApiError(publish.error).message}</p>}
          </div>
        </Modal>
      )}

      <section className="rounded-2xl border border-border bg-surface p-4" data-testid="launch-history">
        <h2 className="mb-2 text-sm font-bold text-text-primary">{ar ? 'سجل الإطلاق' : 'Launch history'}</h2>
        {(plans.data ?? []).length === 0 ? <p className="text-xs text-text-muted">{ar ? 'لا خطط بعد.' : 'No plans yet.'}</p> : (
          <ul className="divide-y divide-border">
            {(plans.data ?? []).map((p) => (
              <li key={p.id} className="flex flex-wrap items-center gap-3 py-2 text-sm">
                <span className="font-semibold text-text-primary">{p.name}</span>
                <StatusBadge status={p.status} ar={ar} />
                <span className="text-xs text-text-muted">{p.steps.length} {ar ? 'حساب' : 'account(s)'}</span>
                {p.unified_campaign_id && <Link to={portal(`/campaigns/${currentProjectId}/${p.unified_campaign_id}`)} className="text-xs font-bold text-brand-600 hover:underline">{ar ? 'فتح الحملة' : 'Open campaign'}</Link>}
                {p.status !== 'published' && <button type="button" className="text-xs font-bold text-brand-600 hover:underline" onClick={() => { setPlan(p); setSpec({ ...p.spec, launch: p.launch }); setStep(p.status === 'draft' ? 5 : 6) }}>{ar ? 'فتح' : 'Open'}</button>}
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  )
}

function composed(spec: LaunchSpec): string | null {
  const t = spec.tracking
  if (!t?.url) return null
  const params = [['utm_source', t.utm_source], ['utm_medium', t.utm_medium], ['utm_campaign', t.utm_campaign]].filter((p): p is [string, string] => Boolean(p[1]))
  if (params.length === 0) return t.url
  return t.url + (t.url.includes('?') ? '&' : '?') + params.map(([k, v]) => `${k}=${encodeURIComponent(v)}`).join('&')
}

function StatusBadge({ status, ar }: { status: LaunchPlan['status']; ar: boolean }) {
  const map: Record<LaunchPlan['status'], { ar: string; en: string; tone: 'success' | 'warning' | 'danger' | 'neutral' | 'info' }> = {
    draft: { ar: 'مسودة', en: 'Draft', tone: 'neutral' },
    publishing: { ar: 'قيد النشر', en: 'Publishing', tone: 'info' },
    published: { ar: 'منشورة', en: 'Published', tone: 'success' },
    partial: { ar: 'نُشرت جزئيًا', en: 'Partially published', tone: 'warning' },
    failed: { ar: 'لم تُنشر', en: 'Not published', tone: 'danger' },
  }
  const m = map[status]
  return <Badge tone={m.tone} data-testid={`launch-status-${status}`}>{ar ? m.ar : m.en}</Badge>
}

function ReviewRow({ row, ar }: { row: LaunchReviewRow; ar: boolean }) {
  return (
    <li className="flex flex-wrap items-center gap-2 rounded-xl border border-border p-3" data-testid={`launch-review-${row.account_id}`}>
      <ProviderMark provider={row.provider} size={18} />
      <span className="text-sm font-semibold text-text-primary">{row.account_name ?? providerLabel(row.provider, ar ? 'ar' : 'en')}</span>
      {row.problems.length === 0
        ? <Badge tone="success">{ar ? 'جاهز للنشر' : 'Ready to publish'}</Badge>
        : row.problems.map((p) => <Badge key={p} tone="warning">{problemText(p, ar)}</Badge>)}
    </li>
  )
}

function StepPill({ label, state, ar }: { label: string; state: StepState | null; ar: boolean }) {
  if (state === null) return null
  const tone = state.status === 'done' ? 'success' : state.status === 'failed' ? 'danger' : 'neutral'
  return (
    <span className="inline-flex flex-wrap items-center gap-1 text-xs">
      <Badge tone={tone}>{label}{state.external_id ? ` · ${state.external_id}` : ''}</Badge>
      {state.status === 'failed' && <span className="text-danger">{state.refusal ? problemText(state.refusal, ar) : state.error}</span>}
    </span>
  )
}

function Results({ plan, ar, projectId, accounts, onRetry, retrying }: { plan: LaunchPlan; ar: boolean; projectId: string; accounts: Array<{ id: string; name: string | null; provider: string }>; onRetry: () => void; retrying: boolean }) {
  const portal = usePortalPath()
  const name = (s: LaunchStep) => accounts.find((a) => a.id === s.account_id)?.name ?? (s.provider ? providerLabel(s.provider, ar ? 'ar' : 'en') : s.account_id)
  return (
    <div className="space-y-3" data-testid="launch-results">
      <div className="flex flex-wrap items-center gap-2">
        {plan.status === 'published' ? <CheckCircle2 className="text-success" size={18} aria-hidden /> : <XCircle className={plan.status === 'partial' ? 'text-warning' : 'text-danger'} size={18} aria-hidden />}
        <StatusBadge status={plan.status} ar={ar} />
        {plan.unified_campaign_id && <Link to={portal(`/campaigns/${projectId}/${plan.unified_campaign_id}`)} className="text-sm font-bold text-brand-600 hover:underline" data-testid="launch-open-campaign">{ar ? 'فتح الحملة في كامبينز هب' : 'Open the campaign in CampaignsHub'}</Link>}
      </div>
      <ul className="space-y-2">
        {plan.steps.map((s) => (
          <li key={s.account_id} className="flex flex-wrap items-center gap-2 rounded-xl border border-border p-3" data-testid={`launch-result-${s.account_id}`}>
            {s.provider && <ProviderMark provider={s.provider} size={18} />}
            <span className="text-sm font-semibold text-text-primary">{name(s)}</span>
            <StepPill label={ar ? 'الحملة' : 'Campaign'} state={s.campaign} ar={ar} />
            <StepPill label={ar ? 'المجموعة' : 'Ad group'} state={s.ad_set} ar={ar} />
            <StepPill label={ar ? 'الإعلان' : 'Ad'} state={s.ad} ar={ar} />
            {plan.launch && s.campaign.status === 'done' && (
              s.launched ? <Badge tone="success">{ar ? 'تعمل' : 'Running'}</Badge> : <Badge tone="warning">{ar ? 'موقوفة' : 'Paused'}{s.launch_message ? ` — ${s.launch_message}` : ''}</Badge>
            )}
          </li>
        ))}
      </ul>
      {plan.status !== 'published' && <Button loading={retrying} onClick={onRetry} data-testid="launch-retry">{ar ? 'أعد المحاولة للخطوات المتبقية' : 'Retry the remaining steps'}</Button>}
      <p className="text-xs text-text-muted">{ar ? 'المعرّفات والحالات أعلاه هي ما أكّدته المنصات نفسها.' : 'The ids and states above are what the platforms themselves confirmed.'}</p>
    </div>
  )
}
