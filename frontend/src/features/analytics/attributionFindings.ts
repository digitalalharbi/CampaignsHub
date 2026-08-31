import { countedAr, countedEn, days as countedDays } from '@/lib/counted'
import type { Attribution, PlatformClaim } from './api'

/**
 * DATA-QUALITY-OPERATOR-UX-001 · CROSS-PLATFORM-ATTRIBUTION-DEPTH-001 — the attribution half of the
 * tab, written for the person who has to act.
 *
 * ## What this half was
 *
 * A table of claims: platform, orders claimed, orders the shop confirmed, the difference. Every
 * column is true, and together they answer a question an analyst asks. The account manager who
 * opens this tab has a client asking why the numbers do not agree, and needs six answers the table
 * implies and never states: what is wrong, what it affects, how much it matters, what they can check
 * themselves, whether anyone can fix it, and whether it needs a provider action.
 *
 * ## Nothing here is a new measurement
 *
 * Every finding is derived from the attribution payload the tab already reads. A second computation
 * of «do these platforms agree» is how two surfaces come to disagree about a disagreement.
 *
 * ## The distinction the findings exist to hold
 *
 * A window that DIFFERS between platforms and a window that is UNKNOWN are not the same problem. The
 * first means the totals were collected under different rules and should not be added; the second
 * means nobody can say whether they were. Merging them into «attribution issue» is what makes a
 * reader stop reading them.
 */
export type AttributionSeverity = 'critical' | 'attention' | 'watch'

export type AttributionOwner = 'system' | 'operator' | 'provider' | 'nobody'

export interface AttributionFinding {
  key: string
  provider: string | null
  severity: AttributionSeverity
  owner: AttributionOwner
  what: { ar: string; en: string }
  affects: { ar: string; en: string }
  check: { ar: string; en: string }
}

const ORDER: Record<AttributionSeverity, number> = { critical: 0, attention: 1, watch: 2 }

/** «طلب · طلبان · طلبات · طلبًا» — the counted forms of the noun this file states most often. */
const ORDER_AR = { one: 'طلب', two: 'طلبان', few: 'طلبات', many: 'طلبًا' }

export function attributionFindings(data: Attribution | undefined): AttributionFinding[] {
  if (data === undefined) return []

  const platforms = data.platform_reported?.platforms ?? []
  const out: AttributionFinding[] = []

  for (const p of platforms) {
    if (p.attribution?.mixed_windows) {
      out.push(mixedWindows(p))
    } else if (p.attribution?.window_known === false) {
      out.push(unknownWindow(p))
    }
  }

  /*
   * Across platforms: two different click windows means the totals beneath them were collected
   * under different rules. This is stated once for the SET rather than per platform — «Meta is 7d
   * and Snapchat is 1d» is one fact about the comparison, not two facts about two platforms.
   */
  const clicks = new Set(
    platforms
      .map((p) => p.attribution?.click_through_days)
      .filter((d): d is number => typeof d === 'number'),
  )

  if (clicks.size > 1) {
    /*
      Each window said as its own counted phrase — «1 day, 7 days» rather than «1, 7 days», which
      reads as though the first number shared the second's noun.
    */
    const list = [...clicks].sort((a, b) => a - b).map((d) => countedDays(d, 'en')).join(', ')
    const listAr = [...clicks].sort((a, b) => a - b).map((d) => countedDays(d, 'ar')).join('، ')
    out.push({
      key: 'click-windows-differ',
      provider: null,
      severity: 'attention',
      owner: 'provider',
      what: {
        ar: `المنصات تحتسب النقرة على نوافذ مختلفة (${listAr})، فأرقامها جُمعت بقواعد غير واحدة.`,
        en: `The platforms count a click over different windows (${list}), so their figures were collected under different rules.`,
      },
      affects: {
        ar: 'مجموع النتائج عبر المنصات ليس مقياسًا واحدًا، ومقارنتها ببعضها تقارن قاعدتين لا أداءين.',
        en: 'The total across platforms is not one measurement, and comparing them compares two rules rather than two performances.',
      },
      check: {
        ar: 'يمكن توحيد النافذة من إعدادات كل منصة؛ حتى ذلك الحين اقرأ كل منصة على حدة.',
        en: 'The window can be aligned in each platform’s own settings; until then read each platform on its own.',
      },
    })
  }

  /*
   * View-through on some platforms and not others is the same class of problem and a different
   * sentence: one is counting people who never clicked, and the other is not.
   */
  const view = new Set(
    platforms
      .map((p) => p.attribution?.includes_view_through)
      .filter((v): v is boolean => typeof v === 'boolean'),
  )

  if (view.size > 1) {
    out.push({
      key: 'view-through-differs',
      provider: null,
      severity: 'attention',
      owner: 'provider',
      what: {
        ar: 'بعض المنصات تحتسب المشاهدة دون نقر وبعضها لا يحتسبها.',
        en: 'Some platforms count a view without a click and others do not.',
      },
      affects: {
        ar: 'المنصات التي تحتسب المشاهدة ستبدو أعلى نتائج — وهو فرق في التعريف لا في الأداء.',
        en: 'The platforms that count views will look like they produced more — a difference in definition, not in performance.',
      },
      check: {
        ar: 'قارن المنصات التي تشترك في نفس التعريف فقط، أو وحّد الإعداد على المنصة.',
        en: 'Compare only platforms that share a definition, or align the setting on the platform itself.',
      },
    })
  }

  /*
   * Orders the SHOP recorded that no platform claimed. Not a fault: direct traffic, an old link, a
   * blocked pixel. It is reported at `watch` because it is a normal part of every account, and the
   * number is what tells an operator whether it has grown.
   */
  const unattributed = data.unattributed
  if (unattributed?.available && (unattributed.orders ?? 0) > 0) {
    const share = unattributed.share === null || unattributed.share === undefined
      ? null
      : `${Math.round(unattributed.share * 100)}%`

    out.push({
      key: 'unattributed-orders',
      provider: null,
      severity: 'watch',
      owner: 'nobody',
      what: {
        ar: `${countedAr(unattributed.orders ?? 0, ORDER_AR)} سجّلها المتجر ولم تدّعِها أي منصة${share ? ` (${share} من الطلبات)` : ''}.`,
        en: `${countedEn(unattributed.orders ?? 0, 'order', 'orders')} the shop recorded that no platform claimed${share ? ` (${share} of orders)` : ''}.`,
      },
      affects: {
        ar: 'العائد على الإنفاق المحسوب من مطالبات المنصات أقل من الحقيقة بمقدار هذه الطلبات.',
        en: 'A return computed from platform claims alone is lower than the truth by these orders.',
      },
      check: {
        ar: 'زيارة مباشرة أو رابط قديم أو بكسل محجوب — طبيعي في كل حساب، والمهم أن تراقب اتجاهه لا وجوده.',
        en: 'Direct traffic, an old link, a blocked pixel — normal in every account; what matters is the trend, not the presence.',
      },
    })
  }

  return out.sort((a, b) => ORDER[a.severity] - ORDER[b.severity] || (a.provider ?? '').localeCompare(b.provider ?? ''))
}

function mixedWindows(p: PlatformClaim): AttributionFinding {
  const windows = (p.attribution?.windows ?? []).map((w) => w.window).join(' · ')

  return {
    key: `mixed-windows-${p.provider}`,
    provider: p.provider,
    severity: 'attention',
    owner: 'provider',
    what: {
      ar: `أرقام هذه المنصة في هذه الفترة جُمعت تحت أكثر من نافذة إسناد (${windows}).`,
      en: `This platform’s figures in this window were collected under more than one attribution window (${windows}).`,
    },
    affects: {
      ar: 'إجمالي المنصة نفسه ليس مقياسًا واحدًا، فحتى مقارنته بنفسه عبر الزمن تقارن قاعدتين.',
      en: 'The platform’s own total is not one measurement, so even comparing it with itself over time compares two rules.',
    },
    check: {
      ar: 'غالبًا إعداد تغيّر داخل الفترة — راجع إعداد الإسناد على المنصة وحدّد متى تغيّر.',
      en: 'Usually a setting that changed inside the window — check the platform’s attribution setting and when it changed.',
    },
  }
}

function unknownWindow(p: PlatformClaim): AttributionFinding {
  return {
    key: `unknown-window-${p.provider}`,
    provider: p.provider,
    severity: 'watch',
    owner: 'provider',
    what: {
      ar: 'لم تذكر هذه المنصة النافذة التي احتُسبت عليها نتائجها.',
      en: 'This platform did not state the window its results were counted over.',
    },
    affects: {
      ar: 'الأرقام صحيحة كما أرسلتها المنصة، لكن قابليتها للمقارنة مع غيرها غير معروفة.',
      en: 'The figures are correct as the platform sent them; whether they are comparable with the others is unknown.',
    },
    check: {
      ar: 'يظهر الإعداد في لوحة المنصة نفسها — لا شيء في هذا المنتج يمكنه استنتاجه.',
      en: 'The setting is visible in the platform’s own dashboard — nothing in this product can infer it.',
    },
  }
}
