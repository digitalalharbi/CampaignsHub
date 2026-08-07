import type { Locale } from '@/stores/ui'

/**
 * Where each policy belongs — POLICY-PLACEMENT-001.
 *
 * ## The problem this replaces
 *
 * All thirteen policies hung off the public homepage footer, in two columns. That put «حذف الحساب
 * والبيانات», «الاشتراكات والاسترداد» and «الإفصاح عن OAuth» in front of a visitor who has no
 * account, no subscription and nothing connected — and put them nowhere near the person who
 * actually needs them, who is signed in and looking at their account, their invoice, or their
 * platform connections.
 *
 * Nothing is deleted here. Every page, every route and every legal obligation stays exactly as it
 * was; this file only records WHERE each one should be offered.
 *
 * ## The rule
 *
 * - **The public footer** keeps the policies a visitor can be expected to want before signing up:
 *   privacy, terms, data processing, cookies, security — plus the company pages.
 * - **Every portal footer** carries the three that apply wherever you are: privacy, terms, security.
 * - **Everything else appears in the context that raises it.** Retention and deletion beside the
 *   account; refunds beside the subscription; the OAuth disclosure beside the platform you are about
 *   to connect. A policy read where the question arises is a policy that gets read.
 *
 * One `to` per policy, pointing at the page that already exists. A second copy of a legal text is a
 * second thing to keep current, and the one nobody updates is the one a regulator reads.
 */

export type PolicyKey =
  | 'privacy'
  | 'terms'
  | 'data-processing'
  | 'cookies'
  | 'security'
  | 'retention'
  | 'subprocessors'
  | 'account-deletion'
  | 'data-requests'
  | 'acceptable-use'
  | 'subscriptions-refunds'
  | 'oauth-disclosure'
  | 'system-status'

type Policy = { to: string; ar: string; en: string }

const POLICIES: Record<PolicyKey, Policy> = {
  'privacy': { to: '/privacy', ar: 'سياسة الخصوصية', en: 'Privacy policy' },
  'terms': { to: '/terms', ar: 'الشروط والأحكام', en: 'Terms of service' },
  'data-processing': { to: '/data-processing', ar: 'معالجة البيانات', en: 'Data processing' },
  'cookies': { to: '/cookies', ar: 'ملفات تعريف الارتباط', en: 'Cookies' },
  'security': { to: '/security', ar: 'الأمان', en: 'Security' },
  'retention': { to: '/retention', ar: 'الاحتفاظ بالبيانات', en: 'Data retention' },
  'subprocessors': { to: '/subprocessors', ar: 'مزودو المعالجة', en: 'Subprocessors' },
  'account-deletion': { to: '/account-deletion', ar: 'حذف الحساب والبيانات', en: 'Account & data deletion' },
  'data-requests': { to: '/data-requests', ar: 'طلبات البيانات', en: 'Data requests' },
  'acceptable-use': { to: '/acceptable-use', ar: 'الاستخدام المقبول', en: 'Acceptable use' },
  'subscriptions-refunds': { to: '/subscriptions-refunds', ar: 'الاشتراكات والاسترداد', en: 'Subscriptions & refunds' },
  'oauth-disclosure': { to: '/oauth-disclosure', ar: 'الإفصاح عن OAuth', en: 'OAuth disclosure' },
  'system-status': { to: '/system-status', ar: 'حالة النظام', en: 'System status' },
}

/**
 * The contexts, and what each one offers.
 *
 * `portal` is the footer under every signed-in shell — deliberately three links and no more, because
 * a footer that lists thirteen policies is the homepage footer again, one level down.
 */
export const POLICY_CONTEXTS = {
  /** Under every portal shell: `/admin`, `/app`, `/agency`, `/portal`, `/influencers`. */
  portal: ['privacy', 'terms', 'security'],
  /** The public site's own footer — what a visitor may need before they have an account. */
  public: ['privacy', 'terms', 'data-processing', 'cookies', 'security'],
  /** Account, profile and privacy settings — «what happens to my data, and how do I get it out». */
  account: ['account-deletion', 'data-requests', 'retention', 'privacy'],
  /** Subscription, billing and payment — «what am I paying for, and how do I stop». */
  billing: ['subscriptions-refunds', 'terms'],
  /** Integrations and platform linking — what an OAuth reviewer and a connecting operator each need. */
  integrations: ['oauth-disclosure', 'data-processing', 'subprocessors', 'security'],
  /** Support and operational health. The status page belongs here, not in a marketing footer. */
  support: ['system-status', 'acceptable-use', 'security'],
} as const satisfies Record<string, readonly PolicyKey[]>

export type PolicyContext = keyof typeof POLICY_CONTEXTS

export type PolicyLink = { key: PolicyKey; to: string; label: string }

/** The links for one context, already labelled in the reader's language. */
export function policyLinks(context: PolicyContext, locale: Locale): PolicyLink[] {
  return POLICY_CONTEXTS[context].map((key) => ({
    key,
    to: POLICIES[key].to,
    label: locale === 'ar' ? POLICIES[key].ar : POLICIES[key].en,
  }))
}

/** One policy by name — for a sentence that links to a single page. */
export function policyLink(key: PolicyKey, locale: Locale): PolicyLink {
  return { key, to: POLICIES[key].to, label: locale === 'ar' ? POLICIES[key].ar : POLICIES[key].en }
}

/** Every policy this product publishes — the guard that none is left with nowhere to be reached. */
export const ALL_POLICY_KEYS = Object.keys(POLICIES) as PolicyKey[]
