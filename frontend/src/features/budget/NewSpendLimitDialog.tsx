import { useMemo, useState } from 'react'
import { Modal } from '@/components/ui/Modal'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { DateField } from '@/components/ui/DateField'
import { providerLabel } from '@/features/campaigns/labels'
import { lastNDays, useAccounts, useCampaignOptions } from '@/features/analytics/api'
import { useCreateSpendLimit, type NewSpendLimit, type SpendLimitScope } from './spendLimitsApi'
import type { Locale } from '@/stores/ui'

/**
 * BUDGET-GOVERNANCE-001 — the half of the requirement an operator can actually reach.
 *
 * The page listed limits and computed their pacing, and its empty state said «set a limit for a
 * project, a platform, an account or a campaign» — beside no control that could set one. The
 * endpoint had existed since the first commit of the feature; nothing in the product called it, so
 * the only way to create a limit was to POST by hand.
 *
 * ## The four scopes, and the identifier each one needs
 *
 * `project` and `platform` differ from `account` and `campaign` in one way that matters: the last
 * two are meaningless without saying WHICH. The server refuses a scoped limit with no identifier
 * with a 422 rather than silently widening it to the whole project — a 4,000 cap meant for one
 * TikTok account, measured against every platform's spend, reads «over» on its first day and
 * teaches its owner to ignore the alert. This form asks for the identifier in the same breath as
 * the scope, so that refusal is one the operator never meets.
 *
 * The identifier lists are the ones the rest of the product already uses: the accounts breakdown
 * over the last ninety days, and the campaign options endpoint, which searches server-side.
 *
 * ## Currency
 *
 * A limit is compared against spend the project reports. Where the two currencies differ, the
 * governor withholds every figure and the limit reads «unknown» — deliberately, because pacing
 * against a converted subset reports room that is not there. The field says so BEFORE the limit is
 * created, which is the only moment the operator can act on it.
 */
const SCOPES: { value: SpendLimitScope; ar: string; en: string; needsId: boolean }[] = [
  { value: 'project', ar: 'المشروع كاملًا (كل المنصات)', en: 'The whole project (every platform)', needsId: false },
  { value: 'platform', ar: 'منصة واحدة', en: 'One platform', needsId: true },
  { value: 'account', ar: 'حساب إعلاني', en: 'An ad account', needsId: true },
  { value: 'campaign', ar: 'حملة واحدة', en: 'One campaign', needsId: true },
]

const PROVIDERS = ['meta', 'google', 'tiktok', 'snapchat', 'x', 'linkedin']

/** The thresholds an operator can ask to hear about. 100 is always included by the server. */
const THRESHOLDS = [50, 75, 90]

const T = {
  title: { ar: 'حد إنفاق جديد', en: 'New spend limit' },
  new: { ar: 'حد جديد', en: 'New limit' },
  scope: { ar: 'النطاق', en: 'Scope' },
  platform: { ar: 'المنصة', en: 'Platform' },
  account: { ar: 'الحساب الإعلاني', en: 'Ad account' },
  campaign: { ar: 'الحملة', en: 'Campaign' },
  choose: { ar: 'اختر…', en: 'Choose…' },
  amount: { ar: 'المبلغ', en: 'Amount' },
  currency: { ar: 'العملة', en: 'Currency' },
  currencyHint: {
    ar: 'يُقارَن الحد بإنفاق المشروع بعملته. عملة مختلفة تعني أن الأرقام تُحجب وتُقرأ الحالة «غير معروف».',
    en: 'The limit is compared against the project’s own reported spend. A different currency means the figures are withheld and the limit reads «unknown».',
  },
  from: { ar: 'من', en: 'From' },
  to: { ar: 'إلى', en: 'To' },
  thresholds: { ar: 'نبّهني عند', en: 'Warn me at' },
  thresholdsHint: {
    ar: 'تنبيه عند 100% دائمًا — هذه إضافات قبله.',
    en: 'The 100% warning is always sent; these come before it.',
  },
  create: { ar: 'إنشاء الحد', en: 'Create limit' },
  cancel: { ar: 'إلغاء', en: 'Cancel' },
  enforcement: {
    ar: 'هذا حد داخلي للمراقبة والتنبيه — لا يوقف عرض الإعلانات على أي منصة.',
    en: 'This is an internal limit for watching and warning — it does not stop delivery on any ad platform.',
  },
  needsId: {
    ar: 'اختر ما ينطبق عليه الحد.',
    en: 'Choose what the limit applies to.',
  },
  failed: { ar: 'تعذّر إنشاء الحد.', en: 'The limit could not be created.' },
}

const t = (key: keyof typeof T, ar: boolean) => (ar ? T[key].ar : T[key].en)

/** `YYYY-MM-DD`, built from local parts so a timezone cannot move the day. */
function isoDay(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

export function NewSpendLimitDialog({
  projectId,
  locale,
  currency: suggestedCurrency,
}: {
  projectId: string | null
  locale: Locale
  /** What the project's existing limits are denominated in — the honest default. */
  currency: string
}) {
  const ar = locale === 'ar'
  const [open, setOpen] = useState(false)
  const create = useCreateSpendLimit(projectId)

  const today = useMemo(() => new Date(), [])
  const monthOut = useMemo(() => {
    const d = new Date(today)
    d.setDate(d.getDate() + 29)
    return d
  }, [today])

  const [scope, setScope] = useState<SpendLimitScope>('project')
  const [scopeId, setScopeId] = useState('')
  const [amount, setAmount] = useState('')
  const [currency, setCurrency] = useState(suggestedCurrency)
  const [startsOn, setStartsOn] = useState(isoDay(today))
  const [endsOn, setEndsOn] = useState(isoDay(monthOut))
  const [thresholds, setThresholds] = useState<number[]>([75, 90])
  const [term, setTerm] = useState('')

  const needsId = SCOPES.find((s) => s.value === scope)?.needsId ?? false

  // Ninety days of accounts: an account that spent nothing this week is still an account a limit
  // can be set on, and the shorter windows this product offers would hide it.
  const accounts = useAccounts(needsId && scope === 'account' ? projectId : null, lastNDays(90))
  const campaigns = useCampaignOptions(needsId && scope === 'campaign' ? projectId : null, term)

  const identifierOptions = useMemo(() => {
    if (scope === 'platform') return PROVIDERS.map((p) => ({ value: p, label: providerLabel(p, locale) }))
    if (scope === 'account') {
      return (accounts.data ?? [])
        .filter((a) => a.account_id !== null)
        .map((a) => ({
          value: a.account_id!,
          // The provider beside the name: two accounts on two platforms often carry the same name.
          label: `${a.account_name ?? a.account_id} · ${providerLabel(a.provider, locale)}`,
        }))
    }
    if (scope === 'campaign') return (campaigns.data?.options ?? []).map((c) => ({ value: c.id, label: c.name }))
    return []
  }, [scope, accounts.data, campaigns.data, locale])

  const parsedAmount = Number(amount)
  const ready =
    Number.isFinite(parsedAmount) &&
    parsedAmount > 0 &&
    currency.length === 3 &&
    startsOn !== '' &&
    endsOn !== '' &&
    endsOn >= startsOn &&
    (!needsId || scopeId !== '')

  function submit() {
    if (!ready) return
    const body: NewSpendLimit = {
      scope,
      scope_id: needsId ? scopeId : null,
      amount: parsedAmount,
      currency: currency.toUpperCase(),
      starts_on: startsOn,
      ends_on: endsOn,
      thresholds,
    }

    create.mutate(body, {
      onSuccess: () => {
        setOpen(false)
        setAmount('')
        setScopeId('')
      },
    })
  }

  return (
    <>
      <Button data-testid="spend-limit-new" onClick={() => setOpen(true)}>
        {t('new', ar)}
      </Button>

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title={t('title', ar)}
        footer={
          <div className="flex flex-wrap items-center justify-end gap-2">
            <Button variant="secondary" onClick={() => setOpen(false)}>
              {t('cancel', ar)}
            </Button>
            <Button data-testid="spend-limit-submit" disabled={!ready || create.isPending} onClick={submit}>
              {t('create', ar)}
            </Button>
          </div>
        }
      >
        <div data-testid="spend-limit-form" className="flex flex-col gap-4">
          {/* The sentence first, as it is on the page itself: what this limit does and does not do. */}
          <p className="rounded-xl border border-warning/40 bg-warning/5 p-3 text-sm text-text-secondary">
            {t('enforcement', ar)}
          </p>

          <Field label={t('scope', ar)} htmlFor="spend-limit-scope" required>
            <Select
              id="spend-limit-scope"
              data-testid="spend-limit-scope"
              value={scope}
              options={SCOPES.map((s) => ({ value: s.value, label: ar ? s.ar : s.en }))}
              onChange={(e) => {
                setScope(e.target.value as SpendLimitScope)
                setScopeId('')
              }}
            />
          </Field>

          {needsId && (
            <Field
              label={t(scope === 'platform' ? 'platform' : scope === 'account' ? 'account' : 'campaign', ar)}
              htmlFor="spend-limit-scope-id"
              hint={t('needsId', ar)}
              required
            >
              {scope === 'campaign' && (
                <Input
                  data-testid="spend-limit-campaign-search"
                  className="mb-2"
                  value={term}
                  placeholder={t('choose', ar)}
                  onChange={(e) => setTerm(e.target.value)}
                />
              )}
              <Select
                id="spend-limit-scope-id"
                data-testid="spend-limit-scope-id"
                value={scopeId}
                placeholder={t('choose', ar)}
                options={identifierOptions}
                onChange={(e) => setScopeId(e.target.value)}
              />
            </Field>
          )}

          <div className="grid gap-3 sm:grid-cols-2">
            <Field label={t('amount', ar)} htmlFor="spend-limit-amount" required>
              <Input
                id="spend-limit-amount"
                data-testid="spend-limit-amount"
                dir="ltr"
                inputMode="decimal"
                value={amount}
                onChange={(e) => setAmount(e.target.value)}
              />
            </Field>

            <Field label={t('currency', ar)} htmlFor="spend-limit-currency" hint={t('currencyHint', ar)} required>
              <Input
                id="spend-limit-currency"
                data-testid="spend-limit-currency"
                dir="ltr"
                maxLength={3}
                value={currency}
                onChange={(e) => setCurrency(e.target.value.toUpperCase())}
              />
            </Field>
          </div>

          <div className="grid gap-3 sm:grid-cols-2">
            {/*
              `DateField`, never a native date input: the browser renders its own sub-fields in the
              page's locale, and under Arabic that is a garbled control the product does not own.
            */}
            <Field label={t('from', ar)} htmlFor="spend-limit-from" required>
              <DateField id="spend-limit-from" data-testid="spend-limit-from" value={startsOn} onChange={setStartsOn} />
            </Field>
            <Field label={t('to', ar)} htmlFor="spend-limit-to" required>
              <DateField id="spend-limit-to" data-testid="spend-limit-to" value={endsOn} onChange={setEndsOn} />
            </Field>
          </div>

          <Field label={t('thresholds', ar)} hint={t('thresholdsHint', ar)}>
            <div className="flex flex-wrap gap-2">
              {THRESHOLDS.map((p) => {
                const on = thresholds.includes(p)

                return (
                  <button
                    key={p}
                    type="button"
                    data-testid={`spend-limit-threshold-${p}`}
                    aria-pressed={on}
                    onClick={() => setThresholds((cur) => (on ? cur.filter((x) => x !== p) : [...cur, p].sort((a, b) => a - b)))}
                    className={`tnum h-11 rounded-xl border px-4 text-sm font-semibold transition-colors sm:h-9 ${
                      on
                        ? 'border-brand-500 bg-brand-500/10 text-brand-600'
                        : 'border-border bg-surface text-text-secondary hover:bg-surface-hover'
                    }`}
                  >
                    {p}%
                  </button>
                )
              })}
            </div>
          </Field>

          {create.isError && (
            <p data-testid="spend-limit-error" className="text-sm font-semibold text-danger">
              {t('failed', ar)}
            </p>
          )}
        </div>
      </Modal>
    </>
  )
}
