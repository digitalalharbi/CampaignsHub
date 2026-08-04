import { useEffect } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight, Building2 } from 'lucide-react'
import { fetchClientSpaces } from './clientSpace'
import { usePortalGuard } from './usePortalGuard'
import { PortalShell } from './PortalShell'
import { useUi } from '@/stores/ui'
import { AccessRecovery } from '@/features/auth/AccessRecovery'

/**
 * `/portal` — which of the agency's clients am I looking at? (PORTAL-CLIENT-001)
 *
 * Only shown to someone named on more than one client. One space is not a choice, so they are sent
 * straight into it: making a person confirm the only option they have is a step that teaches them
 * nothing.
 *
 * The list comes from the server and contains only the caller's own spaces, so this page cannot be
 * used to discover which clients an agency has.
 */
export function ClientSpacePickerPage() {
  const navigate = useNavigate()
  const ar = useUi((s) => s.locale) === 'ar'
  const Arrow = ar ? ArrowLeft : ArrowRight

  const spaces = useQuery({ queryKey: ['portal', 'spaces'], queryFn: fetchClientSpaces, retry: false })
  usePortalGuard(spaces.isError, spaces.error)

  const only = spaces.data?.length === 1 ? spaces.data[0] : null
  useEffect(() => {
    if (only) navigate(`/portal/clients/${encodeURIComponent(only.slug)}`, { replace: true })
  }, [only, navigate])

  return (
    <PortalShell title={ar ? 'مساحات العملاء' : 'Client spaces'} showLogout>
      <div className="mx-auto w-full max-w-4xl px-4 py-8 sm:px-6">
        <h1 className="font-heading text-2xl font-extrabold text-text-primary">
          {ar ? 'اختر المساحة' : 'Choose a space'}
        </h1>
        <p className="mt-1.5 text-sm text-text-secondary">
          {ar
            ? 'أنت مسجّل لدى أكثر من عميل. كل مساحة مستقلة تمامًا — طلباتها وفواتيرها ورسائلها لا تختلط بغيرها.'
            : 'You are named on more than one client. Each space is fully separate — its requests, invoices and messages never mix with another’s.'}
        </p>

        {spaces.isPending && (
          <div className="mt-6 grid gap-3">
            {[0, 1].map((i) => <div key={i} className="h-20 animate-pulse rounded-2xl bg-surface-secondary" />)}
          </div>
        )}

        {spaces.data?.length === 0 && (
          <div data-testid="no-client-space">
            <p className="mt-6 rounded-2xl border border-dashed border-border px-4 py-10 text-center text-sm text-text-muted">
              {ar
                ? 'لا توجد مساحة عميل مرتبطة بحسابك بعد.'
                : 'No client space is linked to your account yet.'}
            </p>
            {/*
              ACCESS-EXIT-001 — a client with no space needs an exit too, and it is NOT the operator
              login: sending them there would offer a form their account cannot use.
            */}
            <AccessRecovery loginPath="/login" />
          </div>
        )}

        {spaces.data && spaces.data.length > 1 && (
          <ul data-testid="client-space-picker" className="mt-6 grid gap-3">
            {spaces.data.map((space) => (
              <li key={space.id}>
                <Link
                  to={`/portal/clients/${encodeURIComponent(space.slug)}`}
                  className="flex items-center gap-3 rounded-2xl border border-border bg-surface p-4 transition-colors hover:border-brand-400 hover:bg-surface-hover"
                >
                  <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-primary-soft text-brand-600">
                    <Building2 size={19} />
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="block text-[15px] font-bold text-text-primary">{space.name}</span>
                    <span className="mt-0.5 block truncate text-[12.5px] text-text-muted" dir="ltr">{space.slug}</span>
                  </span>
                  <Arrow size={16} className="shrink-0 text-text-muted" />
                </Link>
              </li>
            ))}
          </ul>
        )}
      </div>
    </PortalShell>
  )
}
