import { useEffect } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowRight, FileText, LogOut, Plus } from 'lucide-react'
import { listPortalRequests, portalLogout, type PortalRequestCard } from '../clientPortalApi'
import { PortalShell } from './PortalShell'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

function statusTone(s: string): string {
  if (['completed', 'delivered'].includes(s)) return 'bg-success/15 text-success'
  if (['rejected', 'cancelled'].includes(s)) return 'bg-surface-secondary text-text-muted'
  if (s === 'waiting_client' || s === 'information_requested') return 'bg-warning/15 text-warning'
  return 'bg-brand-primary-soft text-brand-700'
}

export function ClientRequestsPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const navigate = useNavigate()
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['client', 'requests'], queryFn: listPortalRequests, retry: false })

  useEffect(() => {
    if (q.isError && toApiError(q.error).status === 401) navigate('/client/login', { replace: true })
  }, [q.isError, q.error, navigate])

  const logout = useMutation({
    mutationFn: portalLogout,
    onSuccess: () => { qc.clear(); navigate('/client/login', { replace: true }) },
  })

  const rows = q.data?.requests ?? []

  return (
    <PortalShell
      title={ar ? 'طلباتي' : 'My requests'}
      action={<button onClick={() => logout.mutate()} className="flex h-9 items-center gap-1.5 rounded-lg px-2.5 text-sm font-semibold text-text-secondary hover:bg-surface-hover"><LogOut size={15} /> {ar ? 'خروج' : 'Sign out'}</button>}
    >
      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="font-heading text-2xl font-extrabold text-text-primary">{ar ? 'طلباتي' : 'My requests'}</h1>
          <p className="mt-1 text-sm text-text-secondary">{ar ? 'تابع حالة طلباتك وتواصل مع الفريق ومشاركة الملفات.' : 'Track your requests, message the team and share files.'}</p>
        </div>
        <Link to="/requests/new" className="flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700"><Plus size={16} /> {ar ? 'طلب جديد' : 'New request'}</Link>
      </div>

      {q.isLoading ? (
        <div className="grid gap-3 sm:grid-cols-2">{[0, 1].map((i) => <div key={i} className="h-28 animate-pulse rounded-2xl bg-surface-secondary" />)}</div>
      ) : rows.length === 0 ? (
        <div className="flex flex-col items-center gap-2 rounded-2xl border border-border bg-surface p-12 text-center text-text-muted"><FileText size={26} /><span className="text-sm">{ar ? 'لا توجد طلبات بعد.' : 'No requests yet.'}</span></div>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2">
          {rows.map((r) => <RequestCard key={r.reference} r={r} ar={ar} />)}
        </div>
      )}
    </PortalShell>
  )
}

function RequestCard({ r, ar }: { r: PortalRequestCard; ar: boolean }) {
  return (
    <Link to={`/client/requests/${r.reference}`} className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-5 hover:border-brand-400">
      <div className="flex items-start justify-between gap-2">
        <div>
          <div className="font-mono text-xs font-semibold text-brand-600" dir="ltr">{r.reference}</div>
          <div className="mt-0.5 font-bold text-text-primary">{ar ? r.type_ar : r.type}</div>
        </div>
        <span className={`whitespace-nowrap rounded-full px-2.5 py-1 text-[11px] font-semibold ${statusTone(r.status)}`}>{r.status_label}</span>
      </div>
      <div className="h-2 overflow-hidden rounded-full bg-surface-secondary"><div className="h-full rounded-full bg-brand-500 transition-all" style={{ width: `${r.progress}%` }} /></div>
      <div className="flex items-center justify-between text-[11px] text-text-muted">
        <span>{r.updated_at ? new Date(r.updated_at).toLocaleDateString('en-CA') : ''}</span>
        <span className="flex items-center gap-1 font-semibold text-brand-600">{ar ? 'التفاصيل' : 'Details'} <ArrowRight size={13} className="rtl:rotate-180" /></span>
      </div>
    </Link>
  )
}
