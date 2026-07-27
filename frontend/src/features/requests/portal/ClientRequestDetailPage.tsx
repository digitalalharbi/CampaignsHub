import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Bell, CheckCircle2, Download, Loader2, Paperclip, Send, Sparkles } from 'lucide-react'
import { getPortalRequest, portalFileUrl, portalReply, portalStartUpload, portalUploadFile } from '../clientPortalApi'
import { PortalShell } from './PortalShell'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

export function ClientRequestDetailPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { reference = '' } = useParams()
  const q = useQuery({ queryKey: ['client', 'request', reference], queryFn: () => getPortalRequest(reference), retry: false })

  const [message, setMessage] = useState('')
  const [uploadToken, setUploadToken] = useState<string | null>(null)
  const [attached, setAttached] = useState<string[]>([])
  const [uploading, setUploading] = useState(false)

  useEffect(() => {
    if (q.isError && toApiError(q.error).status === 401) navigate('/client/login', { replace: true })
  }, [q.isError, q.error, navigate])

  const reply = useMutation({
    mutationFn: () => portalReply(reference, message, uploadToken ?? undefined),
    onSuccess: () => { setMessage(''); setUploadToken(null); setAttached([]); qc.invalidateQueries({ queryKey: ['client', 'request', reference] }) },
  })

  const onFile = async (file: File) => {
    setUploading(true)
    try {
      let token = uploadToken
      if (!token) { token = (await portalStartUpload()).upload_token; setUploadToken(token) }
      const m = await portalUploadFile(token, file)
      setAttached((a) => [...a, m.original_name])
    } catch { /* surfaced by disabled state */ } finally { setUploading(false) }
  }

  if (q.isLoading) return <PortalShell title={ar ? 'الطلب' : 'Request'}><div className="h-64 animate-pulse rounded-2xl bg-surface-secondary" /></PortalShell>
  if (q.isError) return <PortalShell title={ar ? 'الطلب' : 'Request'}><div className="rounded-2xl border border-danger/30 bg-[var(--negative-background)] p-6 text-center text-sm text-danger">{ar ? 'تعذّر تحميل الطلب.' : 'Could not load the request.'}</div></PortalShell>
  const d = q.data!

  return (
    <PortalShell title={ar ? 'تفاصيل الطلب' : 'Request details'}>
      <Link to="/client" className="mb-4 inline-flex items-center gap-1.5 text-sm font-semibold text-text-secondary hover:text-text-primary"><ArrowLeft size={15} className="rtl:rotate-180" /> {ar ? 'طلباتي' : 'My requests'}</Link>

      <div className="rounded-2xl border border-border bg-surface p-5 sm:p-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div className="font-mono text-xs font-semibold text-brand-600" dir="ltr">{d.reference}</div>
            <h1 className="mt-0.5 font-heading text-xl font-extrabold text-text-primary">{ar ? d.type_ar : d.type}</h1>
          </div>
          <span className="rounded-full bg-brand-primary-soft px-3 py-1 text-xs font-semibold text-brand-700">{d.status_label}</span>
        </div>
        <div className="mt-4 h-2.5 overflow-hidden rounded-full bg-surface-secondary"><div className="h-full rounded-full bg-brand-500 transition-all" style={{ width: `${d.progress}%` }} /></div>
        <div className="mt-1 text-[11px] text-text-muted">{ar ? 'الجاهزية' : 'Progress'}: {d.progress}%</div>

        {d.result?.converted && (
          <div className="mt-4 rounded-xl border border-success/30 bg-success/5 p-4">
            <div className="flex items-center gap-1.5 text-sm font-bold text-success"><Sparkles size={15} /> {ar ? 'تم تحويل طلبك إلى تنفيذ' : 'Your request is now in delivery'}</div>
            <div className="mt-1 text-sm text-text-secondary">
              {d.result.project_name && <span>{ar ? 'المشروع' : 'Project'}: <b className="text-text-primary">{d.result.project_name}</b>. </span>}
              {d.result.campaign_name && <span>{ar ? 'الحملة' : 'Campaign'}: <b className="text-text-primary">{d.result.campaign_name}</b> ({d.result.campaign_status}).</span>}
            </div>
          </div>
        )}
      </div>

      <div className="mt-4 grid gap-4 lg:grid-cols-3">
        {/* Timeline */}
        <section className="lg:col-span-2 rounded-2xl border border-border bg-surface p-5">
          <h2 className="mb-3 text-sm font-bold text-text-primary">{ar ? 'المسار' : 'Timeline'}</h2>
          <ol className="relative ms-2 space-y-3 border-s border-border ps-4">
            {d.timeline.length === 0 && <li className="text-sm text-text-muted">—</li>}
            {d.timeline.map((t, i) => (
              <li key={i} className="relative">
                <span className="absolute -start-[22px] top-1 h-2 w-2 rounded-full bg-brand-500 ring-4 ring-surface" />
                <div className="text-sm text-text-primary">{t.message ?? t.type}</div>
                <div className="text-[11px] text-text-muted">{t.at ? new Date(t.at).toLocaleString('en-CA') : ''}</div>
              </li>
            ))}
          </ol>

          {/* Messages */}
          <h2 className="mb-3 mt-6 text-sm font-bold text-text-primary">{ar ? 'المحادثة' : 'Messages'}</h2>
          <div className="space-y-2">
            {d.comments.length === 0 && <p className="text-sm text-text-muted">{ar ? 'لا توجد رسائل بعد.' : 'No messages yet.'}</p>}
            {d.comments.map((c, i) => (
              <div key={i} className={`rounded-xl border border-border px-3 py-2 text-sm ${c.author === 'Client' ? 'bg-brand-primary-soft/40' : 'bg-surface-secondary'}`}>
                <div className="text-[11px] font-semibold text-text-secondary">{c.author === 'Client' ? (ar ? 'أنت' : 'You') : (ar ? 'الفريق' : 'Team')} · {c.at ? new Date(c.at).toLocaleString('en-CA') : ''}</div>
                <div className="text-text-primary">{c.body}</div>
              </div>
            ))}
          </div>

          {/* Reply + upload */}
          <form className="mt-3 grid gap-2" onSubmit={(e) => { e.preventDefault(); if (message.trim().length >= 2 && !uploading) reply.mutate() }}>
            <textarea value={message} onChange={(e) => setMessage(e.target.value)} rows={3} maxLength={2000}
              placeholder={ar ? 'اكتب رسالتك للفريق…' : 'Write a message to the team…'} aria-label={ar ? 'رسالة' : 'Message'}
              className="w-full rounded-xl border border-border bg-surface px-3.5 py-2.5 text-sm outline-none focus:border-brand-500" />
            {attached.length > 0 && <div className="text-[11px] text-text-secondary">{ar ? 'مرفقات' : 'Attached'}: {attached.join('، ')}</div>}
            <div className="flex items-center gap-2">
              <label className="flex cursor-pointer items-center gap-1.5 rounded-lg border border-border px-3 py-2 text-xs font-semibold text-text-secondary hover:text-text-primary">
                {uploading ? <Loader2 size={14} className="animate-spin" /> : <Paperclip size={14} />} {ar ? 'إرفاق ملف' : 'Attach'}
                <input type="file" className="hidden" onChange={(e) => { const f = e.target.files?.[0]; if (f) onFile(f); e.target.value = '' }} />
              </label>
              <button type="submit" disabled={message.trim().length < 2 || uploading || reply.isPending} className="ms-auto flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60">
                {reply.isPending ? <Loader2 size={15} className="animate-spin" /> : <Send size={15} />} {ar ? 'إرسال' : 'Send'}
              </button>
            </div>
          </form>
        </section>

        {/* Sidebar: files + notifications */}
        <aside className="grid gap-4">
          <section className="rounded-2xl border border-border bg-surface p-5">
            <h2 className="mb-3 text-sm font-bold text-text-primary">{ar ? 'الملفات' : 'Files'}</h2>
            {d.files.length === 0 ? <p className="text-sm text-text-muted">{ar ? 'لا توجد ملفات.' : 'No files.'}</p> : (
              <ul className="space-y-1.5">
                {d.files.map((f) => (
                  <li key={f.id}>
                    <a href={portalFileUrl(d.reference, f.id)} className="flex items-center justify-between gap-2 rounded-lg border border-border px-3 py-2 text-sm hover:border-brand-400">
                      <span className="truncate text-text-primary">{f.name}</span>
                      <Download size={15} className="shrink-0 text-brand-600" />
                    </a>
                  </li>
                ))}
              </ul>
            )}
          </section>

          <section className="rounded-2xl border border-border bg-surface p-5">
            <h2 className="mb-3 flex items-center gap-1.5 text-sm font-bold text-text-primary"><Bell size={15} /> {ar ? 'الإشعارات' : 'Notifications'}</h2>
            {d.notifications.length === 0 ? <p className="text-sm text-text-muted">—</p> : (
              <ul className="space-y-1.5 text-xs">
                {d.notifications.map((n, i) => (
                  <li key={i} className="flex items-center justify-between gap-2 rounded-lg bg-surface-secondary px-2.5 py-1.5">
                    <span className="text-text-secondary">{n.event} · {n.channel}</span>
                    <span className={n.status === 'sent' ? 'font-semibold text-success' : 'text-text-muted'}>
                      {n.status === 'awaiting_provider_credentials' ? (ar ? 'بانتظار المزوّد' : 'awaiting provider') : n.status}
                    </span>
                  </li>
                ))}
              </ul>
            )}
            <p className="mt-2 flex items-center gap-1 text-[11px] text-text-muted"><CheckCircle2 size={12} /> {ar ? 'تصلك التحديثات عبر البريد والواتساب عند تفعيل المزوّد.' : 'You’ll get email + WhatsApp updates once a provider is enabled.'}</p>
          </section>
        </aside>
      </div>
    </PortalShell>
  )
}
