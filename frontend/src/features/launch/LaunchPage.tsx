import { useMemo, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { BarChart3, CheckCircle2, CircleCheck, CircleDashed, CircleDot, CircleHelp, ClipboardList, FileText, Megaphone, Rocket, Users, XCircle } from 'lucide-react'
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
import { accounts as countedAccounts } from '@/lib/counted'
import { publishPlan, savePlan, splitBudget, useLaunchOptions, useLaunchPlans, useLaunchStrategy, type AccountReadiness, type BudgetAllocation, type BudgetMode, type BusinessGoal, type EventStatus, type StrategyAccount, type LaunchPlan, type LaunchReviewRow, type LaunchSpec, type LaunchStep, type StepState } from './api'

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
  const [goal, setGoal] = useState<BusinessGoal | null>(null)
  const strategy = useLaunchStrategy(currentProjectId, goal)
  const [step, setStep] = useState(0)
  const [spec, setSpec] = useState<LaunchSpec>(empty)
  const [plan, setPlan] = useState<LaunchPlan | null>(null)
  const [confirming, setConfirming] = useState(false)
  const [spendOk, setSpendOk] = useState(false)
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
    mutationFn: () => publishPlan(currentProjectId!, plan!.id, plan!.spend?.ack_token),
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
              <GoalChooser goal={goal} onChange={(g) => { setGoal(g); setSpec((st) => ({ ...st, business_goal: g })) }} ar={ar} />
              {goal && (strategy.data?.accounts ?? []).filter((x) => spec.accounts.some((a) => a.external_account_id === x.account_id)).map((x) => (
                <div key={x.account_id} className="space-y-1">
                  <p className="flex items-center gap-2 text-xs font-semibold text-text-secondary"><ProviderMark provider={x.provider} size={16} className="shrink-0" />{x.name ?? providerLabel(x.provider, ar ? 'ar' : 'en')}</p>
                  <ProposalLine proposal={x} ar={ar} applied={spec.objectives?.[x.provider] === x.objective}
                    onApply={() => x.objective && setSpec((st) => ({ ...st, objectives: { ...(st.objectives ?? {}), [x.provider]: x.objective! } }))} />
                </div>
              ))}
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
              <BudgetPanel projectId={currentProjectId} spec={spec} accounts={accounts} ar={ar}
                onChange={(budget) => setSpec((st) => ({ ...st, budget }))}
                onApply={(amounts) => setSpec((st) => ({ ...st, accounts: st.accounts.map((a) => (amounts[a.external_account_id] !== undefined ? { ...a, daily_budget: amounts[a.external_account_id] } : a)) }))} />
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
              <ReadinessTable accounts={accounts.filter((a) => chosen(a.id) && a.readiness)} windowDays={options.data?.readiness?.window_days ?? 30} ar={ar} />
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
        <Modal open onClose={() => { setConfirming(false); setSpendOk(false) }} title={ar ? 'تأكيد النشر' : 'Confirm publishing'} size="md"
          footer={<><Button variant="secondary" onClick={() => setConfirming(false)}>{ar ? 'إلغاء' : 'Cancel'}</Button><Button loading={publish.isPending} disabled={Boolean(plan.spend?.ack_required) && !spendOk} onClick={() => publish.mutate()} data-testid="launch-confirm">{ar ? 'نعم، انشر' : 'Yes, publish'}</Button></>}>
          <div className="space-y-2 text-sm" data-testid="launch-confirm-dialog">
            <p>{ar ? `سيُنشأ «${plan.name}» عبر واجهات المنصات الرسمية — ${countedAccounts(plan.steps.length, 'ar')}.` : `«${plan.name}» will be created through the platforms’ official APIs — ${countedAccounts(plan.steps.length, 'en')}.`}</p>
            <p className="font-semibold">{spec.launch ? (ar ? 'ستُشغَّل الحملات فور إنشائها وسيبدأ الإنفاق.' : 'The campaigns will run at once and spend will start.') : (ar ? 'تُنشأ موقوفة — لا إنفاق قبل تشغيلها.' : 'Created paused — no spend before they run.')}</p>
            {plan.spend?.ack_required && (
              <div className="space-y-1.5 rounded-lg border border-border p-2" data-testid="launch-spend">
                <p className="font-semibold">{ar ? 'حدود الإنفاق اليومي التي ستُشغَّل' : 'The daily spend that will run'}</p>
                <ul className="space-y-0.5 text-xs">
                  {plan.spend.lines.map((l) => (
                    <li key={l.account_id} className="flex justify-between gap-2"><span>{accounts.find((a) => a.id === l.account_id)?.name ?? l.account_id}</span><span dir="ltr" className="font-mono">{l.daily_budget === null ? (ar ? 'بلا ميزانية محددة' : 'no budget set') : `${l.daily_budget} ${l.currency}`}</span></li>
                  ))}
                </ul>
                {plan.spend.limit_checks.length > 0 && (
                  <ul className="space-y-1 text-xs" data-testid="launch-limit-checks">
                    {plan.spend.limit_checks.map((c) => (
                      <li key={c.limit_id} data-testid={`launch-limit-${c.status}`} className={c.status === 'exceeds' ? 'text-warning' : 'text-text-secondary'}>
                        {c.status === 'exceeds'
                          ? (ar ? `يتجاوز حدّ الإنفاق (${LIMIT_SCOPE[c.scope].ar}) بمقدار ${c.over_by} ${c.currency} حتى ${c.period_ends_on}.` : `Exceeds the ${LIMIT_SCOPE[c.scope].en} spend limit by ${c.over_by} ${c.currency} until ${c.period_ends_on}.`)
                          : c.status === 'within'
                            ? (ar ? `ضمن حدّ الإنفاق (${LIMIT_SCOPE[c.scope].ar}) حتى ${c.period_ends_on}.` : `Within the ${LIMIT_SCOPE[c.scope].en} spend limit until ${c.period_ends_on}.`)
                            : (ar ? `لا يمكن مقارنته بحدّ الإنفاق (${LIMIT_SCOPE[c.scope].ar}) — عملة أو أرقام غير قابلة للمقارنة.` : `Cannot be compared with the ${LIMIT_SCOPE[c.scope].en} spend limit — different currency or no comparable spend yet.`)}
                      </li>
                    ))}
                    <li className="text-text-muted">{ar ? 'حدود الإنفاق مراقبة داخلية: المنصة لا تتوقف عندها.' : 'Spend limits are internal monitoring: the platform does not stop at them.'}</li>
                  </ul>
                )}
                <label className="flex items-start gap-2 text-xs"><input type="checkbox" className="mt-0.5 accent-brand-600" checked={spendOk} onChange={(e) => setSpendOk(e.target.checked)} data-testid="launch-spend-ack" />{ar ? 'أوافق على حدود الإنفاق هذه وأفوّض تشغيل الحملات.' : 'I approve these spend limits and authorise the campaigns to run.'}</label>
              </div>
            )}
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
                <span className="text-xs text-text-muted">{countedAccounts(p.steps.length, ar ? 'ar' : 'en')}</span>
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
      {plan.steps.some((s) => s.campaign.status === 'done') && (
        <nav aria-label={ar ? 'تابع النتائج' : 'Follow the results'} className="space-y-1.5 border-t border-border pt-3" data-testid="launch-follow">
          <p className="text-xs font-semibold text-text-secondary">{ar ? 'تابع النتائج من نفس المكان' : 'Follow the results from the same place'}</p>
          <ul className="flex flex-wrap gap-2">
            {[
              { to: '/campaigns', icon: Megaphone, ar: 'إدارة الحملات', en: 'Campaigns' },
              { to: '/analytics', icon: BarChart3, ar: 'التحليلات', en: 'Analytics' },
              { to: '/leads', icon: Users, ar: 'العملاء المحتملون', en: 'Leads' },
              { to: '/forms', icon: ClipboardList, ar: 'النماذج والتتبع', en: 'Forms & tracking' },
              { to: '/reports', icon: FileText, ar: 'التقارير', en: 'Reports' },
            ].map((l) => (
              <li key={l.to}>
                <Link to={portal(l.to)} data-testid={`launch-follow-${l.to.slice(1)}`} className="inline-flex items-center gap-1.5 rounded-lg border border-border px-2.5 py-1.5 text-xs font-semibold text-text-primary hover:border-brand-400">
                  <l.icon size={14} aria-hidden /> {ar ? l.ar : l.en}
                </Link>
              </li>
            ))}
          </ul>
        </nav>
      )}
    </div>
  )
}

const EVENT_LABEL: Record<string, { en: string; ar: string }> = {
  page_views: { en: 'PageView', ar: 'مشاهدة الصفحة' },
  add_to_cart: { en: 'AddToCart', ar: 'الإضافة للسلة' },
  leads: { en: 'Lead', ar: 'عميل محتمل' },
  purchases: { en: 'Purchase', ar: 'الشراء' },
}

/**
 * LAUNCH-READINESS-001 — which events each chosen account was seen to report, in the Tracking step where tracking is decided.
 *
 * One row per account, one small monochrome mark per event; the words live in the legend, the tooltip and the accessible name, so the
 * account list stays clean. Never a claim about the pixel itself (no official API here reads it): the empty mark means the platform
 * reported nothing for that event in the window.
 */
function ReadinessTable({ accounts, windowDays, ar }: { accounts: Array<{ id: string; name: string | null; provider: string; readiness?: AccountReadiness | null }>; windowDays: number; ar: boolean }) {
  if (accounts.length === 0) return null
  // None of these words says an event «does not exist»: the data can only show what it was seen to carry.
  const word = (s: EventStatus) => (s === 'eligible' ? (ar ? 'مؤهل للتحسين' : 'Eligible') : s === 'proven' ? (ar ? 'مثبت' : 'Proven') : s === 'not_reported' ? (ar ? 'لم يُرصد' : 'Not seen') : ar ? 'لا بيانات بعد' : 'No data yet')
  const mark = (s: EventStatus) => (s === 'eligible' ? <CircleCheck size={16} className="text-success" aria-hidden /> : s === 'proven' ? <CircleDot size={16} className="text-text-secondary" aria-hidden /> : s === 'not_reported' ? <CircleDashed size={16} className="text-text-muted" aria-hidden /> : <CircleHelp size={16} className="text-text-muted" aria-hidden />)
  const keys = Object.keys(EVENT_LABEL) as Array<keyof AccountReadiness['events']>
  return (
    <div className="space-y-2 md:col-span-2" data-testid="launch-readiness">
      <p className="text-xs font-semibold text-text-secondary">{ar ? 'ما أبلغت به المنصات من أحداث' : 'Events the platforms reported'}</p>
      <div className="overflow-x-auto">
        <table className="w-full text-xs">
          <thead>
            <tr className="text-text-muted">
              <th className="py-1 text-start font-medium" />
              {keys.map((k) => <th key={k} scope="col" className="px-2 py-1 text-center font-medium">{ar ? EVENT_LABEL[k].ar : EVENT_LABEL[k].en}</th>)}
            </tr>
          </thead>
          <tbody>
            {accounts.map((a) => (
              <tr key={a.id} className="border-t border-border" data-testid={`launch-readiness-${a.readiness!.tier}`}>
                <th scope="row" className="py-1.5 text-start font-medium text-text-primary">{a.name ?? providerLabel(a.provider, ar ? 'ar' : 'en')}</th>
                {keys.map((k) => {
                  const e = a.readiness!.events[k]
                  return (
                    <td key={k} className="px-2 py-1.5 text-center" title={`${word(e.status)} — ${ar ? `نافذة القياس بالأيام: ${windowDays}` : `measurement window, days: ${windowDays}`}`}>
                      <span data-testid={`launch-event-${a.id}-${k}`} data-status={e.status} className="inline-flex" role="img" aria-label={`${ar ? EVENT_LABEL[k].ar : EVENT_LABEL[k].en}: ${word(e.status)}`}>{mark(e.status)}</span>
                    </td>
                  )
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-text-muted" data-testid="launch-readiness-legend">
        <span className="inline-flex items-center gap-1"><CircleCheck size={13} aria-hidden /> {word('eligible')}</span>
        <span className="inline-flex items-center gap-1"><CircleDot size={13} aria-hidden /> {word('proven')}</span>
        <span className="inline-flex items-center gap-1"><CircleDashed size={13} aria-hidden /> {word('not_reported')}</span>
        <span className="inline-flex items-center gap-1"><CircleHelp size={13} aria-hidden /> {word('no_data')}</span>
      </p>
    </div>
  )
}

const GOALS: Array<{ id: BusinessGoal; en: string; ar: string }> = [
  { id: 'sales', en: 'Sales', ar: 'مبيعات' },
  { id: 'leads', en: 'Leads', ar: 'عملاء محتملون' },
  { id: 'awareness', en: 'Awareness', ar: 'وعي' },
  { id: 'traffic', en: 'Traffic', ar: 'زيارات' },
]

/** LAUNCH-JOURNEY-001 — the business goal; the proposals below follow it and change nothing until a person applies one. */
function GoalChooser({ goal, onChange, ar }: { goal: BusinessGoal | null; onChange: (g: BusinessGoal | null) => void; ar: boolean }) {
  return (
    <div className="space-y-1.5" data-testid="launch-goal">
      <p className="text-xs font-semibold text-text-secondary">{ar ? 'الهدف التجاري (اختياري — يقترح هدف كل حساب حسب ما قيس فعلًا)' : 'Business goal (optional — proposes each account’s objective from what it actually measured)'}</p>
      <div className="flex flex-wrap gap-1.5" role="group" aria-label={ar ? 'الهدف التجاري' : 'Business goal'}>
        {GOALS.map((g) => (
          <button key={g.id} type="button" aria-pressed={goal === g.id} data-testid={`launch-goal-${g.id}`}
            onClick={() => onChange(goal === g.id ? null : g.id)}
            className={`rounded-lg border px-3 py-1.5 text-xs font-semibold ${goal === g.id ? 'border-brand-500 bg-brand-500/10 text-text-primary' : 'border-border text-text-secondary hover:border-brand-400'}`}>
            {ar ? g.ar : g.en}
          </button>
        ))}
      </div>
    </div>
  )
}

const STAGE_TEXT: Record<StrategyAccount['stage'], { en: string; ar: string }> = {
  sales: { en: 'Sales campaign — Purchase is eligible', ar: 'حملة مبيعات — حدث الشراء مؤهل' },
  leads: { en: 'Lead campaign — Lead is eligible', ar: 'حملة عملاء محتملين — حدث العميل مؤهل' },
  awareness: { en: 'Awareness — needs no conversion event', ar: 'وعي — لا يحتاج حدث تحويل' },
  traffic: { en: 'Traffic — needs no conversion event', ar: 'زيارات — لا تحتاج حدث تحويل' },
  bridge: { en: 'Bridge — optimise for a lower-funnel event first', ar: 'مرحلة وسيطة — التحسين لحدث أدنى في المسار أولًا' },
  measurement_first: { en: 'Traffic first — complete the measurement before selling', ar: 'زيارات أولًا — أكمل القياس قبل البيع' },
}

const ACTION_TEXT: Record<string, { en: string; ar: string }> = {
  sync_the_account_first: { en: 'No synced results yet, so nothing can be said about any event — connect and finish syncing this account first.', ar: 'لا توجد نتائج مُزامنة بعد، فلا يمكن الحكم على أي حدث — أكمل ربط الحساب ومزامنته أولًا.' },
  check_the_platform_receives_the_purchase_event: { en: 'Check that the platform receives the Purchase event — link the store and send it if it is not being sent.', ar: 'تحقق من أن المنصة تستقبل حدث الشراء — اربط المتجر وأرسله إن لم يكن يُرسل.' },
  store_linked_but_platform_reports_no_purchase: { en: 'The store is linked but no Purchase was seen in the platform’s results — check the event it receives.', ar: 'المتجر مرتبط لكن لم يُرصد أي شراء في نتائج المنصة — تحقق من الحدث الذي تستقبله.' },
  check_the_platform_receives_the_lead_event: { en: 'Check that the platform receives the Lead event when the form is submitted.', ar: 'تحقق من أن المنصة تستقبل حدث العميل المحتمل عند إرسال النموذج.' },
  check_the_platform_receives_the_event: { en: 'Check that the platform receives this event.', ar: 'تحقق من أن المنصة تستقبل هذا الحدث.' },
}

function ProposalLine({ proposal, ar, applied, onApply }: { proposal: StrategyAccount; ar: boolean; applied: boolean; onApply: () => void }) {
  const stage = STAGE_TEXT[proposal.stage]
  return (
    <div className="mt-1.5 space-y-1 rounded-lg border border-border bg-surface-hover/40 p-2 text-xs" data-testid={`launch-proposal-${proposal.stage}`}>
      <p className="font-semibold text-text-primary">{ar ? stage.ar : stage.en}</p>
      {proposal.objective_refusal
        ? <p className="text-text-muted">{ar ? 'هذه المنصة لا تدعم هذا الهدف عبر واجهتها.' : 'This platform does not offer this objective through its API.'}</p>
        : (
          <p className="text-text-secondary">
            <span dir="ltr" className="font-mono">{proposal.objective}</span>
            {proposal.optimization_event && <> · {ar ? 'التحسين لحدث' : 'optimise for'} <span dir="ltr" className="font-mono">{proposal.optimization_event}</span></>}
          </p>
        )}
      {proposal.measurement_plan.map((m) => (
        <p key={`${m.event}-${m.action}`} className="text-text-muted">{(ACTION_TEXT[m.action] ?? ACTION_TEXT.check_the_platform_receives_the_event!)[ar ? 'ar' : 'en']}</p>
      ))}
      {proposal.objective && (
        <button type="button" onClick={onApply} disabled={applied} data-testid={`launch-apply-${proposal.account_id}`}
          className="rounded-md border border-border px-2 py-1 font-semibold text-text-primary hover:border-brand-400 disabled:opacity-60">
          {applied ? (ar ? 'مُطبَّق على الخطة' : 'Applied to the plan') : (ar ? 'استخدم هذا الهدف' : 'Use this objective')}
        </button>
      )}
    </div>
  )
}

const MODE_TEXT: Record<BudgetMode, { en: string; ar: string }> = {
  manual: { en: 'Manual — I set every budget', ar: 'يدوي — أحدد كل ميزانية بنفسي' },
  assisted: { en: 'AI-assisted — proposals I approve', ar: 'بمساعدة الذكاء الاصطناعي — اقتراحات أوافق عليها' },
  automated: { en: 'Authorised automation — needs an authorisation', ar: 'أتمتة مفوَّضة — تحتاج تفويضًا' },
}

const VIOLATION: Record<string, { en: string; ar: string }> = {
  missing_rate: { en: 'No published exchange rate for this account’s currency.', ar: 'لا يوجد سعر صرف منشور لعملة هذا الحساب.' },
  below_min: { en: 'Below this account’s minimum.', ar: 'أقل من الحد الأدنى لهذا الحساب.' },
  above_max: { en: 'Above this account’s maximum.', ar: 'أعلى من الحد الأقصى لهذا الحساب.' },
}

/**
 * LAUNCH-BUDGET-001 — one daily budget split over the chosen accounts, as percentages that total 100 and amounts that total the
 * budget to the cent, in each account's own currency. The arithmetic is the server's; this shows it and applies it on request.
 */
function BudgetPanel({ projectId, spec, accounts, ar, onChange, onApply }: {
  projectId: string
  spec: LaunchSpec
  accounts: Array<{ id: string; name: string | null; currency: string | null }>
  ar: boolean
  onChange: (b: NonNullable<LaunchSpec['budget']>) => void
  onApply: (amounts: Record<string, number>) => void
}) {
  const budget = spec.budget ?? {}
  const [total, setTotal] = useState(budget.total ? String(budget.total) : '')
  const [currency, setCurrency] = useState(budget.currency ?? accounts.find((a) => a.id === spec.accounts[0]?.external_account_id)?.currency ?? 'SAR')
  const [shares, setShares] = useState<Record<string, string>>(() => Object.fromEntries(Object.entries(budget.shares ?? {}).map(([k, v]) => [k, String(v)])))
  const [result, setResult] = useState<BudgetAllocation | null>(null)
  const [error, setError] = useState<string | null>(null)
  const mode: BudgetMode = budget.mode ?? 'manual'
  const calc = useMutation({
    mutationFn: () => splitBudget(projectId, {
      total: Number(total), currency: currency.toUpperCase(),
      accounts: spec.accounts.map((a) => ({ external_account_id: a.external_account_id, share: shares[a.external_account_id] ? Number(shares[a.external_account_id]) : null })),
    }),
    onSuccess: (r) => { setResult(r); setError(null) },
    onError: (e) => { setResult(null); setError(toApiError(e).message) },
  })
  const push = (patch: Partial<NonNullable<LaunchSpec['budget']>>) =>
    onChange({ ...budget, mode, total: Number(total) || null, currency: currency.toUpperCase(), shares: Object.fromEntries(Object.entries(shares).filter(([, v]) => v !== '').map(([k, v]) => [k, Number(v)])), ...patch })
  if (spec.accounts.length < 1) return null
  return (
    <section className="space-y-3 rounded-xl border border-border p-3" data-testid="launch-budget-panel">
      <h3 className="text-sm font-bold text-text-primary">{ar ? 'توزيع الميزانية على الحسابات' : 'Split the budget across accounts'}</h3>
      <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
        <Field label={ar ? 'الميزانية اليومية الكلية' : 'Total daily budget'}>
          <Input type="number" min={0} step="0.01" inputMode="decimal" value={total} onChange={(e) => setTotal(e.target.value)} onBlur={() => push({})} data-testid="launch-budget-total" />
        </Field>
        <Field label={ar ? 'العملة' : 'Currency'}>
          <Input dir="ltr" maxLength={3} value={currency} onChange={(e) => setCurrency(e.target.value.toUpperCase())} onBlur={() => push({})} data-testid="launch-budget-currency" />
        </Field>
        <Field label={ar ? 'وضع التحكم' : 'Control mode'}>
          <select className="h-10 w-full rounded-lg border border-border bg-surface px-2 text-sm" value={mode} onChange={(e) => push({ mode: e.target.value as BudgetMode })} data-testid="launch-budget-mode">
            {(Object.keys(MODE_TEXT) as BudgetMode[]).map((m) => <option key={m} value={m}>{ar ? MODE_TEXT[m].ar : MODE_TEXT[m].en}</option>)}
          </select>
        </Field>
      </div>
      <ul className="grid grid-cols-1 gap-2 md:grid-cols-2 [&>*]:min-w-0">
        {spec.accounts.map((a) => {
          const acc = accounts.find((x) => x.id === a.external_account_id)
          const slot = result?.slots.find((x) => x.id === a.external_account_id)
          const bad = result?.violations.filter((v) => v.id === a.external_account_id) ?? []
          return (
            <li key={a.external_account_id} className="space-y-1 rounded-lg border border-border p-2 text-xs">
              <Field label={`${acc?.name ?? ''} — ${ar ? 'النسبة % (اتركها فارغة للتقسيم المتساوي)' : 'Share % (leave blank to split evenly)'}`}>
                <Input type="number" min={0} max={100} step="0.01" inputMode="decimal" value={shares[a.external_account_id] ?? ''} onChange={(e) => setShares((s) => ({ ...s, [a.external_account_id]: e.target.value }))} onBlur={() => push({})} data-testid={`launch-share-${a.external_account_id}`} />
              </Field>
              {slot && (
                <p data-testid={`launch-split-${a.external_account_id}`} className="text-text-secondary">
                  <span dir="ltr" className="font-mono">{slot.share_pct}%</span> · <span dir="ltr" className="font-mono">{slot.amount_in_account_currency ?? '—'} {slot.account_currency}</span>
                </p>
              )}
              {bad.map((v) => <p key={v.kind} className="text-warning">{(VIOLATION[v.kind] ?? VIOLATION.missing_rate!)[ar ? 'ar' : 'en']}</p>)}
            </li>
          )
        })}
      </ul>
      {error && <p role="alert" className="text-xs text-danger" data-testid="launch-split-error">{SPLIT_ERROR[error]?.[ar ? 'ar' : 'en'] ?? error}</p>}
      <div className="flex flex-wrap gap-2">
        <Button variant="secondary" loading={calc.isPending} disabled={!(Number(total) > 0)} onClick={() => { push({}); calc.mutate() }} data-testid="launch-split-calc">{ar ? 'احسب التوزيع' : 'Calculate the split'}</Button>
        <Button disabled={!result?.ok} onClick={() => result && onApply(Object.fromEntries(result.slots.filter((x) => x.amount_in_account_currency !== null).map((x) => [x.id, x.amount_in_account_currency as number])))} data-testid="launch-split-apply">{ar ? 'طبّق على الحسابات' : 'Apply to the accounts'}</Button>
      </div>
      <p className="text-xs text-text-muted">{ar ? 'تُنشأ الحملة موقوفة بميزانية يومية على مستوى الحملة؛ لا يُنفق شيء قبل تشغيلها.' : 'The campaign is created paused with a campaign-level daily budget; nothing is spent before it runs.'}</p>
    </section>
  )
}

const SPLIT_ERROR: Record<string, { en: string; ar: string }> = {
  shares_must_sum_to_100: { en: 'The shares must total exactly 100%.', ar: 'يجب أن يكون مجموع النسب 100% بالضبط.' },
  shares_leave_nothing_for_the_rest: { en: 'The named shares leave nothing for the other accounts.', ar: 'النسب المحددة لا تترك شيئًا للحسابات الأخرى.' },
  shares_must_not_be_negative: { en: 'A share cannot be negative.', ar: 'لا يمكن أن تكون النسبة سالبة.' },
  account_not_selected: { en: 'An account is not selected for this project.', ar: 'حساب غير محدد لهذا المشروع.' },
}

const LIMIT_SCOPE: Record<'project' | 'platform' | 'account', { en: string; ar: string }> = {
  project: { en: 'project', ar: 'المشروع' },
  platform: { en: 'platform', ar: 'المنصة' },
  account: { en: 'account', ar: 'الحساب' },
}
