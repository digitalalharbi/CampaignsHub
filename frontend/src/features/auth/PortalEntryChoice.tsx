import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useLocation, useNavigate } from 'react-router-dom'
import { PORTAL_LABELS, switchMembership, type Membership, type PortalKey } from './memberships'
import { completeWorkspaceSwitch } from './workspaceSwitch'
import { Button } from '@/components/ui/Button'
import { useUi } from '@/stores/ui'

const name = (m: Membership, ar: boolean) => `${m.tenant.name ?? ''} · ${ar ? PORTAL_LABELS[m.portal].ar : PORTAL_LABELS[m.portal].en}`

/**
 * ENSURE-PORTAL-SESSION-001 — the question a link into another portal asks (Owner, #945).
 *
 * The switch is the server's own, on the person's click. It keeps the page the link named, and the other tabs of this
 * browser are told and go to that portal's front door. «Stay» goes back to where the session already is, moving nothing.
 */
export function PortalEntryChoice({ portal, current, targets }: { portal: PortalKey; current: Membership; targets: Membership[] }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const { pathname, search, hash } = useLocation()

  const move = useMutation({
    mutationFn: (target: Membership) => switchMembership(target.id).then(() => target),
    onSuccess: (target) => completeWorkspaceSwitch(queryClient, `${pathname}${search}${hash}`, target.landing_path),
    // The list on screen was wrong about something: read it again rather than leave it there.
    onError: () => void queryClient.invalidateQueries({ queryKey: ['memberships'] }),
  })

  const label = ar ? PORTAL_LABELS[portal].ar : PORTAL_LABELS[portal].en

  return (
    <div className="flex min-h-screen items-center justify-center bg-background px-5 py-10">
      <div data-testid="portal-entry-choice" className="w-full max-w-md rounded-2xl border border-border bg-surface p-7 text-center">
        <h1 className="font-heading text-xl font-extrabold text-text-primary">
          {ar ? `هذه الصفحة في بوابة «${label}»` : `This page is in the ${label} portal`}
        </h1>
        <p data-testid="portal-entry-current" className="mt-2 text-sm text-text-secondary">
          {ar ? 'أنت تعمل الآن في: ' : 'You are working in: '}
          <bdi className="font-semibold text-text-primary">{name(current, ar)}</bdi>
        </p>

        {move.isError && (
          <p role="alert" data-testid="portal-entry-error" className="mt-3 text-sm text-danger-600">
            {ar ? 'تعذّر التبديل، وما زلت في مساحة العمل نفسها.' : 'That switch did not go through. You are still in the same workspace.'}
          </p>
        )}

        <div className="mt-5 flex flex-col gap-2">
          {targets.map((target) => (
            <Button
              key={target.id}
              data-testid={`portal-entry-switch-${target.id}`}
              loading={move.isPending && move.variables?.id === target.id}
              disabled={move.isPending}
              onClick={() => move.mutate(target)}
            >
              <bdi>{ar ? `التبديل إلى ${name(target, ar)}` : `Switch to ${name(target, ar)}`}</bdi>
            </Button>
          ))}
          <Button
            variant="secondary"
            data-testid="portal-entry-stay"
            disabled={move.isPending}
            onClick={() => navigate(current.landing_path, { replace: true })}
          >
            <bdi>{ar ? `البقاء في ${current.tenant.name ?? ''}` : `Stay in ${current.tenant.name ?? ''}`}</bdi>
          </Button>
        </div>
      </div>
    </div>
  )
}
