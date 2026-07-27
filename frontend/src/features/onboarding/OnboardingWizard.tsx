import { useState, type ReactElement } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { ArrowRight, Building2, Check, Loader2, Rocket, User, Users } from 'lucide-react'
import { completeOnboarding, setAccountType, setFirstClient, setFirstProject, setService, setWorkspace } from './api'
import { OnboardingShell } from './OnboardingShell'
import { fetchCurrentUser } from '@/features/auth/api'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'

const ACCOUNT_TYPES = [
  { key: 'freelancer', ar: 'مستقل', en: 'Freelancer', icon: User },
  { key: 'agency', ar: 'وكالة', en: 'Agency', icon: Users },
  { key: 'in_house_team', ar: 'فريق تسويق داخلي', en: 'In-house marketing team', icon: Users },
  { key: 'brand', ar: 'علامة تجارية', en: 'Brand', icon: Building2 },
  { key: 'self_serve_company', ar: 'شركة تستخدم النظام ذاتيًا', en: 'Self-serve company', icon: Building2 },
]
const SERVICES = [
  { key: 'paid_media', ar: 'إدارة الحملات الإعلانية المدفوعة', en: 'Paid advertising management' },
  { key: 'influencer_marketing', ar: 'إدارة حملات المؤثرين وUGC', en: 'Influencer & UGC management' },
  { key: 'combined', ar: 'إدارة الخدمتين معًا', en: 'Both services' },
] as const

export function OnboardingWizard() {
  const ar = useUi((s) => s.locale) === 'ar'
  const navigate = useNavigate()
  const setUser = useAuth((s) => s.setUser)
  const account = useAuth((s) => s.user?.account)
  const step = account?.onboarding.step ?? 'account_type'

  const refresh = async () => { const u = await fetchCurrentUser(); setUser(u) }

  // All mutations are declared unconditionally (hooks rules) before any step branch returns.
  const accountType = useMutation({ mutationFn: setAccountType, onSuccess: refresh })
  const service = useMutation({ mutationFn: setService, onSuccess: refresh })
  const finish = useMutation({ mutationFn: completeOnboarding, onSuccess: async () => { await refresh(); navigate('/dashboard', { replace: true }) } })

  const label = 'text-xs font-semibold text-text-secondary'
  const field = 'h-11 w-full rounded-xl border border-border bg-surface px-3.5 text-sm outline-none focus:border-brand-500'

  const StepBadge = ({ n, total }: { n: number; total: number }) => (
    <div className="mb-4 flex items-center gap-1.5">
      {Array.from({ length: total }).map((_, i) => <span key={i} className={`h-1.5 flex-1 rounded-full ${i < n ? 'bg-brand-500' : 'bg-surface-secondary'}`} />)}
    </div>
  )

  // ---- account_type ----
  if (step === 'account_type') {
    return (
      <OnboardingShell title={ar ? 'التهيئة' : 'Onboarding'}>
        <StepBadge n={1} total={5} />
        <h1 className="font-heading text-xl font-extrabold text-text-primary">{ar ? 'ما نوع حسابك؟' : 'What kind of account is this?'}</h1>
        <p className="mt-1 text-sm text-text-secondary">{ar ? 'يحدّد ذلك مساحة عملك والقائمة المناسبة لك.' : 'This tailors your workspace and menu.'}</p>
        <div className="mt-4 grid gap-2.5">
          {ACCOUNT_TYPES.map((a) => (
            <button key={a.key} disabled={accountType.isPending} onClick={() => accountType.mutate(a.key)}
              className="flex items-center gap-3 rounded-xl border border-border bg-surface p-3.5 text-start hover:border-brand-400 disabled:opacity-60">
              <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-primary-soft text-brand-600"><a.icon size={18} /></span>
              <span className="font-semibold text-text-primary">{ar ? a.ar : a.en}</span>
              <ArrowRight size={16} className="ms-auto text-text-muted rtl:rotate-180" />
            </button>
          ))}
        </div>
      </OnboardingShell>
    )
  }

  // ---- service ----
  if (step === 'service') {
    return (
      <OnboardingShell title={ar ? 'التهيئة' : 'Onboarding'}>
        <StepBadge n={2} total={5} />
        <h1 className="font-heading text-xl font-extrabold text-text-primary">{ar ? 'ما الخدمة التي تديرها؟' : 'Which service do you run?'}</h1>
        <div className="mt-4 grid gap-2.5">
          {SERVICES.map((s) => (
            <button key={s.key} disabled={service.isPending} onClick={() => service.mutate(s.key)}
              className="flex items-center gap-3 rounded-xl border border-border bg-surface p-4 text-start hover:border-brand-400 disabled:opacity-60">
              <span className="font-semibold text-text-primary">{ar ? s.ar : s.en}</span>
              <ArrowRight size={16} className="ms-auto text-text-muted rtl:rotate-180" />
            </button>
          ))}
        </div>
      </OnboardingShell>
    )
  }

  // ---- workspace ----
  if (step === 'workspace') return <WorkspaceStep ar={ar} onDone={refresh} StepBadge={StepBadge} label={label} field={field} />

  // ---- first_client ----
  if (step === 'first_client') return <TextStep ar={ar} title={ar ? 'أضف أول عميل' : 'Add your first client'} placeholder={ar ? 'اسم العميل' : 'Client name'} mutationFn={setFirstClient} onDone={refresh} StepBadge={StepBadge} n={4} icon={Users} field={field} />

  // ---- first_project ----
  if (step === 'first_project') return <TextStep ar={ar} title={ar ? 'أنشئ أول مشروع' : 'Create your first project'} placeholder={ar ? 'اسم المشروع' : 'Project name'} mutationFn={(name: string) => setFirstProject({ name })} onDone={refresh} StepBadge={StepBadge} n={5} icon={Rocket} field={field} />

  // ---- data_source / done ----
  return (
    <OnboardingShell title={ar ? 'التهيئة' : 'Onboarding'}>
      <StepBadge n={5} total={5} />
      <div className="flex flex-col items-center gap-3 text-center">
        <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-success/15 text-success"><Check size={26} /></span>
        <h1 className="font-heading text-xl font-extrabold text-text-primary">{ar ? 'كل شيء جاهز' : 'You’re all set'}</h1>
        <p className="text-sm text-text-secondary">{ar ? 'اربط مصدر بياناتك لاحقًا من التكاملات (المزوّدون قيد التفعيل).' : 'You can connect a data source later from Integrations (providers awaiting credentials).'}</p>
        <button onClick={() => finish.mutate()} disabled={finish.isPending} className="mt-2 flex items-center gap-2 rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60">
          {finish.isPending ? <Loader2 size={16} className="animate-spin" /> : <Rocket size={16} />} {ar ? 'الانتقال إلى لوحة التحكم' : 'Go to dashboard'}
        </button>
      </div>
    </OnboardingShell>
  )
}

function WorkspaceStep({ ar, onDone, StepBadge, label, field }: { ar: boolean; onDone: () => Promise<void>; StepBadge: (p: { n: number; total: number }) => ReactElement; label: string; field: string }) {
  const [form, setForm] = useState({ name: '', currency: 'SAR', timezone: 'Asia/Riyadh', language: ar ? 'ar' : 'en', week_start: 'sunday' })
  const save = useMutation({ mutationFn: () => setWorkspace(form), onSuccess: onDone })
  const set = (p: Partial<typeof form>) => setForm((f) => ({ ...f, ...p }))
  return (
    <OnboardingShell title={ar ? 'التهيئة' : 'Onboarding'}>
      <StepBadge n={3} total={5} />
      <h1 className="font-heading text-xl font-extrabold text-text-primary">{ar ? 'معلومات مساحة العمل' : 'Workspace information'}</h1>
      <form className="mt-4 grid gap-3" onSubmit={(e) => { e.preventDefault(); if (form.name.trim().length >= 2) save.mutate() }}>
        <label className={label}>{ar ? 'اسم مساحة العمل' : 'Workspace name'}<input className={field} value={form.name} onChange={(e) => set({ name: e.target.value })} required /></label>
        <div className="grid gap-3 sm:grid-cols-3">
          <label className={label}>{ar ? 'العملة' : 'Currency'}<input className={field} value={form.currency} maxLength={3} onChange={(e) => set({ currency: e.target.value.toUpperCase() })} /></label>
          <label className={label}>{ar ? 'المنطقة الزمنية' : 'Timezone'}<input className={field} value={form.timezone} onChange={(e) => set({ timezone: e.target.value })} /></label>
          <label className={label}>{ar ? 'اللغة' : 'Language'}
            <select className={field} value={form.language} onChange={(e) => set({ language: e.target.value })}><option value="ar">العربية</option><option value="en">English</option></select>
          </label>
        </div>
        <button type="submit" disabled={form.name.trim().length < 2 || save.isPending} className="mt-1 flex items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60">
          {save.isPending ? <Loader2 size={16} className="animate-spin" /> : <ArrowRight size={16} className="rtl:rotate-180" />} {ar ? 'متابعة' : 'Continue'}
        </button>
      </form>
    </OnboardingShell>
  )
}

function TextStep({ ar, title, placeholder, mutationFn, onDone, StepBadge, n, icon: Icon, field }: { ar: boolean; title: string; placeholder: string; mutationFn: (name: string) => Promise<unknown>; onDone: () => Promise<void>; StepBadge: (p: { n: number; total: number }) => ReactElement; n: number; icon: typeof Users; field: string }) {
  const [name, setName] = useState('')
  const save = useMutation({ mutationFn: () => mutationFn(name), onSuccess: onDone })
  return (
    <OnboardingShell title={ar ? 'التهيئة' : 'Onboarding'}>
      <StepBadge n={n} total={5} />
      <div className="mb-2 flex items-center gap-2.5"><span className="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-primary-soft text-brand-600"><Icon size={20} /></span><h1 className="font-heading text-xl font-extrabold text-text-primary">{title}</h1></div>
      <form className="mt-3 grid gap-3" onSubmit={(e) => { e.preventDefault(); if (name.trim().length >= 2) save.mutate() }}>
        <input className={field} value={name} onChange={(e) => setName(e.target.value)} placeholder={placeholder} aria-label={placeholder} required />
        <button type="submit" disabled={name.trim().length < 2 || save.isPending} className="flex items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60">
          {save.isPending ? <Loader2 size={16} className="animate-spin" /> : <ArrowRight size={16} className="rtl:rotate-180" />} {ar ? 'متابعة' : 'Continue'}
        </button>
      </form>
    </OnboardingShell>
  )
}
