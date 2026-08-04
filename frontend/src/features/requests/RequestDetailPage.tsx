import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Lock, MessageSquare } from 'lucide-react'
import {
  addInternalNote, archiveRequest, assignRequest, changeRequestPriority, changeRequestStatus,
  convertRequest, getRequest, raiseQuoteFromRequest, replyToClientInternal, requestInformation,
} from './internalApi'
import { STATUS_LABELS, priorityTone, statusTone } from './labels'
import { Button } from '@/components/ui/Button'
import { controlClass } from '@/components/ui/Field'
import { TextareaField } from '@/components/ui/form'
import { toApiError } from '@/lib/api/client'
import { useAuth } from '@/stores/auth'
import { useT } from '@/lib/i18n'
import { useUi } from '@/stores/ui'
import { fieldDef } from './paidMediaFields'

const STATUS_OPTIONS = ['under_review', 'waiting_client', 'qualified', 'approved', 'in_progress', 'completed', 'rejected', 'cancelled']
const PRIORITY_OPTIONS = ['critical', 'high', 'medium', 'low']

export function RequestDetailPage() {
  const t = useT()
  const lang = useUi((s) => s.locale)
  const { requestId = '' } = useParams()
  const qc = useQueryClient()
  const { user } = useAuth()
  const query = useQuery({ queryKey: ['app', 'request', requestId], queryFn: () => getRequest(requestId) })

  const [note, setNote] = useState('')
  const [reply, setReply] = useState('')
  const [info, setInfo] = useState('')
  const [actionError, setActionError] = useState<string | null>(null)

  const refresh = () => qc.invalidateQueries({ queryKey: ['app', 'request', requestId] })

  const changeStatus = useMutation({ mutationFn: (s: string) => changeRequestStatus(requestId, s), onSuccess: () => { setActionError(null); void refresh() }, onError: (e) => setActionError(toApiError(e).errors?.status?.[0] ?? toApiError(e).message) })
  const changePriority = useMutation({ mutationFn: (p: string) => changeRequestPriority(requestId, p), onSuccess: refresh })
  const assign = useMutation({ mutationFn: (uid: number | null) => assignRequest(requestId, uid), onSuccess: refresh })
  const noteMut = useMutation({ mutationFn: () => addInternalNote(requestId, note), onSuccess: () => { setNote(''); void refresh() } })
  const replyMut = useMutation({ mutationFn: () => replyToClientInternal(requestId, reply), onSuccess: () => { setReply(''); void refresh() } })
  const infoMut = useMutation({ mutationFn: () => requestInformation(requestId, info), onSuccess: () => { setInfo(''); void refresh() } })
  const archiveMut = useMutation({ mutationFn: () => archiveRequest(requestId), onSuccess: refresh })
  const convertMut = useMutation({ mutationFn: () => convertRequest(requestId), onSuccess: refresh, onError: (e) => setActionError(toApiError(e).message) })
  const quoteMut = useMutation({ mutationFn: () => raiseQuoteFromRequest(requestId), onSuccess: refresh, onError: (e) => setActionError(toApiError(e).message) })

  if (query.isLoading) return <div className="mx-auto max-w-4xl"><div className="h-64 animate-pulse rounded-2xl bg-surface-secondary" /></div>
  if (query.isError) return <div className="mx-auto max-w-4xl rounded-2xl border border-danger/30 bg-[var(--negative-background)] p-6 text-center text-sm text-danger">{t('error_generic')}</div>
  const d = query.data!

  return (
    <div className="mx-auto w-full max-w-4xl">
      <Link to="/app/requests" className="mb-4 inline-flex items-center gap-1.5 text-sm font-semibold text-text-secondary hover:text-text-primary"><ArrowLeft size={15} className="rtl:rotate-180" /> {t('requests_inbox')}</Link>

      {/* Header */}
      <div className="rounded-2xl border border-border bg-surface p-5">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <div className="font-mono text-lg font-bold" dir="ltr">{d.reference}</div>
            <div className="text-sm text-text-secondary">{d.service_ar} · {d.contact}</div>
          </div>
          <div className="flex items-center gap-2">
            {/* REQ-LABELS-001 — the name in the reader's language, not the stored key. */}
            <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${statusTone(d.status)}`}>{lang === 'ar' ? d.status_label : d.status_label_en}</span>
            <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${priorityTone(d.priority)}`}>{lang === 'ar' ? d.priority_label : d.priority_label_en}</span>
            {d.sla.remaining_seconds !== null && <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${d.sla.breached_at ? 'bg-danger/15 text-danger' : 'bg-surface-secondary text-text-muted'}`}>SLA {d.sla.paused_at ? t('sla_paused') : Math.round((d.sla.remaining_seconds ?? 0) / 3600) + 'h'}</span>}
          </div>
        </div>

        {/* Actions */}
        <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-border pt-4">
          <select aria-label={t('change_status')} className={`${controlClass} h-10 min-h-0 w-auto py-0`} defaultValue="" onChange={(e) => { if (e.target.value) changeStatus.mutate(e.target.value) }}>
            <option value="">{t('change_status')}</option>
            {STATUS_OPTIONS.map((s) => <option key={s} value={s}>{STATUS_LABELS[s]}</option>)}
          </select>
          <select aria-label={t('col_priority')} className={`${controlClass} h-10 min-h-0 w-auto py-0`} value={d.priority} onChange={(e) => changePriority.mutate(e.target.value)}>
            {PRIORITY_OPTIONS.map((p) => <option key={p} value={p}>{p}</option>)}
          </select>
          {user?.id && (d.assigned_to ? (
            <Button variant="secondary" size="sm" onClick={() => assign.mutate(null)}>{t('unassign')}</Button>
          ) : (
            <Button variant="secondary" size="sm" onClick={() => assign.mutate(Number(user.id))}>{t('assign_to_me')}</Button>
          ))}
          {!d.archived_at && <Button variant="ghost" size="sm" onClick={() => archiveMut.mutate()}>{t('archive')}</Button>}
          {!d.conversion && !d.archived_at && (
            <Button size="sm" onClick={() => convertMut.mutate()} loading={convertMut.isPending}>{t('convert')}</Button>
          )}
          {!d.archived_at && (
            <Button variant="secondary" size="sm" onClick={() => quoteMut.mutate()} loading={quoteMut.isPending}>
              {lang === 'ar' ? 'إنشاء عرض سعر' : 'Raise quote'}
            </Button>
          )}
        </div>
        {actionError && <p className="mt-2 text-sm text-danger">{actionError}</p>}

        {/* Conversion result — replaces the Convert button once done, with links to the created entities. */}
        {d.conversion && (
          <div className="mt-3 flex flex-wrap items-center gap-3 rounded-xl border border-success/30 bg-success/10 px-4 py-3 text-sm">
            <span className="font-semibold text-success">{t('converted')}</span>
            <Link to={`/app/clients/${d.conversion.client_id}`} className="font-semibold text-brand-600 hover:underline">{t('view_client')}</Link>
            <span className="text-text-muted" dir="ltr">·</span>
            <Link to={`/campaigns/${d.conversion.project_id}/${d.conversion.campaign_id}`} className="font-semibold text-brand-600 hover:underline">{t('view_campaign')}</Link>
          </div>
        )}
      </div>

      {/* Details */}
      <div className="mt-5 grid gap-5 lg:grid-cols-[1fr_320px]">
        <div className="space-y-5">
          {/* Communication */}
          <section className="rounded-2xl border border-border bg-surface p-5">
            <h2 className="mb-3 text-sm font-bold text-text-primary">{t('communication')}</h2>
            <ul className="space-y-2.5">
              {d.comments.map((c) => (
                <li key={c.id} className={`rounded-lg px-3 py-2.5 ${c.visibility === 'internal' ? 'border border-warning/30 bg-warning/10' : 'bg-surface-secondary'}`}>
                  <div className="flex items-center gap-1.5 text-xs font-semibold text-text-secondary">
                    {c.visibility === 'internal' ? <><Lock size={12} className="text-warning" /> {t('internal_note')}</> : <><MessageSquare size={12} /> {c.author}</>}
                  </div>
                  <div className="mt-0.5 text-sm text-text-primary">{c.body}</div>
                </li>
              ))}
              {d.comments.length === 0 && <li className="text-sm text-text-muted">{t('no_messages')}</li>}
            </ul>

            <div className="mt-4 space-y-3 border-t border-border pt-4">
              <div>
                <TextareaField label={t('add_internal_note')} value={note} onChange={(e) => setNote(e.target.value)} maxLength={2000} />
                <Button size="sm" className="mt-2" onClick={() => noteMut.mutate()} loading={noteMut.isPending} disabled={note.trim().length < 2}>{t('add_internal_note')}</Button>
              </div>
              <div>
                <TextareaField label={t('reply_to_client')} value={reply} onChange={(e) => setReply(e.target.value)} maxLength={2000} />
                <Button size="sm" className="mt-2" onClick={() => replyMut.mutate()} loading={replyMut.isPending} disabled={reply.trim().length < 2}>{t('reply_to_client')}</Button>
              </div>
              <div>
                <TextareaField label={t('request_information')} value={info} onChange={(e) => setInfo(e.target.value)} maxLength={2000} />
                <Button size="sm" variant="secondary" className="mt-2" onClick={() => infoMut.mutate()} loading={infoMut.isPending} disabled={info.trim().length < 2}>{t('request_information')}</Button>
              </div>
            </div>
          </section>

          {/* Timeline */}
          <section className="rounded-2xl border border-border bg-surface p-5">
            <h2 className="mb-3 text-sm font-bold text-text-primary">{t('activity')}</h2>
            <ol className="space-y-2">
              {d.events.map((e, i) => (
                <li key={i} className="flex gap-2 text-sm text-text-secondary">
                  <span className="tnum text-xs text-text-muted" dir="ltr">{e.at?.slice(5, 16).replace('T', ' ')}</span>
                  <span>{e.message ?? e.type}</span>
                </li>
              ))}
            </ol>
          </section>
        </div>

        {/* Sidebar */}
        <aside className="space-y-3">
          <section className="rounded-2xl border border-border bg-surface p-5 text-sm">
            <h2 className="mb-3 text-sm font-bold text-text-primary">{t('request_details')}</h2>
            <dl className="space-y-2">
              <Info k={t('field_job_title')} v={d.objective ?? '—'} />
              <Info k="Email" v={d.contact_email} />
              {d.company_name && <Info k={t('org_name')} v={d.company_name} />}
              {d.budget && <Info k={t('field_number_format')} v={`${d.budget} ${d.currency}`} />}
              <Info k={t('col_assignee')} v={d.assignee ?? '—'} />
            </dl>
          </section>
          {/* Selected services (canonical request_services, resolved to display labels). */}
          {(d.services_resolved?.length ?? 0) > 0 && (
            <section className="rounded-2xl border border-border bg-surface p-5 text-sm">
              <h2 className="mb-3 text-sm font-bold text-text-primary">{lang === 'ar' ? 'الخدمات المطلوبة' : 'Requested services'}</h2>
              <ul className="flex flex-wrap gap-1.5">
                {d.services_resolved!.map((s) => (
                  <li key={s.key} className="rounded-full bg-surface-hover px-2.5 py-1 text-[11px] font-semibold text-text-secondary">
                    {lang === 'ar' ? (s.label_ar ?? s.label_en ?? s.key) : (s.label_en ?? s.key)}
                  </li>
                ))}
              </ul>
            </section>
          )}

          {/*
            REQ-DYNFIELDS-001 — the answers the client actually gave.
            The public intake asks a DIFFERENT set of questions per service (`required_field_rules` →
            dynamic fields), stores them in `service_details`, and the operator's page never showed
            them. So the person who has to act on the request could see WHICH services were asked for
            and none of what was said about them — the brief, the budget, the platforms, the tracking
            details — and had to go back to the client for information already sitting in the record.
          */}
          <ServiceAnswers details={d.service_details} lang={lang} />

          {/* Billing thread — quotes raised from this request, each with its issued invoice. */}
          {(d.billing?.length ?? 0) > 0 && (
            <section className="rounded-2xl border border-border bg-surface p-5 text-sm">
              <h2 className="mb-3 text-sm font-bold text-text-primary">{lang === 'ar' ? 'العروض والفواتير' : 'Quotes & invoices'}</h2>
              <ul className="space-y-2">
                {d.billing!.map((b) => (
                  <li key={b.quote_id} className="rounded-xl border border-border p-2.5">
                    <Link to="/app/billing" className="flex items-center justify-between gap-2 hover:text-brand-600">
                      <span className="font-mono text-xs font-semibold text-brand-600" dir="ltr">{b.number}</span>
                      <span className="tnum text-xs font-bold" dir="ltr">{Number(b.total).toLocaleString('en-US')} {b.currency}</span>
                    </Link>
                    {b.invoice && (
                      <Link to="/app/billing/invoices" className="mt-1 flex items-center justify-between gap-2 border-t border-border pt-1 text-text-secondary hover:text-brand-600">
                        <span className="font-mono text-[11px]" dir="ltr">{b.invoice.number}</span>
                        <span className="rounded-full bg-surface-hover px-1.5 py-0.5 text-[10px] font-semibold">{b.invoice.status}</span>
                      </Link>
                    )}
                  </li>
                ))}
              </ul>
            </section>
          )}

          {d.files.length > 0 && (
            <section className="rounded-2xl border border-border bg-surface p-5 text-sm">
              <h2 className="mb-3 text-sm font-bold text-text-primary">{t('files')}</h2>
              <ul className="space-y-1.5">
                {d.files.map((f) => <li key={f.id} className="flex items-center justify-between gap-2 text-text-secondary"><span className="truncate">{f.name}</span>{!f.client_visible && <Lock size={12} className="shrink-0 text-warning" />}</li>)}
              </ul>
            </section>
          )}
        </aside>
      </div>
    </div>
  )
}

function Info({ k, v }: { k: string; v: string }) {
  return <div><dt className="text-xs text-text-muted">{k}</dt><dd className="break-words font-medium text-text-primary">{v}</dd></div>
}


/**
 * The per-service intake answers, labelled.
 *
 * Renders through the SAME field definitions the intake form used (`fieldDef`), so a question and its
 * answer always carry the same wording — a second list of labels here would drift from the form the
 * client filled in, and the operator would be reading a different question from the one that was
 * asked.
 *
 * A token with no definition still shows, under its raw key. That is deliberate: an answer the client
 * typed is worth more than tidiness, and a value silently dropped because a definition was renamed is
 * information lost with nothing on screen to say so.
 */
function ServiceAnswers({ details, lang }: { details: Record<string, unknown> | null; lang: string }) {
  const ar = lang === 'ar'
  const entries = Object.entries(details ?? {}).filter(([, v]) => v !== null && v !== undefined && v !== '' && !(Array.isArray(v) && v.length === 0))
  if (entries.length === 0) return null

  const render = (value: unknown): string => {
    if (Array.isArray(value)) return value.map((v) => String(v)).join('، ')
    if (typeof value === 'boolean') return value ? (ar ? 'نعم' : 'Yes') : (ar ? 'لا' : 'No')
    if (value !== null && typeof value === 'object') return JSON.stringify(value)
    return String(value)
  }

  return (
    <section data-testid="request-service-answers" className="rounded-2xl border border-border bg-surface p-5 text-sm">
      <h2 className="mb-3 text-sm font-bold text-text-primary">{ar ? 'تفاصيل الخدمة' : 'Service details'}</h2>
      <dl className="grid gap-x-6 gap-y-2 sm:grid-cols-2">
        {entries.map(([token, value]) => {
          const def = fieldDef(token)
          return (
            <div key={token} className="min-w-0">
              <dt className="text-xs text-text-muted">{def ? (ar ? def.labelAr : def.labelEn) : token}</dt>
              <dd className="break-words font-medium text-text-primary">{render(value)}</dd>
            </div>
          )
        })}
      </dl>
    </section>
  )
}
