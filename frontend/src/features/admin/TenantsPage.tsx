import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Building2, Search, ShieldOff, ShieldCheck } from 'lucide-react'
import { fetchTenant, fetchTenants, setTenantStatus, type PlatformTenant } from './api'
import { Button } from '@/components/ui/Button'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { Modal } from '@/components/ui/Modal'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

/**
 * `/admin/tenants` — every workspace on the platform (ADMIN-001).
 *
 * The only surface in the product that lists tenants, and the only one that can suspend one. It
 * shows what running the business needs — who exists, on what plan, how large, active or not — and
 * deliberately not what they are doing.
 *
 * Detail opens in a drawer rather than its own page: the structure rule is a maximum of two levels,
 * and one tenant's access list is not worth a route of its own.
 *
 * Suspension asks for a reason before it will proceed, because it locks every person whose only
 * workspace is this one out of the product, and an audit entry with no reason explains nothing to
 * whoever reads it a year later.
 */

const num = (n: number) => n.toLocaleString('en-US')

export function TenantsPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const qc = useQueryClient()
  const [term, setTerm] = useState('')
  const [submitted, setSubmitted] = useState('')
  const [status, setStatus] = useState('')
  const [openId, setOpenId] = useState<string | null>(null)
  const [suspending, setSuspending] = useState<PlatformTenant | null>(null)
  const [reason, setReason] = useState('')

  const list = useQuery({
    queryKey: ['admin', 'tenants', submitted, status],
    queryFn: () => fetchTenants({ q: submitted || undefined, status: status || undefined }),
  })

  const change = useMutation({
    mutationFn: ({ id, next, why }: { id: string; next: 'active' | 'suspended'; why?: string }) =>
      setTenantStatus(id, next, why),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['admin'] })
      setSuspending(null)
      setReason('')
    },
  })

  const error = change.isError ? toApiError(change.error) : null

  return (
    <div className="w-full">
      <header className="mb-5">
        <h1 className="font-heading text-3xl font-extrabold tracking-tight text-text-primary">
          {ar ? 'المستأجرون' : 'Tenants'}
        </h1>
        <p className="mt-1 text-sm text-text-secondary">
          {ar
            ? 'كل مساحة عمل على المنصة: من هي، وحجمها، وحالتها. لا تظهر هنا بيانات عمل أي عميل.'
            : 'Every workspace on the platform: who they are, how large, and their status. No customer’s work appears here.'}
        </p>
      </header>

      <div className="mb-4 flex flex-wrap items-center gap-2">
        <form className="relative" onSubmit={(e) => { e.preventDefault(); setSubmitted(term.trim()) }}>
          <Search size={16} className="pointer-events-none absolute inset-y-0 my-auto ms-3 text-text-muted" aria-hidden />
          <input
            value={term}
            onChange={(e) => setTerm(e.target.value)}
            aria-label={ar ? 'ابحث في المستأجرين' : 'Search tenants'}
            placeholder={ar ? 'ابحث بالاسم أو المعرّف' : 'Search by name or slug'}
            className="h-10 w-64 rounded-lg border border-border bg-surface ps-9 pe-3 text-sm outline-none focus:border-brand-500"
          />
        </form>
        {['', 'active', 'suspended'].map((key) => (
          <button
            key={key || 'all'}
            type="button"
            onClick={() => setStatus(key)}
            aria-pressed={status === key}
            className={`rounded-lg px-3 py-1.5 text-sm font-semibold transition-colors ${
              status === key ? 'bg-brand-primary-soft text-brand-700' : 'text-text-secondary hover:bg-surface-hover'
            }`}
          >
            {key === '' ? (ar ? 'الكل' : 'All') : key === 'active' ? (ar ? 'نشط' : 'Active') : (ar ? 'موقوف' : 'Suspended')}
          </button>
        ))}
      </div>

      {error && (
        <p role="alert" className="mb-4 rounded-xl bg-[var(--negative-background)] px-4 py-3 text-sm text-danger">
          {error.message}
        </p>
      )}

      {list.isPending && <div className="grid gap-2">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-20" />)}</div>}

      {list.isError && (
        <ErrorState title={ar ? 'تعذّر تحميل المستأجرين.' : 'Tenants could not be loaded.'} onRetry={() => void list.refetch()} />
      )}

      {list.data && list.data.tenants.length === 0 && (
        <p className="rounded-2xl border border-dashed border-border px-4 py-12 text-center text-sm text-text-muted">
          {submitted || status
            ? (ar ? 'لا نتائج تطابق البحث.' : 'Nothing matches that search.')
            : (ar ? 'لا مستأجرين بعد.' : 'No tenants yet.')}
        </p>
      )}

      {list.data && list.data.tenants.length > 0 && (
        <ul data-testid="tenant-list" className="grid gap-2">
          {list.data.tenants.map((t) => (
            <li key={t.id} data-testid={`tenant-${t.slug}`}
              className={`flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-surface px-4 py-3 ${
                t.status === 'suspended' ? 'border-warning/40' : 'border-border'
              }`}>
              <button type="button" onClick={() => setOpenId(t.id)} className="min-w-0 flex-1 text-start">
                <p className="flex items-center gap-2 text-[14.5px] font-bold text-text-primary">
                  <Building2 size={15} className="shrink-0 text-text-muted" aria-hidden />
                  {t.name}
                  {t.status === 'suspended' && (
                    <span className="rounded-full bg-warning/15 px-2 py-0.5 text-[11px] font-semibold text-warning">
                      {ar ? 'موقوف' : 'Suspended'}
                    </span>
                  )}
                </p>
                <p className="mt-0.5 flex flex-wrap items-center gap-x-2 text-[12.5px] text-text-muted">
                  <span dir="ltr">{t.slug}</span>
                  <span>· {t.account_type ?? (ar ? 'نوع غير محدّد' : 'type unset')}</span>
                  <span>· {t.subscription_plan ?? (ar ? 'بلا خطة' : 'no plan')}</span>
                  <span className="tnum" dir="ltr">· {num(t.people)} {ar ? 'مستخدم' : 'people'}</span>
                  <span className="tnum" dir="ltr">· {num(t.client_workspaces)} {ar ? 'عميل' : 'clients'}</span>
                </p>
              </button>

              {t.status === 'active' ? (
                <Button size="sm" variant="secondary" onClick={() => setSuspending(t)}>
                  <ShieldOff size={14} /> {ar ? 'إيقاف' : 'Suspend'}
                </Button>
              ) : (
                <Button size="sm" variant="secondary" disabled={change.isPending}
                  onClick={() => change.mutate({ id: t.id, next: 'active' })}>
                  <ShieldCheck size={14} /> {ar ? 'إعادة التفعيل' : 'Reactivate'}
                </Button>
              )}
            </li>
          ))}
        </ul>
      )}

      {openId && <TenantDrawer id={openId} onClose={() => setOpenId(null)} ar={ar} />}

      {suspending && (
        <Modal open onClose={() => { setSuspending(null); setReason('') }}
          title={ar ? `إيقاف ${suspending.name}` : `Suspend ${suspending.name}`}>
          <div className="flex items-start gap-2.5 rounded-xl border border-warning/40 bg-warning/10 px-4 py-3 text-sm">
            <AlertTriangle size={17} className="mt-0.5 shrink-0 text-warning" aria-hidden />
            <span className="text-text-primary">
              {ar
                ? 'كل شخص لا يملك مساحة عمل أخرى سيفقد الدخول إلى المنصة فورًا.'
                : 'Everyone whose only workspace this is will lose access to the product immediately.'}
            </span>
          </div>

          <label className="mt-4 block text-sm font-semibold text-text-primary" htmlFor="suspend-reason">
            {ar ? 'السبب (مطلوب)' : 'Reason (required)'}
          </label>
          <textarea
            id="suspend-reason"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            rows={3}
            placeholder={ar ? 'يُحفظ في سجل التدقيق ويُقرأ لاحقًا.' : 'Recorded in the audit trail and read later.'}
            className="mt-1.5 w-full rounded-xl border border-border bg-surface px-3.5 py-2.5 text-sm outline-none focus:border-brand-500"
          />

          <div className="mt-4 flex justify-end gap-2">
            <Button variant="ghost" onClick={() => { setSuspending(null); setReason('') }}>
              {ar ? 'إلغاء' : 'Cancel'}
            </Button>
            <Button
              variant="danger"
              disabled={reason.trim() === '' || change.isPending}
              onClick={() => change.mutate({ id: suspending.id, next: 'suspended', why: reason.trim() })}
            >
              {ar ? 'إيقاف المستأجر' : 'Suspend tenant'}
            </Button>
          </div>
        </Modal>
      )}
    </div>
  )
}

/** One tenant's access, in a drawer — a maximum of two levels means this gets no route of its own. */
function TenantDrawer({ id, onClose, ar }: { id: string; onClose: () => void; ar: boolean }) {
  const detail = useQuery({ queryKey: ['admin', 'tenant', id], queryFn: () => fetchTenant(id) })

  return (
    <Modal open onClose={onClose} title={detail.data?.tenant.name ?? (ar ? 'مستأجر' : 'Tenant')}>
      {detail.isPending && <Skeleton className="h-40" />}
      {detail.isError && (
        <ErrorState title={ar ? 'تعذّر تحميل المستأجر.' : 'The tenant could not be loaded.'} onRetry={() => void detail.refetch()} />
      )}
      {detail.data && (
        <>
          <dl className="grid grid-cols-2 gap-3 text-sm">
            <Fact label={ar ? 'الحالة' : 'Status'} value={detail.data.tenant.status} />
            <Fact label={ar ? 'النوع' : 'Account type'} value={detail.data.tenant.account_type ?? '—'} />
            <Fact label={ar ? 'الخطة' : 'Plan'} value={detail.data.tenant.subscription_plan ?? (ar ? 'بلا خطة' : 'No plan')} />
            <Fact label={ar ? 'مساحات العملاء' : 'Client workspaces'} value={num(detail.data.client_workspaces)} />
          </dl>

          {detail.data.tenant.is_default_portal && (
            <p className="mt-3 rounded-xl border border-info/30 bg-info/10 px-3.5 py-2.5 text-[13px] text-text-primary">
              {ar
                ? 'هذا المستأجر يخدم بوابة الطلبات العامة — إيقافه يوقف استقبال الطلبات للجميع.'
                : 'This tenant serves public request intake — suspending it stops the form for everyone.'}
            </p>
          )}

          <h3 className="mt-5 text-[12.5px] font-semibold uppercase tracking-wide text-text-muted">
            {ar ? 'من يستطيع الدخول' : 'Who can get in'}
          </h3>
          {detail.data.people.length === 0 ? (
            <p className="mt-2 text-sm text-text-muted">
              {ar ? 'لا أحد. هذه المساحة لا يمكن الوصول إليها.' : 'Nobody. This workspace is unreachable.'}
            </p>
          ) : (
            <ul className="mt-2 grid gap-1.5">
              {detail.data.people.map((p) => (
                <li key={`${p.user_id}-${p.portal}`} className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-surface-secondary px-3 py-2 text-[13px]">
                  <span className="min-w-0">
                    <span className="font-semibold text-text-primary">{p.name ?? '—'}</span>
                    <span className="ms-2 text-text-muted" dir="ltr">{p.email}</span>
                  </span>
                  <span className="flex items-center gap-2 text-[12px] text-text-secondary">
                    <span className="rounded-full bg-surface px-2 py-0.5 font-semibold">{p.portal}</span>
                    <span>{p.role}</span>
                  </span>
                </li>
              ))}
            </ul>
          )}
        </>
      )}
    </Modal>
  )
}

function Fact({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-[11.5px] uppercase tracking-wide text-text-muted">{label}</dt>
      <dd className="font-semibold text-text-primary">{value}</dd>
    </div>
  )
}
