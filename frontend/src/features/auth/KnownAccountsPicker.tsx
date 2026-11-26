import { useState } from 'react'
import { ShieldCheck, X } from 'lucide-react'
import { forgetAccount, readKnownAccounts, type KnownAccount } from './knownAccounts'

/**
 * LOGIN-KNOWN-ACCOUNTS-001 — «الحسابات على هذا الجهاز» on the sign-in page.
 *
 * Picking an account fills its email and moves to the password; nothing signs in by itself. Hidden when the browser
 * has no account on record, so a first visit sees the plain card.
 */
export function KnownAccountsPicker({ ar, selected, onPick }: { ar: boolean; selected: string; onPick: (email: string) => void }) {
  const [accounts, setAccounts] = useState<KnownAccount[]>(readKnownAccounts)

  if (accounts.length === 0) return null

  const current = selected.trim().toLowerCase()

  return (
    <section data-testid="login-known-accounts" className="mt-5">
      <h3 className="mb-2 text-sm font-semibold text-text-secondary">{ar ? 'الحسابات على هذا الجهاز' : 'Accounts on this device'}</h3>
      <ul className="grid gap-2">
        {accounts.map((a) => {
          const active = a.email === current
          const label = a.name !== '' ? a.name : a.email

          return (
            <li key={a.email} className="relative">
              <button
                type="button"
                data-testid="login-known-account"
                aria-pressed={active}
                onClick={() => onPick(a.email)}
                className={`flex w-full min-h-[56px] items-center gap-3 rounded-xl border bg-surface py-2.5 pe-11 ps-3 text-start transition-colors hover:border-brand-500 ${
                  active ? 'border-brand-500 ring-[3px] ring-brand-500/15' : 'border-border'
                }`}
              >
                <span aria-hidden className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-600/15 font-heading text-sm font-bold text-brand-600">
                  {label.trim().charAt(0).toUpperCase()}
                </span>
                <span className="min-w-0 flex-1">
                  <span className="flex items-center gap-1.5 truncate text-sm font-bold text-text-primary">
                    {label}
                    {a.isAdmin && (
                      <span className="inline-flex items-center gap-1 rounded-full bg-brand-600/10 px-2 py-0.5 text-[11px] font-semibold text-brand-600">
                        <ShieldCheck size={12} /> {ar ? 'لوحة الإدارة' : 'Admin'}
                      </span>
                    )}
                  </span>
                  {a.name !== '' && <span dir="ltr" className="block truncate text-xs text-text-muted" style={{ textAlign: ar ? 'right' : 'left' }}>{a.email}</span>}
                </span>
              </button>
              <button
                type="button"
                data-testid="login-known-account-remove"
                onClick={() => setAccounts(forgetAccount(a.email))}
                aria-label={ar ? `إزالة ${a.email} من هذا الجهاز` : `Remove ${a.email} from this device`}
                className="absolute end-2 top-1/2 -translate-y-1/2 rounded-lg p-2 text-text-muted hover:bg-surface-hover hover:text-text-primary"
              >
                <X size={15} />
              </button>
            </li>
          )
        })}
      </ul>
    </section>
  )
}
