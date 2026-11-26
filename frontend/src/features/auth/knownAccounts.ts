/**
 * LOGIN-KNOWN-ACCOUNTS-001 — the accounts this browser has signed in with, so the sign-in page can offer them.
 *
 * Only what the person already typed or saw on screen is kept: the email, the display name, whether the account is the
 * platform admin, and when it was last used. Never a password, a token or a session id — picking an account fills the
 * email and the sign-in still asks for the password or code. One session cookie means one signed-in account at a time;
 * this list is a shortcut to the next sign-in, not a second session.
 *
 * The key deliberately sits outside `signOut.ts`'s owned prefixes (`chub:`), so signing out does not forget the list —
 * that is the moment it is needed. Each entry has its own «remove» on the sign-in page for a shared machine.
 */
export interface KnownAccount {
  email: string
  name: string
  isAdmin: boolean
  lastUsedAt: number
}

const KEY = 'campaignshub.known-accounts'
const MAX = 5

export function readKnownAccounts(): KnownAccount[] {
  try {
    const raw = window.localStorage.getItem(KEY)
    if (!raw) return []
    const parsed: unknown = JSON.parse(raw)
    if (!Array.isArray(parsed)) return []

    return parsed
      .filter((a): a is KnownAccount => typeof a === 'object' && a !== null && typeof (a as KnownAccount).email === 'string')
      .map((a) => ({ email: a.email, name: typeof a.name === 'string' ? a.name : '', isAdmin: a.isAdmin === true, lastUsedAt: Number(a.lastUsedAt) || 0 }))
      .sort((a, b) => b.lastUsedAt - a.lastUsedAt)
      .slice(0, MAX)
  } catch {
    return []
  }
}

function write(accounts: KnownAccount[]): void {
  try {
    window.localStorage.setItem(KEY, JSON.stringify(accounts.slice(0, MAX)))
  } catch { /* private mode or storage disabled */ }
}

/** Record (or refresh) the account that is signed in now. A support visit is not the person's own account. */
export function rememberAccount(user: { email?: string | null; name?: string | null; is_platform_admin?: boolean; support?: unknown }): void {
  const email = user.email?.trim().toLowerCase()
  if (!email || user.support) return

  const rest = readKnownAccounts().filter((a) => a.email !== email)
  write([{ email, name: user.name?.trim() ?? '', isAdmin: user.is_platform_admin === true, lastUsedAt: Date.now() }, ...rest])
}

export function forgetAccount(email: string): KnownAccount[] {
  const next = readKnownAccounts().filter((a) => a.email !== email.trim().toLowerCase())
  write(next)

  return next
}
