import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, CheckCircle2, Download, FileText, Megaphone, Search } from 'lucide-react'
import { replyToRequest, trackFileUrl, trackRequest } from './api'
import { Button } from '@/components/ui/Button'
import { TextareaField } from '@/components/ui/form'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'
import { getPublishedPage } from '@/features/settings/publicPagesApi'

/**
 * SITE-CMS-002: the request-tracking portal reads its own published document
 * (System Settings → الواجهة الرئيسية والبوابات → بوابة متابعة الطلبات), so its copy is editable
 * without a code change. A missing/failed fetch leaves the shipped copy in place.
 */
function useTrackingCopy() {
  const cms = useQuery({ queryKey: ['public-page', 'portal_tracking'], queryFn: () => getPublishedPage('portal_tracking'), retry: false, staleTime: 60_000 })
  const heroSection = cms.data?.content?.hero as Record<string, unknown> | undefined
  return (field: string, fallback: string): string => {
    const v = heroSection?.[field]
    return typeof v === 'string' && v.trim() !== '' ? v : fallback
  }
}

/**
 * Public request tracking — client-safe view keyed by a secure token. Shows only what the API returns
 * (status, client-visible timeline, comments, files); it can never see internal notes / SLA / tenant.
 */
export function RequestTrackPage() {
  const { locale } = useUi()
  const ar = locale === 'ar'
  const dir = ar ? 'rtl' : 'ltr'
  const [params, setParams] = useSearchParams()
  const token = params.get('token') ?? ''

  return (
    <div dir={dir} className="min-h-screen bg-background text-text-primary">
      <header className="border-b border-border bg-surface">
        <div className="mx-auto flex h-16 max-w-2xl items-center px-4 sm:px-6">
          <Link to="/" className="flex items-center gap-2.5">
            <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 text-white"><Megaphone size={18} /></span>
            <span className="font-heading text-lg font-extrabold">CampaignsHub</span>
          </Link>
        </div>
      </header>
      <main className="mx-auto max-w-2xl px-4 py-8 sm:px-6">
        {token ? <TrackView token={token} ar={ar} /> : <TokenEntry ar={ar} onSubmit={(t) => setParams({ token: t })} />}
        <Link to="/" className="mt-8 inline-flex items-center gap-1.5 text-sm font-semibold text-text-secondary hover:text-text-primary"><ArrowLeft size={15} className="rtl:rotate-180" /> {ar ? 'العودة للصفحة الرئيسية' : 'Back to home'}</Link>
      </main>
    </div>
  )
}

function TokenEntry({ ar, onSubmit }: { ar: boolean; onSubmit: (t: string) => void }) {
  const [value, setValue] = useState('')
  const txt = useTrackingCopy()
  return (
    <div className="rounded-2xl border border-border bg-surface p-6 text-center">
      <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-primary-soft text-brand-600"><Search size={26} /></div>
      <h1 className="mt-4 font-heading text-2xl font-extrabold">{txt('title', ar ? 'تتبع الطلب' : 'Track your request')}</h1>
      <p className="mt-1.5 text-sm text-text-secondary">{txt('desc', ar ? 'أدخل رمز التتبع الآمن الخاص بطلبك.' : 'Enter your secure tracking token.')}</p>
      <form className="mt-5 flex gap-2" onSubmit={(e) => { e.preventDefault(); if (value.trim()) onSubmit(value.trim()) }}>
        <input value={value} onChange={(e) => setValue(e.target.value)} dir="ltr" placeholder="token" className="min-h-[52px] flex-1 rounded-xl border border-border bg-surface px-4 text-base outline-none focus:border-brand-500" />
        <Button type="submit" size="lg">{ar ? 'تتبع' : 'Track'}</Button>
      </form>
    </div>
  )
}

function TrackView({ token, ar }: { token: string; ar: boolean }) {
  const qc = useQueryClient()
  const [message, setMessage] = useState('')
  const query = useQuery({ queryKey: ['requests', 'track', token], queryFn: () => trackRequest(token) })

  const reply = useMutation({
    mutationFn: () => replyToRequest(token, message),
    onSuccess: () => { setMessage(''); void qc.invalidateQueries({ queryKey: ['requests', 'track', token] }) },
  })
  const replyError = reply.isError ? toApiError(reply.error) : null

  if (query.isLoading) return <div className="h-40 animate-pulse rounded-2xl bg-surface-secondary" />
  if (query.isError) {
    const e = toApiError(query.error)
    return <div className="rounded-2xl border border-danger/30 bg-[var(--negative-background)] p-6 text-center text-sm text-danger">{e.status === 410 ? (ar ? 'انتهت صلاحية رابط التتبع أو أُلغي.' : 'This tracking link has expired or been revoked.') : (ar ? 'رمز تتبع غير صحيح.' : 'Invalid tracking token.')}</div>
  }
  const d = query.data!

  return (
    <div className="space-y-5">
      <div className="rounded-2xl border border-border bg-surface p-5">
        <div className="flex items-center justify-between gap-3">
          <div>
            <div className="text-xs text-text-muted">{ar ? 'رقم الطلب' : 'Request number'}</div>
            <div className="font-mono text-lg font-bold" dir="ltr">{d.reference}</div>
          </div>
          <span className="rounded-full bg-brand-primary-soft px-3 py-1 text-sm font-semibold text-brand-700">{d.status_label}</span>
        </div>
        <div className="mt-3 grid grid-cols-2 gap-3 text-sm">
          <div><span className="text-text-muted">{ar ? 'الخدمة' : 'Service'}: </span><span className="font-medium">{ar ? d.type_ar : d.type}</span></div>
          <div><span className="text-text-muted">{ar ? 'آخر تحديث' : 'Updated'}: </span><span className="font-medium tnum" dir="ltr">{d.updated_at?.slice(0, 10) ?? '—'}</span></div>
        </div>
      </div>

      {d.timeline.length > 0 && (
        <div className="rounded-2xl border border-border bg-surface p-5">
          <h2 className="mb-3 text-sm font-bold">{ar ? 'مسار الطلب' : 'Timeline'}</h2>
          <ol className="space-y-3">
            {d.timeline.map((t, i) => (
              <li key={i} className="flex gap-3 text-sm">
                <CheckCircle2 size={16} className="mt-0.5 shrink-0 text-brand-500" />
                <div><div className="font-medium">{t.message ?? t.status}</div><div className="text-xs text-text-muted tnum" dir="ltr">{t.at?.slice(0, 16).replace('T', ' ')}</div></div>
              </li>
            ))}
          </ol>
        </div>
      )}

      {d.files.length > 0 && (
        <div className="rounded-2xl border border-border bg-surface p-5">
          <h2 className="mb-3 text-sm font-bold">{ar ? 'الملفات' : 'Files'}</h2>
          <ul className="space-y-2">
            {d.files.map((f) => (
              <li key={f.id} className="flex items-center gap-3 rounded-lg border border-border px-3 py-2">
                <FileText size={18} className="shrink-0 text-text-muted" />
                <span className="min-w-0 flex-1 truncate text-sm font-medium">{f.name}</span>
                <a href={trackFileUrl(token, f.id)} className="flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline"><Download size={15} /> {ar ? 'تنزيل' : 'Download'}</a>
              </li>
            ))}
          </ul>
        </div>
      )}

      <div className="rounded-2xl border border-border bg-surface p-5">
        <h2 className="mb-3 text-sm font-bold">{ar ? 'المراسلات' : 'Messages'}</h2>
        {d.comments.length === 0 && <p className="text-sm text-text-muted">{ar ? 'لا توجد رسائل بعد.' : 'No messages yet.'}</p>}
        <ul className="space-y-2.5">
          {d.comments.map((c, i) => (
            <li key={i} className="rounded-lg bg-surface-secondary px-3 py-2.5">
              <div className="text-xs font-semibold text-text-secondary">{c.author}</div>
              <div className="mt-0.5 text-sm text-text-primary">{c.body}</div>
            </li>
          ))}
        </ul>
        <form className="mt-4" onSubmit={(e) => { e.preventDefault(); if (message.trim().length >= 2) reply.mutate() }}>
          <TextareaField label={ar ? 'إضافة رد' : 'Add a reply'} value={message} onChange={(e) => setMessage(e.target.value)} maxLength={2000} error={replyError?.errors?.message?.[0]} />
          <div className="mt-3 flex items-center gap-3">
            <Button type="submit" loading={reply.isPending} disabled={message.trim().length < 2}>{ar ? 'إرسال الرد' : 'Send reply'}</Button>
            {reply.isSuccess && <span className="text-sm font-semibold text-success">{ar ? 'تم الإرسال' : 'Sent'}</span>}
          </div>
        </form>
      </div>
    </div>
  )
}
