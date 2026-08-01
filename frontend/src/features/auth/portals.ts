import type { PortalKey } from './memberships'
import { features } from '@/lib/features'

/**
 * The five doors, in one place (LOGIN-FINAL).
 *
 * Every portal gets its own address — `/admin/login`, `/app/login`, `/agency/login`,
 * `/influencers/login`, `/portal/login` — because a person arriving at work has a portal in mind and
 * a single `/login` makes them pick from a list before it will talk to them. What they must NOT get
 * is five sign-in engines: this table is content, and one form reads it.
 *
 * `/portal` is deliberately absent from the password set. It authenticates by one-time code, and
 * offering it a password field would be claiming support for something that does not exist — see
 * `clientPortal` below, which points at its own door instead of pretending.
 */
export const PASSWORD_PORTALS = ['admin', 'app', 'agency', 'influencers'] as const

export type PasswordPortal = (typeof PASSWORD_PORTALS)[number]

export interface PortalDoor {
  /** The path this door lives at. */
  path: string
  /**
   * The portal claimed on the way in — a PREFERENCE the server checks, never a grant.
   *
   * `admin` is null: the platform console is held by a flag rather than a membership, and naming it
   * here would have the form ask the server to honour a portal no membership can name. The server
   * routes an owner to `/admin` on its own.
   */
  claim: PortalKey | null
  ar: { title: string; blurb: string; audience: string }
  en: { title: string; blurb: string; audience: string }
}

export const PORTAL_DOORS: Record<PasswordPortal, PortalDoor> = {
  admin: {
    path: '/admin/login',
    claim: null,
    ar: {
      title: 'دخول إدارة المنصة',
      blurb: 'لوحة مالك المنصة: المستأجرون والباقات والمدفوعات والتسجيلات والتكاملات.',
      audience: 'لمالكي المنصة ومشغّليها',
    },
    en: {
      title: 'Platform administration',
      blurb: 'The owner’s console: tenants, plans, payments, registrations and integrations.',
      audience: 'For the platform’s owners and operators',
    },
  },
  app: {
    path: '/app/login',
    claim: 'app',
    ar: {
      title: 'دخول إدارة الحملات',
      blurb: 'تابع حملاتك وميزانياتك ونتائجك عبر جميع المنصات من مكان واحد.',
      audience: 'للمعلنين والعلامات التجارية',
    },
    en: {
      title: 'Campaign management',
      blurb: 'Track your campaigns, budgets and results across every platform from one place.',
      audience: 'For advertisers and brands',
    },
  },
  agency: {
    path: '/agency/login',
    claim: 'agency',
    ar: {
      title: 'دخول الوكالة',
      blurb: 'أدِر عملاءك ومشاريعهم وفواتيرهم، ولكل عميل مساحته المعزولة.',
      audience: 'لوكالات الإعلان ومديري الحسابات',
    },
    en: {
      title: 'Agency',
      blurb: 'Run your clients, their projects and their invoices — each in its own isolated space.',
      audience: 'For agencies and account managers',
    },
  },
  influencers: {
    path: '/influencers/login',
    claim: 'influencers',
    ar: {
      title: 'دخول المؤثرين وUGC',
      blurb: 'الترشيحات والتعاونات والمخرجات وروابط التتبع ونتائج كل منشور.',
      audience: 'لفرق التسويق عبر المؤثرين وصنّاع المحتوى',
    },
    en: {
      title: 'Influencers & UGC',
      blurb: 'Nominations, collaborations, deliverables, tracking links and per-post results.',
      audience: 'For influencer teams and creators',
    },
  },
}

/**
 * The fifth door, which is not a password door.
 *
 * Kept beside the others so it is never forgotten, and typed differently so it cannot accidentally
 * be rendered by the password form.
 */
export const CLIENT_PORTAL_DOOR = {
  path: '/portal/login',
  ar: {
    title: 'متابعة الطلبات',
    blurb: 'طلباتك وعروضك وفواتيرك وحالة التنفيذ.',
    audience: 'للعملاء',
    method: 'الدخول برمز تحقق يُرسل إلى بريدك أو جوالك — لا تحتاج كلمة مرور.',
  },
  en: {
    title: 'Track your requests',
    blurb: 'Your requests, quotes, invoices and how the work is going.',
    audience: 'For clients',
    method: 'You sign in with a one-time code sent to your email or phone — no password needed.',
  },
} as const

/**
 * The doors a visitor may be SHOWN right now (INFL-OFF-001).
 *
 * `PORTAL_DOORS` above stays complete — it is the vocabulary, and `/influencers/login` still resolves
 * through it so the address has copy to render the day the sub-system returns. This is the list every
 * surface iterates when it OFFERS a choice, so a door that is closed cannot be advertised by a panel
 * that forgot about the flag.
 */
export function offeredDoors(): Array<[PasswordPortal, PortalDoor]> {
  return (Object.entries(PORTAL_DOORS) as Array<[PasswordPortal, PortalDoor]>)
    .filter(([key]) => key !== 'influencers' || features.influencersUgc)
}

/** The door a path belongs to, or null when the path is not one. */
export function doorFor(pathname: string): PasswordPortal | null {
  const entry = Object.entries(PORTAL_DOORS).find(([, door]) => door.path === pathname)

  return entry ? (entry[0] as PasswordPortal) : null
}
