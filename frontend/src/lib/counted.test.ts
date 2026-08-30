import { describe, expect, it } from 'vitest'
import { accounts, adAccounts, campaigns, clients, connectedAccounts, countedAr, days } from './counted'

/**
 * The four Arabic forms, and the boundaries nobody remembers at a call site.
 *
 * Every one of these was rendered wrongly somewhere in the product before this existed: the
 * integrations banner said «1 حسابًا متاحًا» for a single account and «1 accounts available» in
 * English, on the same screen, in the same release.
 */
describe('counting a noun', () => {
  it('takes the singular at one, the dual at two, the plural through ten and the accusative after', () => {
    expect(accounts(1, 'ar')).toBe('1 حساب')
    expect(accounts(2, 'ar')).toBe('2 حسابان')
    expect(accounts(3, 'ar')).toBe('3 حسابات')
    expect(accounts(10, 'ar')).toBe('10 حسابات')
    expect(accounts(11, 'ar')).toBe('11 حسابًا')
    expect(accounts(25, 'ar')).toBe('25 حسابًا')
    // 309 ends in a nine, so it counts like nine: «309 حسابات», not «309 حسابًا».
    expect(accounts(309, 'ar')).toBe('309 حسابات')
  })

  /** 103 counts like 3, not like 100 — the rule is on the last two digits. */
  it('reads the last two digits, so 103 counts like 3', () => {
    expect(countedAr(103, { one: 'حساب', two: 'حسابان', few: 'حسابات', many: 'حسابًا' })).toBe('103 حسابات')
    expect(countedAr(100, { one: 'حساب', two: 'حسابان', few: 'حسابات', many: 'حسابًا' })).toBe('100 حسابًا')
  })

  it('keeps the digits Latin in both languages', () => {
    expect(accounts(309, 'ar')).toMatch(/^309 /)
    expect(accounts(309, 'en')).toBe('309 accounts')
    expect(accounts(1, 'en')).toBe('1 account')
  })

  it('agrees an adjective with the count too, which is the form that survives longest wrong', () => {
    expect(adAccounts(1, 'ar')).toBe('1 حساب إعلاني')
    expect(adAccounts(3, 'ar')).toBe('3 حسابات إعلانية')
    expect(adAccounts(12, 'ar')).toBe('12 حسابًا إعلانيًا')
    expect(adAccounts(1, 'en')).toBe('1 ad account')
    expect(adAccounts(3, 'en')).toBe('3 ad accounts')
  })

  it('agrees the adjective with the count as well as the noun', () => {
    expect(connectedAccounts(1, 'ar')).toBe('1 حساب مربوط')
    expect(connectedAccounts(2, 'ar')).toBe('2 حسابان مربوطان')
    expect(connectedAccounts(6, 'ar')).toBe('6 حسابات مربوطة')
    expect(connectedAccounts(1, 'en')).toBe('1 account connected')
  })

  /** The nouns this product counts on nearly every screen, each on its own boundaries. */
  it('counts campaigns, clients and days the same way', () => {
    expect(campaigns(1, 'en')).toBe('1 campaign')
    expect(campaigns(1, 'ar')).toBe('1 حملة')
    expect(campaigns(4, 'ar')).toBe('4 حملات')
    expect(clients(2, 'ar')).toBe('2 عميلان')
    expect(clients(12, 'ar')).toBe('12 عميلًا')
    expect(days(7, 'ar')).toBe('7 أيام')
    expect(days(30, 'ar')).toBe('30 يومًا')
    expect(days(1, 'en')).toBe('1 day')
  })
})
