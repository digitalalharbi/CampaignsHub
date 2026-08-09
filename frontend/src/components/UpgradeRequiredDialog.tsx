import { ArrowUpRight } from 'lucide-react'
import { Modal } from './ui/Modal'
import { Button } from './ui/Button'
import { useUi } from '@/stores/ui'
import { useUpgrade } from '@/stores/upgrade'

/**
 * What to do when the plan says no — PAY-AUDIT-004.
 *
 * The commission asks for entitlements «gating real features with an in-context upgrade path rather
 * than a bare 403». Both halves were missing in different ways: `EnsureEntitlement` refused a whole
 * section with a sentence and nothing else, and `EnsureWithinPlanLimit` answered properly — with the
 * metric, the usage, the cap and a route to upgrade — to an interface that read none of it.
 *
 * So this is the one surface both refusals reach, and it says three things a red toast could not:
 * what was refused, how close to the cap they actually are, and where to go. The numbers matter most:
 * «3 of 3» answers «is upgrading going to help?» in a way «not allowed» never does.
 *
 * ## Why a plain anchor and not `<Link>`
 *
 * This is mounted in `Providers`, ABOVE the router, so that a refusal is answered identically in
 * every portal rather than depending on which page the person happened to be on. Above the router
 * there is no router context, and `<Link>` throws there — which is exactly what it did on the first
 * live run: React logged «An error occurred in the <Link> component» and the dialog rendered
 * nothing at all, while the 403 it was built to explain arrived perfectly.
 *
 * The unit test did not catch it because it wrapped the component in a `MemoryRouter` — testing a
 * mounting condition the application never uses. It now renders bare, the way it really mounts.
 */

const T = {
  limitTitle: { ar: 'وصلت إلى حد باقتك', en: 'You have reached your plan limit' },
  entitlementTitle: { ar: 'هذه الخاصية ليست ضمن باقتك', en: 'This is not part of your plan' },
  usage: { ar: 'المستخدَم', en: 'In use' },
  plan: { ar: 'باقتك الحالية', en: 'Your current plan' },
  upgrade: { ar: 'عرض الباقات', en: 'See the plans' },
  later: { ar: 'ليس الآن', en: 'Not now' },
  /*
   * Said explicitly, because it is the first thing anybody blocked mid-task fears.
   *
   * Nothing was deleted and nothing was hidden — the action was refused, and that is all that
   * happened. `SubscriptionLifecycle::suspend()` makes the same promise for a whole account
   * («عدم حذف بيانات العميل عند التعليق»); this is that promise at the scale of one click.
   */
  intact: {
    ar: 'لم يُحذف شيء ولم يتغيّر شيء — لم يُنفَّذ هذا الإجراء فقط.',
    en: 'Nothing was deleted and nothing changed — only this one action was refused.',
  },
}

/** The metric names, in words. An axis key printed at a reader is the defect NORM-001 exists for. */
const SUBJECTS: Record<string, { ar: string; en: string }> = {
  projects: { ar: 'المشاريع', en: 'Projects' },
  campaigns: { ar: 'الحملات', en: 'Campaigns' },
  team_members: { ar: 'أعضاء الفريق', en: 'Team members' },
  connections: { ar: 'الاتصالات', en: 'Connections' },
  reports_per_month: { ar: 'التقارير هذا الشهر', en: 'Reports this month' },
}

export function UpgradeRequiredDialog() {
  const ar = useUi((s) => s.locale) === 'ar'
  const refusal = useUpgrade((s) => s.refusal)
  const dismiss = useUpgrade((s) => s.dismiss)

  if (refusal === null) return null

  const t = (key: keyof typeof T) => (ar ? T[key].ar : T[key].en)
  const subject = refusal.subject !== null && SUBJECTS[refusal.subject] !== undefined
    ? (ar ? SUBJECTS[refusal.subject].ar : SUBJECTS[refusal.subject].en)
    : refusal.subject

  return (
    <Modal
      open
      onClose={dismiss}
      title={refusal.reason === 'plan_limit' ? t('limitTitle') : t('entitlementTitle')}
      footer={
        <>
          <Button variant="secondary" onClick={dismiss}>{t('later')}</Button>
          <a
            href={refusal.upgradePath}
            onClick={dismiss}
            data-testid="upgrade-link"
            className="inline-flex h-10 items-center gap-1.5 rounded-xl bg-brand-600 px-3.5 text-sm font-semibold text-white hover:bg-brand-700"
          >
            {t('upgrade')} <ArrowUpRight size={16} aria-hidden />
          </a>
        </>
      }
    >
      <div data-testid="upgrade-required" className="space-y-3">
        {/* The server's sentence, already in the reader's language and already carrying the numbers. */}
        <p className="text-sm leading-7 text-text-primary">{refusal.message}</p>

        {/*
          The cap again, as a figure rather than inside a sentence.
          Latin digits, as everywhere in this product: a cap in Eastern-Arabic numerals cannot be
          compared against the list the customer is looking at.
        */}
        {refusal.limit !== null && refusal.used !== null && (
          <div className="flex flex-wrap items-center gap-2 rounded-xl border border-border bg-surface-secondary px-3 py-2">
            <span className="text-xs font-semibold text-text-secondary">
              {subject !== null ? `${subject} — ` : ''}{t('usage')}
            </span>
            <span className="tnum text-sm font-bold text-text-primary">
              {refusal.used} / {refusal.limit}
            </span>
          </div>
        )}

        {refusal.plan !== null && (
          <p className="text-xs text-text-secondary">
            {t('plan')}: <span className="font-semibold text-text-primary">{refusal.plan}</span>
          </p>
        )}

        <p className="text-xs text-text-muted">{t('intact')}</p>
      </div>
    </Modal>
  )
}
