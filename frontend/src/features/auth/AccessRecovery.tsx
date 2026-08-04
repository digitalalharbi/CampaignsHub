import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { ArrowRight, Home, LogOut, Repeat, UserPlus } from 'lucide-react'
import type { Membership } from './memberships'
import { clearStaleWorkspaceSelection, signOutCompletely } from './signOut'
import { Button } from '@/components/ui/Button'
import { useUi } from '@/stores/ui'

/**
 * ACCESS-EXIT-001 — the way out, on every screen that can refuse somebody.
 *
 * ## The problem this exists to end
 *
 * A refusal used to offer one button. For somebody holding a portal that was fine; for somebody
 * holding NONE it pointed at `/switch`, which showed «لا توجد مساحة عمل» and offered nothing at all.
 * Closing the tab and coming back landed on the same wall, because the session was still valid and
 * the router still sent them to the same place. The only escape was clearing site data by hand — a
 * thing no user knows to do and no product should require.
 *
 * The rule now: **no screen may refuse without also offering a way out.** Every state that can
 * dead-end — portal mismatch, forbidden, no workspace, suspended, expired session, a load that
 * failed — renders this block.
 *
 * ## Which actions, and why in this order
 *
 * 1. **The portal they actually hold**, named. Somebody with one membership does not want a menu;
 *    they want the place they were trying to reach.
 * 2. **Choose another workspace**, only when there is genuinely more than one. A switcher with one
 *    option is a step that teaches nothing.
 * 3. **Sign in with another account** — the common case for a person on a shared machine who simply
 *    used the wrong one. It signs out first: offering a login link that leaves the old session
 *    running is how people end up back on the same wall.
 * 4. **Home** — always valid, never authenticated, guaranteed to render.
 * 5. **Sign out** — the last resort that must always work, and the one that clears the browser so the
 *    next visit does not repeat the trap.
 *
 * Sign-out is last but never hidden: it is the action somebody reaches for when nothing else has
 * helped, and burying it in a menu is how a dead end survives a redesign.
 */
export function AccessRecovery({
  memberships,
  onboarding = false,
  loginPath = '/login',
}: {
  /** The portals this person holds. Empty is the case this component exists for. */
  memberships?: Membership[]
  /** Show the «finish setting up / ask for an invite» line — true when there is no workspace at all. */
  onboarding?: boolean
  /** Where signing in again should land. The client portal has its own door. */
  loginPath?: string
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [busy, setBusy] = useState(false)

  const held = memberships ?? []
  const only = held.length === 1 ? held[0] : null

  /*
   * Reaching this component means something stored led somewhere the account cannot go.
   *
   * The persisted project and client selection are this app's version of a saved route, and a
   * selection pointing at a workspace the person no longer holds is what made the trap survive a
   * browser restart: boot, restore, request data the account cannot see, land back on the wall.
   * Clearing it here means the NEXT visit starts clean even if the person does nothing else.
   */
  useEffect(() => {
    clearStaleWorkspaceSelection()
  }, [])

  const leave = async (destination: string) => {
    setBusy(true)
    await signOutCompletely(queryClient, destination)
  }

  return (
    <div className="mt-6 grid gap-2" data-testid="access-recovery">
      {only && (
        <Button
          data-testid="recovery-go-to-portal"
          className="w-full"
          onClick={() => navigate(only.landing_path, { replace: true })}
        >
          <ArrowRight size={16} /> {ar ? 'انتقل إلى بوابتي' : 'Go to my portal'}
        </Button>
      )}

      {held.length > 1 && (
        <Button
          data-testid="recovery-switch"
          className="w-full"
          onClick={() => navigate('/switch', { replace: true })}
        >
          <Repeat size={16} /> {ar ? 'اختر مساحة أخرى' : 'Choose another workspace'}
        </Button>
      )}

      {onboarding && (
        <Button
          data-testid="recovery-onboarding"
          variant="secondary"
          className="w-full"
          onClick={() => navigate('/register', { replace: true })}
        >
          <UserPlus size={16} /> {ar ? 'أكمل إعداد الحساب' : 'Finish setting up'}
        </Button>
      )}

      {/*
        Signing in as somebody else SIGNS OUT first.
        A plain link to /login while the old session is still alive sends the person straight back to
        the screen they are trying to escape — which is exactly the loop this component removes.
      */}
      <Button
        data-testid="recovery-switch-account"
        variant="secondary"
        className="w-full"
        loading={busy}
        onClick={() => void leave(loginPath)}
      >
        <UserPlus size={16} /> {ar ? 'تسجيل الدخول بحساب آخر' : 'Sign in with another account'}
      </Button>

      <div className="mt-1 flex flex-wrap items-center justify-center gap-4">
        <button
          type="button"
          data-testid="recovery-home"
          onClick={() => navigate('/', { replace: true })}
          className="inline-flex items-center gap-1.5 text-sm font-semibold text-text-secondary hover:text-text-primary"
        >
          <Home size={14} /> {ar ? 'الصفحة الرئيسية' : 'Home'}
        </button>

        <button
          type="button"
          data-testid="recovery-sign-out"
          disabled={busy}
          onClick={() => void leave(loginPath)}
          className="inline-flex items-center gap-1.5 text-sm font-semibold text-danger hover:underline disabled:opacity-60"
        >
          <LogOut size={14} /> {ar ? 'تسجيل الخروج' : 'Sign out'}
        </button>
      </div>

      {onboarding && (
        <p className="mt-1 text-center text-xs text-text-muted">
          {ar
            ? 'إن كان لديك زميل في المنشأة، اطلب منه دعوتك بالبريد الذي سجّلت به.'
            : 'If a colleague already has a workspace, ask them to invite the email you signed up with.'}
        </p>
      )}
    </div>
  )
}
