import { Link } from 'react-router-dom'
import {
  BarChart3,
  Bell,
  Bot,
  CheckCircle2,
  CreditCard,
  FileCheck2,
  FolderKanban,
  Plug,
  Shield,
  Users,
} from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { brand } from '@/lib/brand'
import { useUi } from '@/stores/ui'

const features = [
  { icon: FolderKanban, ar: 'إدارة المشاريع', en: 'Project management' },
  { icon: Plug, ar: 'ربط منصات الإعلان', en: 'Ad platform integrations' },
  { icon: FileCheck2, ar: 'المحتوى والاعتمادات', en: 'Content & approvals' },
  { icon: BarChart3, ar: 'التتبع والتقارير', en: 'Tracking & reporting' },
  { icon: Bell, ar: 'الإشعارات والمهام', en: 'Notifications & tasks' },
  { icon: Users, ar: 'بوابات العملاء', en: 'Client portals' },
  { icon: Bot, ar: 'الذكاء الاصطناعي BYOK', en: 'AI (bring your own key)' },
  { icon: CreditCard, ar: 'الاشتراكات والفوترة', en: 'Subscriptions & billing' },
]

const plans = [
  { name: 'Trial', price: '0', ar: 'تجربة' },
  { name: 'Starter', price: '—', ar: 'ابتدائية' },
  { name: 'Professional', price: '—', ar: 'احترافية' },
  { name: 'Agency', price: '—', ar: 'وكالة' },
  { name: 'Enterprise', price: '—', ar: 'مؤسسات' },
]

const faqs = [
  { ar: 'هل يمكن ربط حساب مختلف لكل مشروع؟', en: 'Can I connect a different account per project?' },
  { ar: 'هل يمكن منح العميل صلاحية مشاهدة فقط؟', en: 'Can I give a client view-only access?' },
  { ar: 'هل يمكن استخدام مفتاح OpenAI الخاص بالعميل؟', en: "Can a client use their own OpenAI key?" },
  { ar: 'هل تُخلط بيانات المشاريع؟', en: 'Is project data ever mixed?' },
  { ar: 'هل يمكن مشاركة التقرير برابط آمن؟', en: 'Can I share a report via a secure link?' },
  { ar: 'هل يمكن إضافة نطاق خاص؟', en: 'Can I add a custom domain?' },
]

export function MarketingPage() {
  const { locale, theme, toggleTheme, toggleLocale } = useUi()
  const ar = locale === 'ar'

  return (
    <div className="min-h-screen bg-background text-text-primary">
      {/* Nav */}
      <header className="sticky top-0 z-40 border-b border-border bg-surface/90 backdrop-blur">
        <div className="mx-auto flex max-w-[1240px] items-center gap-3 px-6 py-3">
          <span className="font-[var(--font-heading)] text-lg font-extrabold text-brand-600">{brand.name}</span>
          <nav className="ms-auto flex items-center gap-2">
            <Button variant="secondary" onClick={toggleLocale}>{ar ? 'EN' : 'ع'}</Button>
            <Button variant="secondary" onClick={toggleTheme}>{theme === 'light' ? '🌙' : '☀️'}</Button>
            <Link to="/login"><Button variant="ghost">{ar ? 'الدخول' : 'Sign in'}</Button></Link>
            <Link to="/login"><Button>{ar ? 'إنشاء حساب' : 'Get started'}</Button></Link>
          </nav>
        </div>
      </header>

      {/* Hero */}
      <section className="mx-auto max-w-[1240px] px-6 py-20 text-center">
        <span className="inline-block rounded-[var(--radius-pill)] bg-[var(--brand-background)] px-3 py-1 text-xs font-bold text-brand-600">
          {/*
            BRAND-001 — the product's own words, not the industry's.

            This transliterated «media buying» into Arabic and sat directly above the official
            tagline, so the first line of the homepage asked a visitor to know an English industry
            term. «الحملات الإعلانية المدفوعة» is what the rest of the product calls this.

            The superseded wording is deliberately not repeated here: `BrandIdentityGuardTest`
            fails on it wherever it appears outside a test, which is what stops a rename from
            decaying back into a habit.
          */}
          {ar ? 'منصة إدارة الحملات الإعلانية المدفوعة' : 'Paid advertising management platform'}
        </span>
        <h1 className="mx-auto mt-5 max-w-3xl font-[var(--font-heading)] text-4xl font-extrabold leading-tight md:text-5xl">
          {ar ? brand.taglineAr : brand.tagline}
        </h1>
        <p className="mx-auto mt-4 max-w-2xl text-[15px] text-text-secondary">
          {ar
            ? 'أدر العملاء والمشاريع والمحتوى والحسابات الإعلانية وإطلاق الحملات والتتبع والقياس والتقارير والمهام — من مكان واحد، مع عزل كامل لكل مشروع وعميل.'
            : 'Manage clients, projects, content, ad accounts, campaign launch, tracking, measurement, reports, and tasks — in one place, with full per-project and per-client isolation.'}
        </p>
        <div className="mt-7 flex items-center justify-center gap-3">
          <Link to="/login"><Button>{ar ? 'ابدأ الآن' : 'Create account'}</Button></Link>
          <Button variant="secondary">{ar ? 'اطلب عرضاً تجريبياً' : 'Request a demo'}</Button>
        </div>
      </section>

      {/* Problem → Solution */}
      <section className="border-y border-border bg-surface">
        <div className="mx-auto grid max-w-[1240px] gap-8 px-6 py-16 md:grid-cols-2">
          <div>
            <h2 className="font-[var(--font-heading)] text-2xl font-bold">{ar ? 'المشكلة' : 'The problem'}</h2>
            <ul className="mt-4 space-y-2 text-base text-text-secondary">
              {(ar
                ? ['تشتت الحسابات بين المنصات', 'اختلاف التقارير', 'ضياع صلاحيات العملاء', 'تأخر اعتماد المحتوى', 'غياب الربط بين الإنفاق والمبيعات', 'صعوبة إدارة عشرات المشاريع']
                : ['Accounts scattered across platforms', 'Inconsistent reports', 'Lost client permissions', 'Slow content approvals', 'No link between spend and sales', 'Hard to manage dozens of projects']
              ).map((p) => <li key={p}>• {p}</li>)}
            </ul>
          </div>
          <div>
            <h2 className="font-[var(--font-heading)] text-2xl font-bold">{ar ? 'الحل' : 'The solution'}</h2>
            <ol className="mt-4 space-y-2 text-base text-text-secondary">
              {(ar
                ? ['أنشئ مساحة العميل', 'أنشئ المشروع', 'اربط مصادره', 'جهّز الحملة واعتمد المحتوى', 'تابع الأداء واستلم التنبيهات', 'شارك التقرير بأمان']
                : ['Create a client workspace', 'Create a project', 'Connect its sources', 'Prepare the campaign & approve content', 'Track performance & get alerts', 'Share the report securely']
              ).map((s, i) => (
                <li key={s} className="flex gap-2"><span className="tnum font-bold text-brand-600">{i + 1}.</span>{s}</li>
              ))}
            </ol>
          </div>
        </div>
      </section>

      {/* Features */}
      <section className="mx-auto max-w-[1240px] px-6 py-16">
        <h2 className="text-center font-[var(--font-heading)] text-2xl font-bold">{ar ? 'المزايا' : 'Features'}</h2>
        <div className="mt-8 grid grid-cols-2 gap-4 md:grid-cols-4">
          {features.map((f) => (
            <div key={f.en} className="rounded-[14px] border border-border bg-surface p-4 shadow-[var(--shadow-small)]">
              <f.icon size={20} className="text-brand-600" />
              <p className="mt-2 text-sm font-bold">{ar ? f.ar : f.en}</p>
            </div>
          ))}
        </div>
      </section>

      {/* Pricing */}
      <section className="border-y border-border bg-surface">
        <div className="mx-auto max-w-[1240px] px-6 py-16">
          <h2 className="text-center font-[var(--font-heading)] text-2xl font-bold">{ar ? 'الباقات' : 'Pricing'}</h2>
          <p className="mt-2 text-center text-sm text-text-muted">
            {ar ? 'تُدار الأسعار والحدود من لوحة المنصة.' : 'Prices and limits are managed from the platform admin.'}
          </p>
          <div className="mt-8 grid grid-cols-2 gap-4 md:grid-cols-5">
            {plans.map((p) => (
              <div key={p.name} className="rounded-[14px] border border-border bg-background p-4 text-center">
                <p className="text-sm font-bold">{ar ? p.ar : p.name}</p>
                <p className="mt-2 font-[var(--font-heading)] text-xl font-extrabold tnum">{p.price}</p>
                <Link to="/login"><Button variant="secondary" className="mt-3 w-full">{ar ? 'اختيار' : 'Choose'}</Button></Link>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Security */}
      <section className="mx-auto max-w-[1240px] px-6 py-16">
        <div className="flex items-center gap-2">
          <Shield size={20} className="text-brand-600" />
          <h2 className="font-[var(--font-heading)] text-2xl font-bold">{ar ? 'الأمان' : 'Security'}</h2>
        </div>
        <div className="mt-4 grid grid-cols-1 gap-2 text-base text-text-secondary md:grid-cols-2">
          {(ar
            ? ['عزل بيانات المؤسسات والمشاريع', 'صلاحيات دقيقة خادمية', 'تشفير البيانات الحساسة', 'سجل تدقيق', 'مصادقة آمنة بالجلسة', 'علامات مائية للمحتوى الحساس']
            : ['Client + project data isolation', 'Fine-grained server-side permissions', 'Encryption of sensitive data', 'Audit log', 'Secure session auth', 'Watermarks on sensitive content']
          ).map((s) => (
            <div key={s} className="flex items-center gap-2"><CheckCircle2 size={16} className="text-success" />{s}</div>
          ))}
        </div>
        <p className="mt-3 text-xs text-text-muted">
          {ar
            ? 'ملاحظة: لا ندّعي منع تصوير الشاشة بشكل كامل؛ نوفّر ردعاً وتتبعاً وعلامات مائية.'
            : 'Note: we do not claim to prevent screen capture; we provide deterrence, attribution, and watermarks.'}
        </p>
      </section>

      {/* FAQ */}
      <section className="border-t border-border bg-surface">
        <div className="mx-auto max-w-[900px] px-6 py-16">
          <h2 className="text-center font-[var(--font-heading)] text-2xl font-bold">{ar ? 'أسئلة شائعة' : 'FAQ'}</h2>
          <div className="mt-6 space-y-3">
            {faqs.map((f) => (
              <details key={f.en} className="rounded-[12px] border border-border bg-background p-4">
                <summary className="cursor-pointer text-base font-semibold">{ar ? f.ar : f.en}</summary>
                <p className="mt-2 text-sm text-text-secondary">
                  {ar ? 'نعم — النظام يدعم ذلك مع عزل كامل للبيانات وصلاحيات دقيقة.' : 'Yes — supported, with full data isolation and fine-grained permissions.'}
                </p>
              </details>
            ))}
          </div>
        </div>
      </section>

      {/* Final CTA + Footer */}
      <section className="mx-auto max-w-[1240px] px-6 py-16 text-center">
        <h2 className="font-[var(--font-heading)] text-2xl font-bold">{ar ? 'ابدأ مع CampaignsHub' : 'Get started with CampaignsHub'}</h2>
        <div className="mt-5 flex items-center justify-center gap-3">
          <Link to="/login"><Button>{ar ? 'إنشاء حساب' : 'Create account'}</Button></Link>
          <Button variant="secondary">{ar ? 'التواصل مع المبيعات' : 'Contact sales'}</Button>
        </div>
      </section>
      <footer className="border-t border-border bg-surface">
        <div className="mx-auto flex max-w-[1240px] flex-col items-center justify-between gap-2 px-6 py-6 text-xs text-text-muted md:flex-row">
          <span>© {brand.name} — {brand.domain}</span>
          <span>{ar ? 'المنتج · المزايا · الأسعار · الأمان · الخصوصية · الشروط · الدعم' : 'Product · Features · Pricing · Security · Privacy · Terms · Support'}</span>
        </div>
      </footer>
    </div>
  )
}
