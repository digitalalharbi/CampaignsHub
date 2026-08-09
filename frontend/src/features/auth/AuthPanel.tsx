import { useState } from 'react'
import { Link } from 'react-router-dom'
import { BarChart3, Bell, Check, ChevronDown, FileText, LayoutDashboard, Megaphone } from 'lucide-react'
import type { Locale } from '@/features/marketing/homeCopy'

/**
 * The brand panel shared by sign-in and sign-up.
 *
 * One component so the two pages cannot drift apart, built from the same tokens as the marketing
 * homepage — light surface, soft-green eyebrow, near-black heading, brand-green accent — so signing in
 * reads as the next section of one site rather than a jump into a different product. (It replaces a
 * near-black slab, and then a saturated gradient, neither of which the homepage has anywhere.)
 *
 * The copy adapts to the portal the visitor arrived from, but the shape stays constant: wordmark, badge,
 * the hook at the largest size on the page, and four feature cards each carrying a capability and a
 * plain sentence about it.
 *
 * On phones the panel is NOT shown beside the form: the form comes first and this collapses to a short
 * summary the visitor can open, because cramming both into one screen is what made the fields hard to
 * reach in the first place.
 */

/**
 * `advertiser` is the SELF-SERVICE journey — PLAN-FIT-001.
 *
 * It used to reuse `default`, whose body reads «نظّم العملاء والمشاريع»: clients and projects, shown
 * to somebody who had just said they run their own campaigns. `default` and `agency` in fact carried
 * the SAME body, so the panel said the same thing on both paths while the toggle above it promised
 * two different products.
 */
export type AuthPortal = 'default' | 'advertiser' | 'client' | 'influencer' | 'agency'

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
 * one repeated block. Kept at 10% over the surface token, so they stay quiet next to the brand green and
 * work in both themes rather than becoming a rainbow.
 */
const FEATURE_TINTS = [
  'bg-emerald-500/10 text-emerald-600',
  'bg-sky-500/10 text-sky-600',
  'bg-amber-500/10 text-amber-600',
  'bg-violet-500/10 text-violet-600',
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
    advertiser: {
      tagline: 'إدارة حملاتي الإعلانية',
      eyebrow: 'منصة موحدة لإدارة الحملات الإعلانية',
      title: 'أدِر حملاتك الإعلانية عبر جميع المنصات',
      titleAccent: 'من مكان واحد',
      body: 'اربط حساباتك الإعلانية، تابع الإنفاق والنتائج، قارن الأداء بين المنصات والمشاريع، واحصل على رؤية واضحة تساعدك على اتخاذ قرارات أفضل.',
      features: [
        { title: 'جميع المنصات في لوحة واحدة', desc: 'تابع حملاتك عبر Snapchat وTikTok وMeta وGoogle Ads وX وLinkedIn من مكان واحد.' },
        { title: 'مؤشرات موحدة وقابلة للمقارنة', desc: 'قارن الإنفاق والنتائج والعائد بين المنصات والمشاريع دون التنقل بين الحسابات.' },
        { title: 'تحليل يتوافق مع هدف الحملة', desc: 'اعرض المؤشرات المناسبة للوعي أو الزيارات أو العملاء المحتملين أو المبيعات دون خلط النتائج.' },
        { title: 'تقارير وتنبيهات جاهزة لاتخاذ القرار', desc: 'تابع أهم التغيّرات وشارك تقارير واضحة ومحدثة دون إعداد يدوي متكرر.' },
      ],
    },
    agency: {
      tagline: 'إدارة حملات الوكالات',
      eyebrow: 'منصة متكاملة لإدارة حملات عدة عملاء',
      title: 'كل حملات عملائك المدفوعة',
      titleAccent: 'في مكان واحد',
      body: 'مساحة مستقلة لكل عميل، ومشاريع منفصلة، وصلاحيات محدّدة لكل فرد في الفريق، وتقارير جاهزة لكل عميل باسمه.',
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
    advertiser: {
      tagline: 'My campaign management',
      eyebrow: 'One platform for every ad campaign',
      title: 'Run your advertising across every platform',
      titleAccent: 'from one place',
      body: 'Connect your ad accounts, follow spend and results, compare performance across platforms and projects, and get a clear view to decide from.',
      features: [
        { title: 'Every platform on one dashboard', desc: 'Follow your campaigns across Snapchat, TikTok, Meta, Google Ads, X and LinkedIn in one place.' },
        { title: 'One set of comparable metrics', desc: 'Compare spend, results and return across platforms and projects without moving between accounts.' },
        { title: 'Analysis that matches the objective', desc: 'See the metrics that fit awareness, traffic, leads or sales — never mixed together.' },
        { title: 'Reports and alerts you can act on', desc: 'Follow what actually changed and share clear, current reports without rebuilding them each time.' },
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

/**
 * The desktop panel: identity, promise, four capabilities — in the homepage's own visual language.
 *
 * The surface, the soft-green eyebrow pill, the near-black heading and the muted body are the same tokens
 * the marketing hero uses, so arriving here from the homepage feels like the next section of one site
 * rather than a jump into a different product.
 */
export function AuthPanel({ locale, portal }: { locale: Locale; portal: AuthPortal }) {
  const c = authPanelCopy(locale, portal)

  return (
    <aside
      data-testid="auth-panel"
      className="relative hidden min-w-0 overflow-hidden border-e border-border bg-surface-secondary px-[clamp(1.25rem,2.4vw,2.5rem)] py-[clamp(1.25rem,3vh,2rem)] lg:flex lg:flex-col lg:justify-center"
    >
      {/* A soft brand wash, the same green the homepage carries — not a slab of colour. */}
      <div aria-hidden className="pointer-events-none absolute -end-24 -top-28 h-72 w-72 rounded-full bg-brand-primary-soft blur-3xl" />
      <div aria-hidden className="pointer-events-none absolute -bottom-32 -start-24 h-80 w-80 rounded-full bg-brand-primary-soft/70 blur-3xl" />

      {/*
        Held to a readable measure and pushed toward the divider (`ms-auto` = margin-inline-start, so it
        works in both directions). Left to fill the column, the promise and the form drifted to opposite
        edges of a wide screen and stopped reading as one composition.
      */}
      {/* `min(…, 100%)` so the measure can never exceed the track it sits in — AUTH-FIT-001. */}
      <div className="relative w-full max-w-[min(35rem,100%)] ms-auto">
        {/* A real way back to the site the visitor came from. */}
        <Link to="/" className="flex w-fit items-center gap-2.5">
          <span className="flex h-[clamp(2.25rem,3vw,2.75rem)] w-[clamp(2.25rem,3vw,2.75rem)] items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 text-white shadow-[var(--shadow-small)]">
            <Megaphone size={18} />
          </span>
          <span className="min-w-0">
            <span className="block font-heading text-[clamp(1.0625rem,1.35vw,1.25rem)] font-extrabold leading-tight tracking-tight text-text-primary">CampaignsHub</span>
            <span className="block text-[clamp(0.6875rem,0.85vw,0.75rem)] text-text-muted">{c.tagline}</span>
          </span>
        </Link>

        <p className="mt-[clamp(0.75rem,2vh,1.25rem)] inline-flex w-fit items-center gap-2 rounded-full bg-brand-primary-soft px-3.5 py-1.5 text-[clamp(0.6875rem,0.85vw,0.75rem)] font-semibold text-brand-700">
          <span className="h-1.5 w-1.5 rounded-full bg-brand-500" />
          {c.eyebrow}
        </p>

        {/* The hook. Deliberately the largest thing on the page — it is what the product promises. The
            closing phrase gets its own line, the brand green and a larger size, so «في مكان واحد» is the
            part that lands rather than being buried in the middle of a sentence. */}
        {/* The two halves are block-level for the layout, which would otherwise run them together into
            one word for assistive tech. The label is the same sentence a sighted visitor reads. */}
        <h1
          aria-label={`${c.title} ${c.titleAccent}`}
          className="mt-[clamp(0.5rem,1.4vh,0.75rem)] font-heading font-extrabold leading-[1.12] tracking-tight text-text-primary"
        >
          {/*
            One fluid ramp instead of three breakpoint steps — AUTH-FIT-001.

            The old sizes were tuned for a wide screen and were simply too tall on a 768px-high
            laptop, where the panel then needed scrolling to finish a sentence. `clamp()` keeps the
            lower bound readable rather than merely small: the floor is what a 1024px laptop gets,
            not a size chosen to make the numbers fit.
          */}
          <span className="block text-[clamp(1.5rem,2.15vw,2.1875rem)]">{c.title}</span>
          {/* Kept unbreakable: "in one place" split across two lines reads as a typo, not a promise. */}
          <span className="mt-0.5 block whitespace-nowrap text-[clamp(1.875rem,2.75vw,2.8125rem)] text-brand-600">
            {c.titleAccent}
          </span>
        </h1>
        <p className="mt-[clamp(0.5rem,1.4vh,0.75rem)] text-[clamp(0.8125rem,0.95vw,0.875rem)] leading-relaxed text-text-secondary">{c.body}</p>

        {/* Feature cards, at the same weight the rest of the product gives them: an icon tile, the
            capability, and one plain sentence saying what it does. */}
        <ul className="mt-[clamp(0.625rem,1.6vh,1rem)] grid gap-[clamp(0.375rem,0.9vh,0.5rem)]">
          {c.features.map((f, i) => {
            const Icon = FEATURE_ICONS[i] ?? Check
            return (
              <li
                key={f.title}
                className="flex items-start gap-3 rounded-2xl border border-border bg-surface px-3 py-[clamp(0.5rem,1.1vh,0.625rem)] shadow-[var(--shadow-small)]"
              >
                <span className={`flex h-[clamp(1.75rem,2.2vw,2rem)] w-[clamp(1.75rem,2.2vw,2rem)] shrink-0 items-center justify-center rounded-[10px] ${FEATURE_TINTS[i] ?? 'bg-brand-primary-soft text-brand-600'}`}>
                  <Icon size={15} />
                </span>
                <span className="min-w-0">
                  <span className="block text-[clamp(0.8125rem,0.92vw,0.84375rem)] font-bold leading-snug text-text-primary">{f.title}</span>
                  <span className="mt-0.5 block text-[clamp(0.75rem,0.85vw,0.75rem)] leading-relaxed text-text-secondary">{f.desc}</span>
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
