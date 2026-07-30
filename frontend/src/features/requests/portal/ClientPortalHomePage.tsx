import { useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { fetchClientSpaces } from './clientSpace'
import { ClientDashboardPage } from './ClientDashboardPage'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

/**
 * `/portal` — where a client lands (ADR 0002, PORTAL-CLIENT-001).
 *
 * What belongs here depends on how many of the agency's clients this person is named on, and that is
 * not something the browser can assume:
 *
 *   one space  → their dashboard, addressed by slug so the URL says which brand they are looking at;
 *   several    → the picker, because a merged view gives them figures they cannot attribute;
 *   none yet   → the dashboard as it always was, which explains its own empty state.
 *
 * The redirect is `replace`, so Back returns to wherever they came from rather than bouncing them
 * through this decision again.
 */
export function ClientPortalHomePage() {
  const navigate = useNavigate()
  const ar = useUi((s) => s.locale) === 'ar'

  const spaces = useQuery({ queryKey: ['portal', 'spaces'], queryFn: fetchClientSpaces, retry: false })

  // A 401 is the login's business, not this page's — the dashboard below already handles it.
  const unauthenticated = spaces.isError && toApiError(spaces.error).status === 401

  useEffect(() => {
    if (!spaces.data || unauthenticated) return

    if (spaces.data.length === 1) {
      navigate(`/portal/clients/${encodeURIComponent(spaces.data[0].slug)}`, { replace: true })
    } else if (spaces.data.length > 1) {
      navigate('/portal/spaces', { replace: true })
    }
  }, [spaces.data, unauthenticated, navigate])

  if (spaces.isPending) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-background">
        <Loader2 className="h-6 w-6 animate-spin text-brand-600" aria-label={ar ? 'جارٍ التحميل' : 'Loading'} />
      </div>
    )
  }

  // No space of their own, or the probe failed: the dashboard is honest about both.
  return <ClientDashboardPage />
}
