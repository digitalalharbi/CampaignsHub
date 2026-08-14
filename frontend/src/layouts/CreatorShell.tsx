import { Outlet } from 'react-router-dom'
import { PortalFooter } from '@/features/legal/PolicyFooter'
import { useQuery } from '@tanstack/react-query'
import { Loader2, Moon, Sun } from 'lucide-react'
import { AccountMenu } from '@/features/account/UserMenu'
import { fetchCreatorProfile } from '@/features/influencers/creator/api'
import { useUi } from '@/stores/ui'

/**
 * The creator's shell (INFL-002, ADR 0002).
 *
 * Deliberately has NO sidebar, and that is a decision rather than an omission.
 *
 * The operator's shell carries a rail because an operator moves between a roster, a book of
 * agreements and a deliverables queue. A creator has one place to be: their own work. A rail with a
 * single entry would be chrome pretending there is more behind it, and the usual fix — padding it
 * out with "Profile", "Settings", "Help" — invents sections to justify the furniture. Personal
 * settings live in the account menu, which is where they live in every portal here.
 *
 * So this is the same product, same theming, same language toggle, and a visibly different surface —
 * which is the point of a portal as opposed to a permission level.
 */
export function CreatorShell() {
  const { theme, locale, toggleTheme, toggleLocale } = useUi()
  const ar = locale === 'ar'

  // Asked once here so the header can name the person, and so a login that is NOT a creator gets a
  // straight answer instead of a shell wrapped around a page of failed requests.
  const profile = useQuery({ queryKey: ['creator', 'profile'], queryFn: fetchCreatorProfile, retry: false })

  const notACreator = profile.isError && (profile.error as { status?: number } | null)?.status === 403

  return (
    <div data-testid="creator-shell" className="flex min-h-screen flex-col bg-background text-text-primary">
      <header className="sticky top-0 z-40 border-b border-border bg-surface/85 backdrop-blur-md">
        <div className="mx-auto flex w-full max-w-[1080px] items-center gap-3 px-4 py-3 sm:px-6">
          <div className="flex min-w-0 items-center gap-2.5">
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 text-sm font-extrabold text-white">
              {(profile.data?.creator.name ?? 'C').slice(0, 1)}
            </div>
            <div className="min-w-0">
              <span className="block truncate font-heading text-[15px] font-extrabold tracking-tight">
                {profile.data?.creator.name ?? (ar ? 'مساحتك' : 'Your space')}
              </span>
              {/* Silent for someone who is not a creator here: "your work with the agency" over a
                  panel explaining they have none is the header contradicting the page. */}
              {!notACreator && (
                <span className="block truncate text-[11.5px] text-text-muted">
                  {ar ? 'أعمالك مع الوكالة' : 'Your work with the agency'}
                </span>
              )}
            </div>
          </div>

          <div className="ms-auto flex items-center gap-1.5">
            <button
              onClick={toggleLocale}
              aria-label="Toggle language"
              className="flex h-11 min-w-11 items-center justify-center rounded-lg px-2 text-sm font-semibold text-text-secondary hover:bg-surface-hover sm:h-9 sm:min-w-9"
            >
              {ar ? 'EN' : 'ع'}
            </button>
            <button
              onClick={toggleTheme}
              aria-label="Toggle theme"
              className="flex h-11 w-11 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover sm:h-9 sm:w-9"
            >
              {theme === 'light' ? <Moon size={18} /> : <Sun size={18} />}
            </button>
            <AccountMenu variant="topbar" />
          </div>
        </div>
      </header>

      <main className="mx-auto w-full max-w-[1080px] flex-1 px-4 pb-14 pt-4 sm:px-6">
        {profile.isPending ? (
          <div className="flex min-h-[40vh] items-center justify-center">
            <Loader2 className="h-6 w-6 animate-spin text-brand-600" aria-label={ar ? 'جارٍ التحميل' : 'Loading'} />
          </div>
        ) : notACreator ? (
          /* Not "no data" — a different answer, and worth saying out loud. Someone who holds an
             agency membership landed on the creator's side by following a link, and a blank page
             would leave them assuming the portal is broken. */
          <div
            data-testid="creator-not-a-creator"
            className="mx-auto mt-10 max-w-md rounded-2xl border border-border bg-surface p-7 text-center"
          >
            <h1 className="font-heading text-lg font-extrabold text-text-primary">
              {ar ? 'هذه المساحة مخصّصة لصنّاع المحتوى' : 'This space belongs to creators'}
            </h1>
            <p className="mt-2 text-sm text-text-secondary">
              {ar
                ? 'حسابك ليس مسجّلًا كصانع محتوى في هذه الوكالة. إن كنت من فريق الوكالة فأعمالك في قسم التعاونات.'
                : 'Your account is not registered as a creator with this agency. If you are on the agency team, your work is under Collaborations.'}
            </p>
            <a
              href="/influencers"
              className="mt-5 inline-block text-sm font-semibold text-brand-600 hover:underline"
            >
              {ar ? 'انتقل إلى قسم التعاونات' : 'Go to Collaborations'}
            </a>
          </div>
        ) : (
          <Outlet />
        )}
        <PortalFooter />
      </main>
    </div>
  )
}
