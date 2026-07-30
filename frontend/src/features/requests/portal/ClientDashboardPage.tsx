import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowRight, FileText, MessagesSquare, Receipt, ScrollText } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { listPortalRequests, type PortalRequestCard } from '../clientPortalApi'
import { listPortalInvoices, listPortalQuotes, listPortalThreads, formatDate } from './portalAccountApi'
import { PortalShell } from './PortalShell'
import { usePortalGuard } from './usePortalGuard'
import { useUi } from '@/stores/ui'
import { useClientSpacePath } from './clientSpace'

const COPY = {
  ar: {
    title: 'مرحباً بك', subtitle: 'نظرة سريعة على طلباتك وفواتيرك ورسائلك.',
    open_requests: 'طلبات مفتوحة', pending_quotes: 'عروض بانتظار ردّك',
    unpaid_invoices: 'فواتير غير مدفوعة', unread_messages: 'رسائل غير مقروءة',
    recent: 'أحدث الطلبات', none: 'لا توجد طلبات بعد.', view_all: 'عرض الكل', details: 'التفاصيل',
  },
  en: {
    title: 'Welcome', subtitle: 'A quick look at your requests, invoices and messages.',
    open_requests: 'Open requests', pending_quotes: 'Quotes awaiting you',
    unpaid_invoices: 'Unpaid invoices', unread_messages: 'Unread messages',
    recent: 'Recent requests', none: 'No requests yet.', view_all: 'View all', details: 'Details',
  },
}

const OPEN_REQUEST_CLOSED = ['completed', 'delivered', 'rejected', 'cancelled']

export function ClientDashboardPage() {
  const spaceTo = useClientSpacePath()
  const ar = useUi((s) => s.locale) === 'ar'
  const t = ar ? COPY.ar : COPY.en

  const requests = useQuery({ queryKey: ['client', 'requests'], queryFn: listPortalRequests, retry: false })
  const quotes = useQuery({ queryKey: ['client', 'quotes'], queryFn: listPortalQuotes, retry: false })
  const invoices = useQuery({ queryKey: ['client', 'invoices'], queryFn: listPortalInvoices, retry: false })
  const threads = useQuery({ queryKey: ['client', 'threads'], queryFn: listPortalThreads, retry: false })

  // A 401 on any query means the session expired — bounce to login.
  usePortalGuard(requests.isError, requests.error)

  const requestRows = requests.data?.requests ?? []
  const openRequests = requestRows.filter((r) => !OPEN_REQUEST_CLOSED.includes(r.status)).length
  const pendingQuotes = (quotes.data ?? []).filter((q) => ['draft', 'sent'].includes(q.status)).length
  const unpaidInvoices = (invoices.data ?? []).filter((i) => ['unpaid', 'partially_paid'].includes(i.payment_status)).length
  const unreadMessages = (threads.data ?? []).reduce((sum, th) => sum + (th.unread ?? 0), 0)

  const recent = requestRows.slice(0, 5)
  const loading = requests.isLoading || quotes.isLoading || invoices.isLoading || threads.isLoading

  return (
    <PortalShell title={ar ? 'الرئيسية' : 'Dashboard'} nav showLogout>
      <div className="mb-5">
        <h1 className="font-heading text-2xl font-extrabold text-text-primary">{t.title}</h1>
        <p className="mt-1 text-sm text-text-secondary">{t.subtitle}</p>
      </div>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <StatCard to={spaceTo('/requests')} icon={FileText} label={t.open_requests} value={openRequests} loading={loading} />
        <StatCard to={spaceTo('/quotes')} icon={ScrollText} label={t.pending_quotes} value={pendingQuotes} loading={loading} tone={pendingQuotes > 0 ? 'warning' : undefined} />
        <StatCard to={spaceTo('/invoices')} icon={Receipt} label={t.unpaid_invoices} value={unpaidInvoices} loading={loading} tone={unpaidInvoices > 0 ? 'warning' : undefined} />
        <StatCard to={spaceTo('/messages')} icon={MessagesSquare} label={t.unread_messages} value={unreadMessages} loading={loading} tone={unreadMessages > 0 ? 'brand' : undefined} />
      </div>

      <section className="mt-6 rounded-2xl border border-border bg-surface p-5">
        <div className="mb-3 flex items-center justify-between">
          <h2 className="text-sm font-bold text-text-primary">{t.recent}</h2>
          <Link to={spaceTo('/requests')} className="flex items-center gap-1 text-xs font-semibold text-brand-600 hover:text-brand-700">{t.view_all} <ArrowRight size={13} className="rtl:rotate-180" /></Link>
        </div>
        {requests.isLoading ? (
          <div className="space-y-2">{[0, 1, 2].map((i) => <div key={i} className="h-14 animate-pulse rounded-xl bg-surface-secondary" />)}</div>
        ) : recent.length === 0 ? (
          <p className="py-6 text-center text-sm text-text-muted">{t.none}</p>
        ) : (
          <ul className="divide-y divide-border">
            {recent.map((r) => <RecentRow key={r.reference} r={r} ar={ar} details={t.details} />)}
          </ul>
        )}
      </section>
    </PortalShell>
  )
}

function StatCard({ to, icon: Icon, label, value, loading, tone }: {
  to: string; icon: LucideIcon; label: string; value: number; loading: boolean
  tone?: 'warning' | 'brand'
}) {
  const toneClass = tone === 'warning' ? 'text-warning' : tone === 'brand' ? 'text-brand-600' : 'text-text-primary'
  return (
    <Link to={to} className="flex flex-col gap-2 rounded-2xl border border-border bg-surface p-4 hover:border-brand-400">
      <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-surface-secondary text-text-secondary"><Icon size={16} /></span>
      {loading ? <div className="h-7 w-10 animate-pulse rounded bg-surface-secondary" /> : <span className={`tnum text-2xl font-extrabold ${toneClass}`}>{value}</span>}
      <span className="text-xs text-text-secondary">{label}</span>
    </Link>
  )
}

function RecentRow({ r, ar, details }: { r: PortalRequestCard; ar: boolean; details: string }) {
  const spaceTo = useClientSpacePath()
  return (
    <li>
      <Link to={spaceTo(`/requests/${r.reference}`)} className="flex items-center justify-between gap-3 py-3 hover:opacity-80">
        <div className="min-w-0">
          <div className="font-mono text-[11px] font-semibold text-brand-600" dir="ltr">{r.reference}</div>
          <div className="truncate text-sm font-semibold text-text-primary">{ar ? r.type_ar : r.type}</div>
        </div>
        <div className="flex shrink-0 items-center gap-3">
          <span className="whitespace-nowrap rounded-full bg-surface-secondary px-2.5 py-1 text-[11px] font-semibold text-text-secondary">{r.status_label}</span>
          <span className="hidden text-[11px] text-text-muted sm:inline">{formatDate(r.updated_at)}</span>
          <ArrowRight size={14} className="text-text-muted rtl:rotate-180" aria-label={details} />
        </div>
      </Link>
    </li>
  )
}
