import { rankableMoney, type MoneyTotals } from '@/lib/money/contract'
import type { AccountRow } from './api'

/**
 * PLATFORM-DECISION-ANALYTICS-001 — account contribution: which ad accounts carry a platform's spend.
 *
 * «Snapchat spent 18.8K» is not an answer when two accounts run on Snapchat: the operator deciding
 * where to move money needs the share each account carries. Shares are computed only over spend the
 * money contract can state in the reporting currency; a withheld account is counted, named, and
 * given no share, never a zero.
 */
export interface AccountShare {
  account_id: string | null
  account_name: string | null
  spend: number | null
  /** Share of the platform's stated spend, 0..1; null when this account's spend could not be stated. */
  share: number | null
  conversions: number | null
}

export interface PlatformContribution {
  provider: string
  stated: number
  withheld: number
  accounts: AccountShare[]
}

export function accountContribution(rows: AccountRow[], reportingCurrency: string | null): PlatformContribution[] {
  const byProvider = new Map<string, AccountRow[]>()
  for (const row of rows) {
    const list = byProvider.get(row.provider) ?? []
    list.push(row)
    byProvider.set(row.provider, list)
  }
  const out: PlatformContribution[] = []
  for (const [provider, accounts] of byProvider) {
    const ranked = rankableMoney(accounts as MoneyTotals[], 'spend', reportingCurrency)
    const values = ranked?.values ?? accounts.map(() => null)
    const total = values.reduce<number>((sum, v) => sum + (v ?? 0), 0)
    const shares: AccountShare[] = accounts.map((a, i) => ({
      account_id: a.account_id,
      account_name: a.account_name,
      spend: values[i] ?? null,
      share: values[i] === null || values[i] === undefined || total <= 0 ? null : (values[i] as number) / total,
      conversions: typeof a.conversions === 'number' ? a.conversions : null,
    }))
    shares.sort((x, y) => (y.share ?? -1) - (x.share ?? -1))
    out.push({ provider, stated: total, withheld: shares.filter((s) => s.share === null).length, accounts: shares })
  }
  out.sort((a, b) => b.stated - a.stated)
  return out
}
