import { useState } from 'react'
import { BarChart3, Bell, Check, ChevronDown, FileText, LayoutDashboard, Megaphone } from 'lucide-react'
import type { Locale } from '@/features/marketing/homeCopy'

/**
 * The brand panel shared by sign-in and sign-up.
 *
 * One component so the two pages cannot drift apart, and one gradient — deep green through teal into a
 * light navy — instead of the near-black slab the pages used to carry. The copy adapts to the portal the
 * visitor arrived from, but the shape stays constant: wordmark, badge, the hook at the largest size on
 * the page, and four feature cards each carrying a capability and a plain sentence about it.
 *
 * On phones the panel is NOT shown beside the form: the form comes first and this collapses to a short
 * summary the visitor can open, because cramming both into one screen is what made the fields hard to
 * reach in the first place.
 */

export type AuthPortal = 'default' | 'client' | 'influencer' | 'agency'

/**
 * A feature is a card, not a bullet: the headline alone ("Professional reports") tells a visitor nothing
 * they cannot already guess, so each one carries a plain sentence saying what it actually does for them.
 */
interface PanelFeature {
  title: string
  desc: string
}

interface PanelCopy {
  /** Sits under the wordmark, the way the product describes itself in one line. */
  tagline: string
  eyebrow: string
  /**
   * The hook, split so the closing phrase can carry the accent colour. Keeping the two halves as data
   * (rather than parsing a sentence for a substring) means translations decide their own emphasis.
   */
  title: string
  titleAccent: string
  body: string
  features: PanelFeature[]
}

const FEATURE_ICONS = [LayoutDashboard, BarChart3, FileText, Bell]

/**
 * A distinct tint per capability, so the four cards read as four different things at a glance instead of
 * one repeated block. They stay translucent and light-on-dark, so the gradient still shows through and the
 * panel remains recognisably CampaignsHub rather than a rainbow.
 */
const FEATURE_TINTS = [
  'bg-emerald-400/20 text-emerald-200',
  'bg-cyan-400/20 text-cyan-200',
  'bg-amber-400/20 text-amber-200',
  'bg-violet-400/20 text-violet-200',
]

const COPY: Record<Locale, Record<AuthPortal, PanelCopy>> = {
  ar: {
    default: {
      tagline: 'إدارة الحملات الإعلانية',
      eyebrow: 'منصة متكاملة لإدارة الحملات الإعلانية',
      title: 'كل حملاتك الإعلانية المدفوعة',
      titleAccent: 'في مكان واحد',
      body: 'تابع الأداء والميزانيات والنتائج عبر المنصات، نظّم العملاء والمشاريع، وأنشئ تقارير احترافية من مساحة عمل واحدة.',
      features: [
        { title: 'متابعة موحّدة لجميع المنصات', desc: 'سناب شات وتيك توك وميتا وجوجل وإكس ولينكدإن في شاشة واحدة.' },
        { title: 'تحليلات تناسب هدف الحملة', desc: 'مؤشرات كل هدف على حدة، دون خلط الوعي بالزيارات أو المبيعات.' },
        { title: 'تقارير احترافية قابلة للمشاركة', desc: 'جهّز تقريرًا واضحًا لعميلك أو لإدارتك في دقائق.' },
        { title: 'تنبيهات ومتابعة مستمرة', desc: 'تنبيه عند تغيّر الإنفاق أو الأداء قبل أن تكبر المشكلة.' },
      ],
    },
    agency: {
      tagline: 'إدارة حملات الوكالات',
      eyebrow: 'منصة متكاملة لإدارة حملات عدة عملاء',
      title: 'كل حملات عملائك المدفوعة',
      titleAccent: 'في مكان واحد',
      body: 'تابع الأداء والميزانيات والنتائج عبر المنصات، نظّم العملاء والمشاريع، وأنشئ تقارير احترافية من مساحة عمل واحدة.',
      features: [
        { title: 'مساحة مستقلة لكل عميل ومشروع', desc: 'بيانات كل عميل معزولة، وصلاحيات محدّدة لكل فرد في الفريق.' },
        { title: 'تحليلات تناسب هدف الحملة', desc: 'مؤشرات كل هدف على حدة، دون خلط الوعي بالزيارات أو المبيعات.' },
        { title: 'تقارير احترافية لكل عميل', desc: 'تقرير جاهز للإرسال في أي وقت، دون إعداد يدوي.' },
        { title: 'صلاحيات فريق وتنبيهات مستمرة', desc: 'حدّد من يرى ماذا، وتابع التغيّرات أولًا بأول.' },
      ],
    },
    client: {
      tagline: 'بوابة العملاء',
      eyebrow: 'بوابة آمنة لمتابعة طلباتك',
      title: 'طلباتك وعروضك وفواتيرك',
      titleAccent: 'في مكان واحد',
      body: 'حالة الطلب، وعروض الأسعار، والفواتير والمدفوعات، والرسائل والملفات — في بوابة واحدة آمنة.',
      features: [
        { title: 'متابعة حالة كل طلب خطوة بخطوة', desc: 'تعرف أين وصل طلبك ومتى تتغيّر حالته.' },
        { title: 'عروض الأسعار والفواتير والمدفوعات', desc: 'راجع العرض واعتمده، وتابع فواتيرك في مكان واحد.' },
        { title: 'الرسائل والملفات في مكان واحد', desc: 'كل المراسلات والمرفقات محفوظة مع الطلب نفسه.' },
        { title: 'تنبيهات عند كل تحديث', desc: 'يصلك إشعار فور تغيّر حالة الطلب.' },
      ],
    },
    influencer: {
      tagline: 'حملات المؤثرين والمحتوى',
      eyebrow: 'منصة متكاملة لإدارة حملات المؤثرين',
      title: 'حملات المؤثرين والمحتوى',
      titleAccent: 'في مكان واحد',
      body: 'من الطلب حتى التسليم — تابع المحتويات والموافقات والتسليمات ونتائج الحملة في مساحة واحدة.',
      features: [
        { title: 'إدارة طلبات المؤثرين', desc: 'من الطلب حتى الاتفاق، بخطوات واضحة للطرفين.' },
        { title: 'مراجعة المحتوى والموافقات', desc: 'راجع المحتوى واعتمده قبل النشر.' },
        { title: 'متابعة التسليمات', desc: 'تابع ما تم تسليمه وما تبقّى في كل حملة.' },
        { title: 'قياس نتائج الحملة', desc: 'نتائج كل مؤثر ومقارنة الأداء ضمن الهدف نفسه.' },
      ],
    },
  },
  en: {
    default: {
      tagline: 'Ad campaign management',
      eyebrow: 'One platform for every paid campaign',
      title: 'All your paid ad campaigns',
      titleAccent: 'in one place',
      body: 'Track performance, budgets and results across platforms, organise clients and projects, and produce professional reports from one workspace.',
      features: [
        { title: 'Unified tracking across every platform', desc: 'Snapchat, TikTok, Meta, Google, X and LinkedIn on one screen.' },
        { title: 'Analytics matched to each objective', desc: 'Each goal measured on its own terms — awareness is never compared to sales.' },
        { title: 'Professional, shareable reports', desc: 'Produce a clear report for a client or your management in minutes.' },
        { title: 'Alerts and continuous monitoring', desc: 'Hear about a spend or performance shift before it becomes a problem.' },
      ],
    },
    agency: {
      tagline: 'Agency campaign management',
      eyebrow: 'One platform for every client’s campaigns',
      title: 'Every client’s paid campaigns',
      titleAccent: 'in one place',
      body: 'Track performance, budgets and results across platforms, organise clients and projects, and produce professional reports from one workspace.',
      features: [
        { title: 'A separate space per client and project', desc: 'Each client’s data stays isolated, with defined permissions per team member.' },
        { title: 'Analytics matched to each objective', desc: 'Each goal measured on its own terms — awareness is never compared to sales.' },
        { title: 'Professional reports per client', desc: 'Ready to send at any time, with no manual assembly.' },
        { title: 'Team permissions and continuous alerts', desc: 'Decide who sees what, and follow changes as they happen.' },
      ],
    },
    client: {
      tagline: 'Client portal',
      eyebrow: 'A secure portal for your requests',
      title: 'Your requests, quotes and invoices',
      titleAccent: 'in one place',
      body: 'Request status, quotes, invoices and payments, plus messages and files — in one secure portal.',
      features: [
        { title: 'Follow every request step by step', desc: 'See where your request stands and when its status changes.' },
        { title: 'Quotes, invoices and payments', desc: 'Review and approve a quote, and keep every invoice in one place.' },
        { title: 'Messages and files in one place', desc: 'All correspondence and attachments stay with the request itself.' },
        { title: 'Alerts on every update', desc: 'You are notified the moment a request status changes.' },
      ],
    },
    influencer: {
      tagline: 'Influencer & content campaigns',
      eyebrow: 'One platform for influencer campaigns',
      title: 'Influencer and content campaigns',
      titleAccent: 'in one place',
      body: 'From request to delivery — track content, approvals, deliverables and campaign results in one space.',
      features: [
        { title: 'Manage influencer requests', desc: 'From request to agreement, in steps both sides can follow.' },
        { title: 'Content review and approvals', desc: 'Review and approve content before it goes live.' },
        { title: 'Track deliverables', desc: 'See what has been delivered and what is still outstanding.' },
        { title: 'Measure campaign results', desc: 'Per-influencer results, compared within the same objective.' },
      ],
    },
  },
}

export function authPanelCopy(locale: Locale, portal: AuthPortal): PanelCopy {
  return COPY[locale][portal] ?? COPY[locale].default
}

/** Deep green → teal → light navy. Replaces the near-black panel while staying unmistakably CampaignsHub. */
export const AUTH_GRADIENT =
  'bg-[linear-gradient(140deg,#0b3f34_0%,#0e6155_42%,#17506e_78%,#22608a_100%)]'

/** The desktop panel: identity, promise, four benefits. */
export function AuthPanel({ locale, portal }: { locale: Locale; portal: AuthPortal }) {
  const c = authPanelCopy(locale, portal)

  return (
    <aside
      data-testid="auth-panel"
      className={`relative hidden overflow-hidden p-8 text-white lg:flex lg:flex-col lg:justify-center xl:p-12 ${AUTH_GRADIENT}`}
    >
      {/* Soft light, not a black vignette. */}
      <div aria-hidden className="pointer-events-none absolute -end-24 -top-28 h-72 w-72 rounded-full bg-white/10 blur-3xl" />
      <div aria-hidden className="pointer-events-none absolute -bottom-32 -start-24 h-80 w-80 rounded-full bg-[var(--teal)]/20 blur-3xl" />

      <div className="relative">
        <div className="flex items-center gap-2.5">
          <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-white/15 backdrop-blur"><Megaphone size={20} /></span>
          <span className="min-w-0">
            <span className="block text-xl font-extrabold leading-tight tracking-tight">CampaignsHub</span>
            <span className="block text-[12px] text-white/60">{c.tagline}</span>
          </span>
        </div>

        <p className="mt-7 inline-flex w-fit items-center gap-2 rounded-full bg-white/10 px-3.5 py-1.5 text-[12px] font-semibold text-white/85 ring-1 ring-inset ring-white/15">
          <span className="h-1.5 w-1.5 rounded-full bg-emerald-300" />
          {c.eyebrow}
        </p>

        {/* The hook. Deliberately the largest thing on the page — it is what the product promises, and the
            closing phrase carries the accent so the promise lands rather than reading as one grey block. */}
        <h1 className="mt-4 font-[var(--font-heading)] text-[34px] font-extrabold leading-[1.14] tracking-tight sm:text-[40px] xl:text-[46px]">
          {c.title}{' '}
          {/* Kept unbreakable: "in one place" split across two lines reads as a typo, not a promise. */}
          <span className="whitespace-nowrap bg-gradient-to-l from-emerald-200 via-teal-200 to-cyan-200 bg-clip-text text-transparent">
            {c.titleAccent}
          </span>
        </h1>
        <p className="mt-4 max-w-xl text-[14.5px] leading-relaxed text-white/70">{c.body}</p>

        {/* Feature cards, at the same weight the rest of the product gives them: an icon tile, the
            capability, and one plain sentence saying what it does. */}
        <ul className="mt-6 grid max-w-xl gap-2.5">
          {c.features.map((f, i) => {
            const Icon = FEATURE_ICONS[i] ?? Check
            return (
              <li
                key={f.title}
                className="flex items-start gap-3 rounded-2xl border border-white/12 bg-white/8 p-3.5 backdrop-blur-sm"
              >
                <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ${FEATURE_TINTS[i] ?? 'bg-white/15 text-white'}`}>
                  <Icon size={17} />
                </span>
                <span className="min-w-0">
                  <span className="block text-[14.5px] font-bold leading-snug text-white">{f.title}</span>
                  <span className="mt-1 block text-[12.5px] leading-relaxed text-white/70">{f.desc}</span>
                </span>
              </li>
            )
          })}
        </ul>
      </div>
    </aside>
  )
}

/**
 * The phone version: a short, collapsed summary UNDER the form. It never competes with the fields for
 * the first screen, and it does not introduce a horizontal scroller.
 */
export function AuthPanelMobile({ locale, portal }: { locale: Locale; portal: AuthPortal }) {
  const c = authPanelCopy(locale, portal)
  const [open, setOpen] = useState(false)
  const ar = locale === 'ar'

  return (
    <section data-testid="auth-panel-mobile" className="mt-8 rounded-2xl border border-border bg-surface-secondary p-4 lg:hidden">
      <button
        type="button"
        aria-expanded={open}
        onClick={() => setOpen((v) => !v)}
        className="flex w-full items-center justify-between gap-3 text-start"
      >
        <span className="min-w-0">
          <span className="block text-[13.5px] font-bold text-text-primary">{c.title} {c.titleAccent}</span>
          <span className="mt-0.5 block text-[11.5px] text-text-muted">
            {open ? (ar ? 'إخفاء التفاصيل' : 'Hide details') : (ar ? 'ماذا تحصل عليه؟' : 'What you get')}
          </span>
        </span>
        <ChevronDown size={16} className={`shrink-0 text-text-muted transition-transform ${open ? 'rotate-180' : ''}`} />
      </button>

      {open && (
        <>
          <p className="mt-3 text-[12.5px] leading-relaxed text-text-secondary">{c.body}</p>
          <ul className="mt-2.5 space-y-2">
            {c.features.map((f) => (
              <li key={f.title} className="flex items-start gap-2">
                <Check size={13} className="mt-1 shrink-0 text-brand-500" />
                <span className="min-w-0">
                  <span className="block text-[12.5px] font-bold text-text-primary">{f.title}</span>
                  <span className="block text-[12px] leading-relaxed text-text-muted">{f.desc}</span>
                </span>
              </li>
            ))}
          </ul>
        </>
      )}
    </section>
  )
}
