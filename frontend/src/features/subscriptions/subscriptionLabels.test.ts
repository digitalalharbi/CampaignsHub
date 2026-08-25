import { describe, expect, it } from 'vitest'

import { COPY, featureLabel, featureValueLabel, metricLabel } from './SubscriptionsPage'

/**
 * SUBSCRIPTION-LABELS-001 — no English identifier reaches the page a customer reads before paying.
 *
 * The usage table read «clients 1 4 5»; a plan card read «reports نعم», «campaign tracking نعم» and
 * «الدعم community». The label maps covered a subset of the seeder's keys and the fallbacks printed
 * the rest verbatim.
 *
 * The key lists below are `database/seeders/SubscriptionPlanSeeder.php`. TypeScript cannot read PHP,
 * so they are written out — a plan gaining a feature fails HERE rather than showing a column name to
 * somebody deciding whether to buy.
 */
describe('subscription labels cover what the seeder actually sends', () => {
  const LIMIT_KEYS = ['projects', 'clients', 'team_members', 'connections', 'ad_accounts', 'reports_per_month']
  const FEATURE_KEYS = ['reports', 'campaign_tracking', 'ai_assist', 'white_label', 'support']
  const SUPPORT_VALUES = ['community', 'email', 'priority']

  for (const [locale, c] of Object.entries(COPY)) {
    it.each(LIMIT_KEYS)(`labels the ${locale} usage metric %s`, (key) => {
      expect(metricLabel(key, c), `${key} would render as its own key`).not.toBe(key)
    })

    it.each(FEATURE_KEYS)(`labels the ${locale} plan feature %s`, (key) => {
      const label = featureLabel(key, c)

      expect(label).not.toBe(key)
      // `key.replace(/_/g, ' ')` is the fallback — «campaign tracking» is the key with a space in it.
      expect(label).not.toBe(key.replace(/_/g, ' '))
    })

    it.each(SUPPORT_VALUES)(`labels the ${locale} support tier %s`, (value) => {
      expect(featureValueLabel(value, c)).not.toBe(value)
    })
  }

  it('shows an unknown key as itself, so a new plan field is visibly unlabelled and not disguised', () => {
    expect(metricLabel('warehouses', COPY.ar)).toBe('warehouses')
    expect(featureValueLabel('enterprise', COPY.ar)).toBe('enterprise')
  })
})
