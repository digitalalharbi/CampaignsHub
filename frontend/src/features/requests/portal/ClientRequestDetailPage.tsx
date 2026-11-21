import { useState } from 'react'
import { fmtDateTime } from '@/lib/datetime'
import { Link, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Bell, Check, Download, FolderOpen, History, MessagesSquare, Paperclip, Sparkles } from 'lucide-react'
import { ChatAttach, ChatComposer, ChatMessages } from './PortalChat'
import { getPortalRequest, portalFileUrl, portalReply, portalStartUpload, portalUploadFile } from '../clientPortalApi'
import { getPortalJourney, isOfframpStage, journeySteps, type PortalJourney } from './portalAccountApi'
import { PortalShell } from './PortalShell'
import { usePortalGuard } from './usePortalGuard'
import { useUi } from '@/stores/ui'
import { useClientSpacePath } from './clientSpace'
import { QueryFailure } from '@/components/ui/QueryFailure'

/** Bilingual labels for the main-line journey stages (keys mirror the backend RequestStage enum). */
const STAGE_LABELS: Record<string, { ar: string; en: string }> = {
  submitted: { ar: 'تم الاستلام', en: 'Submitted' },
  under_review: { ar: 'قيد المراجعة', en: 'Under review' },
  qualified: { ar: 'مؤهّل', en: 'Qualified' },
  proposal_sent: { ar: 'أُرسل العرض', en: 'Proposal sent' },
  awaiting_client_approval: { ar: 'بانتظار موافقتك', en: 'Awaiting your approval' },
  payment_pending: { ar: 'بانتظار السداد', en: 'Payment pending' },
  paid: { ar: 'مدفوع', en: 'Paid' },
  onboarding: { ar: 'التهيئة', en: 'Onboarding' },
  in_progress: { ar: 'قيد التنفيذ', en: 'In progress' },
  client_review: { ar: 'مراجعتك', en: 'Your review' },
  completed: { ar: 'مكتمل', en: 'Completed' },
}

/** Actions the client can take from the current stage → bilingual copy + a portal destination when one exists. */
const ACTION_META: Record<string, { ar: string; en: string; to?: string }> = {
  approve_quote: { ar: 'الموافقة على عرض السعر', en: 'Approve your quote', to: '/client/quotes' },
  reject_quote: { ar: 'مراجعة عرض السعر', en: 'Review your quote', to: '/client/quotes' },
  pay_invoice: { ar: 'سداد الفاتورة', en: 'Pay your invoice', to: '/client/invoices' },
  cancel_request: { ar: 'لإلغاء الطلب تواصل مع فريقك', en: 'To cancel, contact your team' },
}

export function ClientRequestDetailPage() {
  const spaceTo = useClientSpacePath()
  const ar = useUi((s) => s.locale) === 'ar'
  const qc = useQueryClient()
  const { reference = '' } = useParams()
  const q = useQuery({ queryKey: ['client', 'request', reference], queryFn: () => getPortalRequest(reference), retry: false })
  const journey = useQuery({ queryKey: ['client', 'journey', reference], queryFn: () => getPortalJourney(reference), retry: false })
  usePortalGuard(q.isError, q.error)

  const [uploadToken, setUploadToken] = useState<string | null>(null)
  const [attached, setAttached] = useState<string[]>([])
  const [uploading, setUploading] = useState(false)

  const reply = useMutation({
    mutationFn: (message: string) => portalReply(reference, message, uploadToken ?? undefined),
    onSuccess: () => { setUploadToken(null); setAttached([]); qc.invalidateQueries({ queryKey: ['client', 'request', reference] }) },
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

  if (q.isLoading) return <PortalShell title={ar ? 'الطلب' : 'Request'} nav showLogout><div className="h-64 animate-pulse rounded-2xl bg-surface-secondary" /></PortalShell>
  if (q.isError) {
    return (
      <PortalShell title={ar ? 'الطلب' : 'Request'} nav showLogout>
        <QueryFailure error={q.error} ar={ar} testId="portal-request-failure" onRetry={() => void q.refetch()}
          fallbackTitle={ar ? 'تعذّر تحميل الطلب.' : 'The request could not be loaded.'} />
      </PortalShell>
    )
  }
  const d = q.data!

  return (
    <PortalShell title={ar ? 'تفاصيل الطلب' : 'Request details'} nav showLogout>
      <Link to={spaceTo('/requests')} className="mb-4 inline-flex items-center gap-1.5 text-sm font-semibold text-text-secondary hover:text-text-primary"><ArrowLeft size={15} className="rtl:rotate-180" /> {ar ? 'طلباتي' : 'My requests'}</Link>

      <div className="rounded-2xl border border-border bg-surface p-5 sm:p-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div className="font-mono text-xs font-semibold text-brand-600" dir="ltr">{d.reference}</div>
            <h1 className="mt-0.5 font-heading text-xl font-extrabold text-text-primary">{ar ? d.type_ar : d.type}</h1>
          </div>
          <span className="rounded-full bg-brand-primary-soft px-3 py-1 text-xs font-semibold text-brand-700">{ar ? d.status_label : d.status_label_en}</span>
        </div>
        <div className="mt-4 h-2.5 overflow-hidden rounded-full bg-surface-secondary"><div className="h-full rounded-full bg-brand-500 transition-all" style={{ width: `${d.progress}%` }} /></div>
        <div className="tnum mt-1 text-[11px] text-text-muted">{ar ? 'الجاهزية' : 'Progress'}: {d.progress}%</div>

        {d.result?.converted && (
          <div className="mt-4 rounded-xl border border-success/30 bg-success/5 p-4">
            <div className="flex items-center gap-1.5 text-sm font-bold text-success"><Sparkles size={15} /> {ar ? 'تم تحويل طلبك إلى تنفيذ' : 'Your request is now in delivery'}</div>
            <div className="mt-1 text-sm text-text-secondary">
              {/*
                CLIENT-REPORT-ENTITY-BOUNDARY-001 — «الحملة: Meta — Lead Gen (burner) (paused)» stood
                here. The campaign is the agency's container and its status is a state the client
                neither sets nor acts on. The project is theirs, and it is the whole answer.
              */}
              {d.result.project_name && <span>{ar ? 'المشروع' : 'Project'}: <b className="text-text-primary">{d.result.project_name}</b>.</span>}
            </div>
          </div>
        )}
      </div>

      {/* Journey progress + what's required from the client */}
      <JourneySection journey={journey.data} loading={journey.isLoading} ar={ar} />

      <div className="mt-4 grid gap-4 lg:grid-cols-3">
        {/* CLIENT-CHAT-001 — the conversation, as a chat */}
        <section className="rounded-2xl border border-border bg-surface p-4 sm:p-5 lg:col-span-2" data-testid="request-chat">
          <div className="mb-3 flex items-center gap-2">
            <span className="grid h-9 w-9 place-items-center rounded-full bg-brand-600/15 text-brand-600"><MessagesSquare size={17} /></span>
            <div className="min-w-0">
              <h2 className="text-sm font-bold text-text-primary">{ar ? 'المحادثة مع الفريق' : 'Conversation with the team'}</h2>
              <p className="text-[11px] text-text-muted">{ar ? '✓ أُرسلت · ✓✓ قرأها الفريق' : '✓ sent · ✓✓ read by the team'}</p>
            </div>
          </div>
          <ChatMessages
            team={ar ? 'الفريق' : 'Team'}
            empty={ar ? 'ابدأ المحادثة مع فريقك — نردّ عليك هنا.' : 'Start the conversation — the team replies here.'}
            messages={d.comments.map((c, i) => ({ id: String(i), mine: c.author === 'Client', body: c.body, at: c.at, readAt: c.read_by_team_at ?? null }))}
          />
          <ChatComposer
            minLength={2}
            sending={reply.isPending || uploading}
            placeholder={ar ? 'اكتب رسالتك…' : 'Write a message…'}
            onSend={(body) => reply.mutate(body)}
            attach={<ChatAttach uploading={uploading} onFile={(f) => void onFile(f)} label={ar ? 'إرفاق ملف' : 'Attach a file'} />}
            note={attached.length > 0 ? <div className="mb-1.5 flex flex-wrap gap-1.5">{attached.map((n) => <span key={n} className="inline-flex items-center gap-1 rounded-full bg-surface-secondary px-2.5 py-1 text-[11px] text-text-secondary"><Paperclip size={11} />{n}</span>)}</div> : undefined}
          />
        </section>

        <aside className="grid content-start gap-4">
          {/* The request's history, in the client's language */}
          <section className="rounded-2xl border border-border bg-surface p-5" data-testid="request-history">
            <h2 className="mb-3 flex items-center gap-1.5 text-sm font-bold text-text-primary"><History size={15} /> {ar ? 'سجل الطلب' : 'Request history'}</h2>
            {d.timeline.length === 0 ? <p className="text-sm text-text-muted">{ar ? 'لا تحديثات بعد.' : 'No updates yet.'}</p> : (
              <ol className="relative ms-2 space-y-3 border-s border-border ps-4">
                {[...d.timeline].reverse().map((t, i) => (
                  <li key={i} className="relative">
                    <span className={`absolute -start-[22px] top-1 h-2.5 w-2.5 rounded-full ring-4 ring-surface ${i === 0 ? 'bg-brand-500' : 'bg-border-strong'}`} />
                    <div className={`text-sm ${i === 0 ? 'font-bold text-text-primary' : 'text-text-secondary'}`}>{(ar ? t.label_ar : t.label_en) ?? (ar ? 'تحديث على طلبك' : 'Your request was updated')}</div>
                    <div className="tnum text-[11px] text-text-muted">{t.at ? fmtDateTime(t.at) : ''}</div>
                  </li>
                ))}
              </ol>
            )}
          </section>

          <section className="rounded-2xl border border-border bg-surface p-5">
            <h2 className="mb-3 flex items-center gap-1.5 text-sm font-bold text-text-primary"><FolderOpen size={15} /> {ar ? 'الملفات' : 'Files'}</h2>
            {d.files.length === 0 ? <p className="text-sm text-text-muted">{ar ? 'لا توجد ملفات — أرفقها من المحادثة.' : 'No files — attach one from the conversation.'}</p> : (
              <ul className="space-y-1.5">
                {d.files.map((f) => (
                  <li key={f.id}>
                    <a href={portalFileUrl(d.reference, f.id)} className="flex items-center justify-between gap-2 rounded-xl border border-border px-3 py-2 text-sm hover:border-brand-400">
                      <span className="truncate text-text-primary">{f.name}</span>
                      <Download size={15} className="shrink-0 text-brand-600" />
                    </a>
                  </li>
                ))}
              </ul>
            )}
          </section>

          {d.notifications.length > 0 && (
            <section className="rounded-2xl border border-border bg-surface p-5">
              <h2 className="mb-3 flex items-center gap-1.5 text-sm font-bold text-text-primary"><Bell size={15} /> {ar ? 'التنبيهات المرسلة لك' : 'Updates sent to you'}</h2>
              <ul className="space-y-1.5 text-xs">
                {d.notifications.map((n, i) => (
                  <li key={i} className="flex items-center justify-between gap-2 rounded-xl bg-surface-secondary px-3 py-2">
                    <span className="text-text-primary">{noticeLabel(n.event, ar)} <span className="text-text-muted">· {channelLabel(n.channel, ar)}</span></span>
                    <span className={n.status === 'sent' ? 'inline-flex items-center gap-1 font-semibold text-success' : 'text-text-muted'}>
                      {n.status === 'sent' && <Check size={12} />}{noticeStatus(n.status, ar)}
                    </span>
                  </li>
                ))}
              </ul>
            </section>
          )}
        </aside>
      </div>
    </PortalShell>
  )
}

/** The staged journey progress bar + the client's allowed next actions (grounded in the journey endpoint). */
function JourneySection({ journey, loading, ar }: { journey: PortalJourney | undefined; loading: boolean; ar: boolean }) {
  if (loading) return <div className="mt-4 h-28 animate-pulse rounded-2xl bg-surface-secondary" />
  if (!journey) return null // journey endpoint unavailable — the header progress bar still conveys status honestly

  // CLIENT-CHAT-001 — a request the client has sent is never a «draft» to them: before the journey writes a stage, it is received.
  const stage = ['draft', 'contact_verification'].includes(journey.stage) ? 'submitted' : journey.stage
  const offramp = isOfframpStage(stage)
  const steps = journeySteps(stage)
  const actions = journey.client_actions ?? []

  return (
    <section className="mt-4 rounded-2xl border border-border bg-surface p-5">
      <div className="mb-4 flex items-center justify-between gap-2">
        <h2 className="text-sm font-bold text-text-primary">{ar ? 'مسار الطلب' : 'Request journey'}</h2>
        <span className="rounded-full bg-surface-secondary px-2.5 py-1 text-[11px] font-semibold text-text-secondary">
          {offramp ? journey.stage_label : (STAGE_LABELS[stage] ? (ar ? STAGE_LABELS[stage].ar : STAGE_LABELS[stage].en) : journey.stage_label)}
        </span>
      </div>

      {offramp ? (
        <p className="rounded-xl bg-surface-secondary px-4 py-3 text-sm text-text-secondary">
          {ar ? 'حالة الطلب الآن:' : 'Your request is now:'} <b className="text-text-primary">{journey.stage_label}</b>
        </p>
      ) : (
        <ol className="flex flex-wrap gap-x-1 gap-y-3">
          {steps.map((step, i) => {
            const label = STAGE_LABELS[step.key] ? (ar ? STAGE_LABELS[step.key].ar : STAGE_LABELS[step.key].en) : step.key
            return (
              <li key={step.key} className="flex items-center gap-1">
                <div className="flex flex-col items-center gap-1 px-1">
                  <span className={`flex h-6 w-6 items-center justify-center rounded-full text-[11px] font-bold ${
                    step.done ? 'bg-brand-500 text-white' : step.current ? 'bg-brand-primary-soft text-brand-700 ring-2 ring-brand-500' : 'bg-surface-secondary text-text-muted'
                  }`}>
                    {step.done ? <Check size={13} /> : <span className="tnum">{i + 1}</span>}
                  </span>
                  <span className={`max-w-[68px] text-center text-[10px] leading-tight ${step.current ? 'font-bold text-brand-700' : 'text-text-muted'}`}>{label}</span>
                </div>
                {i < steps.length - 1 && <span className={`h-0.5 w-3 rounded ${step.done ? 'bg-brand-500' : 'bg-border'}`} />}
              </li>
            )
          })}
        </ol>
      )}

      {actions.length > 0 && (
        <div className="mt-4 rounded-xl border border-brand-400/40 bg-brand-primary-soft/30 p-4">
          <div className="text-sm font-bold text-brand-700">{ar ? 'المطلوب منك' : 'What’s required from you'}</div>
          <ul className="mt-2 flex flex-wrap gap-2">
            {actions.map((a) => {
              const meta = ACTION_META[a.key]
              const label = meta ? (ar ? meta.ar : meta.en) : a.label
              return meta?.to ? (
                <li key={a.key}>
                  <Link to={meta.to} className="inline-flex items-center gap-1 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700">{label}</Link>
                </li>
              ) : (
                <li key={a.key}><span className="inline-flex items-center rounded-lg bg-surface px-3 py-1.5 text-xs font-semibold text-text-secondary">{label}</span></li>
              )
            })}
          </ul>
        </div>
      )}
    </section>
  )
}

/** CLIENT-CHAT-001 — what each update sent to the client was, in the client's language. */
const NOTICES: Record<string, [string, string]> = {
  received: ['تأكيد استلام طلبك', 'Request received'],
  info_requested: ['طلب معلومات منك', 'Information requested'],
  team_reply: ['ردّ جديد من الفريق', 'New reply from the team'],
  approved: ['الموافقة على طلبك', 'Request approved'],
  in_progress: ['بدء العمل على طلبك', 'Work started'],
  completed: ['اكتمال طلبك', 'Request completed'],
}
const noticeLabel = (event: string, ar: boolean) => (NOTICES[event] ? NOTICES[event][ar ? 0 : 1] : (ar ? 'تحديث على طلبك' : 'Request update'))
const channelLabel = (channel: string, ar: boolean) => ({ email: ar ? 'البريد' : 'Email', whatsapp: ar ? 'واتساب' : 'WhatsApp', sms: ar ? 'رسالة نصية' : 'SMS' }[channel] ?? channel)
function noticeStatus(status: string, ar: boolean): string {
  if (status === 'sent') return ar ? 'وصلت' : 'Delivered'
  if (status === 'failed') return ar ? 'لم تصل' : 'Not delivered'
  if (status === 'awaiting_provider_credentials') return ar ? 'بانتظار تفعيل القناة' : 'Channel not enabled yet'

  return ar ? 'قيد الإرسال' : 'Sending'
}
