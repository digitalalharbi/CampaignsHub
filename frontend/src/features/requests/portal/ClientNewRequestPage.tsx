import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Loader2, Send } from 'lucide-react'
import { createPortalRequest } from '../clientPortalApi'
import { getRequestMeta } from '../api'
import { PortalShell } from './PortalShell'
import { usePortalGuard } from './usePortalGuard'
import { fetchPortalOverview, fetchPortalVisibility } from './portalOverviewApi'
import { useClientSpacePath, useClientSpaceSlug } from './clientSpace'
import { useUi } from '@/stores/ui'

/**
 * CP-REQ-ONE-001 — a client opens a request from inside their portal.
 *
 * Short on purpose: what they need, which project it is about, how urgent. The space comes from the URL
 * and the server resolves it against what this person may reach, so nothing here chooses a client. The
 * form is offered only when the agency has switched `create_requests` on for the space; the server
 * refuses otherwise, so this is a courtesy and not the lock.
 */
export function ClientNewRequestPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const navigate = useNavigate()
  const spaceTo = useClientSpacePath()
  const slug = useClientSpaceSlug()
  const qc = useQueryClient()

  const meta = useQuery({ queryKey: ['requests', 'meta'], queryFn: getRequestMeta, staleTime: 300_000 })
  const spaces = useQuery({ queryKey: ['portal', 'visibility'], queryFn: fetchPortalVisibility, retry: false, staleTime: 60_000 })
  const overview = useQuery({ queryKey: ['portal', 'overview', 'projects'], queryFn: () => fetchPortalOverview(), retry: false })
  usePortalGuard(spaces.isError, spaces.error)

  const space = (spaces.data ?? []).find((s) => s.slug === slug) ?? ((spaces.data ?? []).length === 1 ? spaces.data?.[0] : undefined)
  const mayAsk = space === undefined ? true : space.capabilities.create_requests !== false

  const [type, setType] = useState('')
  const [projectId, setProjectId] = useState('')
  const [priority, setPriority] = useState<'low' | 'medium' | 'high'>('medium')
  const [message, setMessage] = useState('')

  const create = useMutation({
    mutationFn: () => createPortalRequest({
      type,
      message: message.trim(),
      priority,
      ...(projectId === '' ? {} : { project_id: projectId }),
    }),
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: ['client', 'requests'] })
      navigate(spaceTo(`/requests/${r.reference}`))
    },
  })

  const projects = overview.data?.projects ?? []
  const ready = type !== '' && message.trim().length >= 5 && !create.isPending

  const field = 'w-full rounded-xl border border-border bg-surface px-3 py-2.5 text-sm text-text-primary'

  return (
    <PortalShell title={ar ? 'طلب جديد' : 'New request'} nav showLogout>
      <Link to={spaceTo('/requests')} className="mb-4 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600">
        <ArrowLeft size={14} className="rtl:rotate-180" /> {ar ? 'طلباتي' : 'My requests'}
      </Link>
      <h1 className="font-heading text-2xl font-extrabold text-text-primary">{ar ? 'طلب جديد' : 'New request'}</h1>
      <p className="mt-1 text-sm text-text-secondary">
        {ar ? 'اكتب ما تحتاجه وسيصل مباشرة إلى فريقك.' : 'Tell us what you need and it goes straight to your team.'}
      </p>

      {!mayAsk ? (
        <p data-testid="requests-disabled" className="mt-6 rounded-2xl border border-border bg-surface p-6 text-sm text-text-secondary">
          {ar ? 'لم تفعّل وكالتك الطلبات الجديدة هنا. تواصل معهم مباشرة.' : 'Your agency has not turned on new requests here. Please contact them directly.'}
        </p>
      ) : (
        <form
          className="mt-6 grid max-w-xl gap-4"
          onSubmit={(e) => { e.preventDefault(); if (ready) create.mutate() }}
        >
          <label className="grid gap-1.5 text-sm font-semibold text-text-primary">
            {ar ? 'نوع الطلب' : 'What is it about'}
            <select data-testid="new-request-type" value={type} onChange={(e) => setType(e.target.value)} className={field}>
              <option value="">{ar ? 'اختر…' : 'Choose…'}</option>
              {(meta.data?.types ?? []).map((t) => <option key={t.key} value={t.key}>{ar ? t.name_ar : t.name_en}</option>)}
            </select>
          </label>

          {projects.length > 0 && (
            <label className="grid gap-1.5 text-sm font-semibold text-text-primary">
              {ar ? 'المشروع (اختياري)' : 'Project (optional)'}
              <select data-testid="new-request-project" value={projectId} onChange={(e) => setProjectId(e.target.value)} className={field}>
                <option value="">{ar ? 'بدون تحديد' : 'Not specific'}</option>
                {projects.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
              </select>
            </label>
          )}

          <label className="grid gap-1.5 text-sm font-semibold text-text-primary">
            {ar ? 'الأولوية' : 'Priority'}
            <select data-testid="new-request-priority" value={priority} onChange={(e) => setPriority(e.target.value as typeof priority)} className={field}>
              <option value="low">{ar ? 'منخفضة' : 'Low'}</option>
              <option value="medium">{ar ? 'متوسطة' : 'Normal'}</option>
              <option value="high">{ar ? 'عالية' : 'High'}</option>
            </select>
          </label>

          <label className="grid gap-1.5 text-sm font-semibold text-text-primary">
            {ar ? 'التفاصيل' : 'Details'}
            <textarea
              data-testid="new-request-message"
              rows={5}
              value={message}
              onChange={(e) => setMessage(e.target.value)}
              className={field}
            />
          </label>

          {create.isError && (
            <p role="alert" className="text-sm text-danger">
              {ar ? 'تعذّر إرسال الطلب. راجع البيانات وحاول مرة أخرى.' : 'The request could not be sent. Check the details and try again.'}
            </p>
          )}

          <button
            type="submit"
            data-testid="new-request-submit"
            disabled={!ready}
            className="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50"
          >
            {create.isPending ? <Loader2 size={15} className="animate-spin" /> : <Send size={15} className="rtl:-scale-x-100" />}
            {ar ? 'إرسال الطلب' : 'Send request'}
          </button>
        </form>
      )}
    </PortalShell>
  )
}
