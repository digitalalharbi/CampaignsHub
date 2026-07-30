import { useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight, Building2, LayoutDashboard, Loader2, Megaphone, Sparkles, Users } from 'lucide-react'
import { PORTAL_LABELS, fetchMemberships, switchMembership, type Membership, type PortalKey } from './memberships'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { Button } from '@/components/ui/Button'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

/**
 * `/switch` — the portal and workspace switcher (ADR 0002).
 *
 * Shown when a user belongs to more than one portal or workspace, which the previous single-tenant
 * model could not represent at all. The list comes from the server and contains only the caller's own
 * memberships, so this page cannot be used to discover anyone else's workspaces.
 *
 * Choosing one asks the server to switch; the server refuses anything the caller does not hold, and
 * the destination it returns is the one we navigate to — the browser never decides where a portal
 * lives.
 */

const PORTAL_ICON: Record<PortalKey, typeof LayoutDashboard> = {
  app: LayoutDashboard,
  agency: Users,
  influencers: Sparkles,
  portal: Building2,
}

export function WorkspaceSwitcherPage() {
  const navigate = useNavigate()
  const { locale } = useUi()
  const ar = locale === 'ar'
  const Arrow = ar ? ArrowLeft : ArrowRight

  const state = useQuery({ queryKey: ['memberships'], queryFn: () => fetchMemberships() })

  const pick = useMutation({
    mutationFn: switchMembership,
    onSuccess: (result) => navigate(result.destination, { replace: true }),
  })

  // Nothing to choose between: send them straight through rather than showing a list of one.
  useEffect(() => {
    if (state.data && state.data.memberships.length === 1) {
      navigate(state.data.memberships[0].landing_path, { replace: true })
    }
  }, [state.data, navigate])

  const error = pick.isError ? toApiError(pick.error) : null

  return (
    <div className="min-h-screen bg-background px-5 py-10">
      <div className="mx-auto w-full max-w-2xl">
        <div className="flex items-center gap-2.5">
          <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 text-white">
            <Megaphone size={19} />
          </span>
          <span className="font-heading text-lg font-extrabold tracking-tight text-text-primary">CampaignsHub</span>
        </div>

        <h1 className="mt-6 font-heading text-[26px] font-extrabold leading-tight text-text-primary">
          {ar ? 'اختر مساحة العمل' : 'Choose a workspace'}
        </h1>
        <p className="mt-1.5 text-[14.5px] text-text-secondary">
          {ar
            ? 'أنت عضو في أكثر من مساحة. اختر التي تريد العمل فيها الآن — يمكنك التبديل في أي وقت.'
            : 'You belong to more than one space. Pick the one to work in now — you can switch at any time.'}
        </p>

        {state.isLoading && (
          <div className="mt-6 grid gap-3">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-20" />)}</div>
        )}

        {state.isError && (
          <div role="alert" className="mt-6 rounded-2xl border border-danger/30 bg-[var(--negative-background)] p-5">
            <p className="text-sm font-semibold text-danger">
              {ar ? 'تعذّر تحميل مساحات العمل.' : 'Your workspaces could not be loaded.'}
            </p>
            <Button variant="secondary" className="mt-3" onClick={() => void state.refetch()}>
              {ar ? 'إعادة المحاولة' : 'Retry'}
            </Button>
          </div>
        )}

        {error && (
          <p role="alert" className="mt-4 rounded-xl bg-[var(--negative-background)] px-4 py-3 text-sm text-danger">
            {error.message}
          </p>
        )}

        {state.data && state.data.memberships.length === 0 && (
          <div className="mt-6">
            <EmptyState
              title={ar ? 'لا توجد مساحة عمل بعد' : 'No workspace yet'}
              description={ar
                ? 'حسابك ليس عضوًا في أي مساحة. أكمل الإعداد أو اطلب دعوة من مسؤول المساحة.'
                : 'Your account is not a member of any space. Finish setting up, or ask a workspace admin to invite you.'}
            />
          </div>
        )}

        {state.data && state.data.memberships.length > 1 && (
          <ul data-testid="workspace-switcher" className="mt-6 grid gap-3">
            {state.data.memberships.map((m: Membership) => {
              const Icon = PORTAL_ICON[m.portal] ?? LayoutDashboard
              const label = PORTAL_LABELS[m.portal]
              return (
                <li key={m.id}>
                  <button
                    type="button"
                    data-testid={`switch-to-${m.portal}`}
                    disabled={pick.isPending}
                    onClick={() => pick.mutate(m.id)}
                    className={`flex w-full items-center gap-3 rounded-2xl border p-4 text-start transition-colors disabled:opacity-60 ${
                      m.is_active ? 'border-brand-500 bg-brand-primary-soft' : 'border-border bg-surface hover:border-brand-300 hover:bg-surface-hover'
                    }`}
                  >
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-primary-soft text-brand-600">
                      <Icon size={19} />
                    </span>
                    <span className="min-w-0 flex-1">
                      <span className="block text-[15px] font-bold text-text-primary">
                        {m.client_workspace?.name ?? m.tenant.name}
                      </span>
                      <span className="mt-0.5 block text-[12.5px] text-text-muted">
                        {ar ? label.ar : label.en} · {m.role}
                      </span>
                    </span>
                    {pick.isPending ? <Loader2 size={16} className="animate-spin text-text-muted" /> : <Arrow size={16} className="shrink-0 text-text-muted" />}
                  </button>
                </li>
              )
            })}
          </ul>
        )}
      </div>
    </div>
  )
}
