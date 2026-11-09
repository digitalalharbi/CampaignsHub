import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { X } from 'lucide-react'
import { useAuth } from '@/stores/auth'
import { days } from '@/lib/counted'
import { fmtDateTime } from '@/lib/datetime'
import { toApiError } from '@/lib/api/client'
import { assignAlert, decideAlert, getAlertDetail, noteAlert, type AlertImpactResult, type DecisionLogLine, type DecisionStatus } from './api'
import { ACTION_TAG_LABEL, IMPACT_REASON, PRIORITY_TONE, STATUS_LABEL, pick } from './decisionCopy'

const T = {
  ar: { close: 'إغلاق', problem: 'المشكلة', evidence: 'الدليل', impact: 'الأثر', action: 'الإجراء المقترح', owner: 'المسؤول', decision: 'القرار', note: 'ملاحظة', add_note: 'أضف ملاحظة',
    save: 'حفظ', unowned: 'بلا مسؤول', reason: 'سبب التجاهل (مطلوب)', tag: 'ما الذي فعلتَه؟', no_tag: 'بدون وسم', log: 'سجل القرارات', impact_title: 'أثر الإجراء',
    before: 'قبل', after: 'بعد', window: (n: number) => `${days(n, 'ar')} قبل وبعد`, remaining: (n: number) => `متبقٍ ${days(n, 'ar')}`, thin: 'بيانات غير كافية — لا نوصي بإجراء قبل اكتمال الدليل.',
    improved: 'تحسّن', worsened: 'ساء', flat: 'لم يتغير', unclear: 'غير واضح', by: 'بواسطة', insufficient: 'بيانات غير كافية', not_measurable: 'غير قابل للقياس', completeness: 'اكتمال البيانات',
    complete: 'مكتملة', unverified: 'غير مؤكدة', partial: 'ناقصة', threshold: 'الحد', kind: { status: 'قرار', assign: 'إسناد', note: 'ملاحظة', resolve: 'إغلاق', snooze: 'تأجيل' } as Record<string, string> },
  en: { close: 'Close', problem: 'Problem', evidence: 'Evidence', impact: 'Impact', action: 'Recommended action', owner: 'Owner', decision: 'Decision', note: 'Note', add_note: 'Add a note',
    save: 'Save', unowned: 'No owner', reason: 'Reason for dismissing (required)', tag: 'What did you do?', no_tag: 'No tag', log: 'Decision log', impact_title: 'Impact of the action',
    before: 'Before', after: 'After', window: (n: number) => `${days(n, 'en')} before and after`, remaining: (n: number) => `${days(n, 'en')} remaining`, thin: 'Insufficient data — no action is recommended until the evidence is complete.',
    improved: 'Improved', worsened: 'Worsened', flat: 'No change', unclear: 'Unclear', by: 'by', insufficient: 'Insufficient data', not_measurable: 'Not measurable', completeness: 'Data completeness',
    complete: 'Complete', unverified: 'Unverified', partial: 'Partial', threshold: 'Threshold', kind: { status: 'Decision', assign: 'Assignment', note: 'Note', resolve: 'Closed', snooze: 'Snoozed' } as Record<string, string> },
}

const fmt = (n: number | null | undefined): string => (typeof n === 'number' ? n.toLocaleString('en-US', { maximumFractionDigits: 2 }) : '—')

/**
 * OPERATIONAL-INTELLIGENCE-P1-001 — one alert, decision-ready: the five fields (problem, evidence, impact, action, owner),
 * the decision controls, the measured impact of «done», and the append-only log. Thin evidence shows no recommendation.
 */
export function AlertDecisionDrawer({ id, locale, onClose }: { id: string; locale: 'ar' | 'en'; onClose: () => void }) {
  const ar = locale === 'ar'
  const t = T[locale]
  const qc = useQueryClient()
  const canManage = useAuth((s) => s.hasPermission('alerts.manage'))
  const q = useQuery({ queryKey: ['alert-detail', id], queryFn: () => getAlertDetail(id), retry: false })
  const [status, setStatus] = useState<DecisionStatus | null>(null)
  const [tag, setTag] = useState('')
  const [reason, setReason] = useState('')
  const [note, setNote] = useState('')
  const [error, setError] = useState<string | null>(null)
  const closeRef = useRef<HTMLButtonElement | null>(null)
  useEffect(() => { closeRef.current?.focus() }, [])
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose() }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [onClose])

  const refresh = () => {
    void qc.invalidateQueries({ queryKey: ['alert-detail', id] })
    void qc.invalidateQueries({ queryKey: ['alert-events'] })
    void qc.invalidateQueries({ queryKey: ['alert-attention'] })
  }
  const fail = (e: unknown) => setError(toApiError(e).message)
  const decide = useMutation({
    mutationFn: () => decideAlert(id, { status: status as DecisionStatus, reason: status === 'dismissed' ? reason.trim() : undefined, action_tag: status === 'done' && tag ? tag : undefined }),
    onSuccess: () => { setError(null); setStatus(null); setReason(''); setTag(''); refresh() }, onError: fail,
  })
  const assign = useMutation({ mutationFn: (ownerId: number | null) => assignAlert(id, ownerId), onSuccess: () => { setError(null); refresh() }, onError: fail })
  const addNote = useMutation({ mutationFn: () => noteAlert(id, note.trim()), onSuccess: () => { setError(null); setNote(''); refresh() }, onError: fail })

  const d = q.data
  const ev = d?.event
  const an = ev?.anatomy
  const evi = ev?.evidence
  const current: DecisionStatus = (ev?.decision_status as DecisionStatus | undefined) ?? 'new'
  const canSave = status !== null && status !== current && (status !== 'dismissed' || reason.trim().length >= 3)

  return (
    <div className="fixed inset-0 z-40 flex justify-end bg-black/30" onClick={onClose}>
      <aside role="dialog" aria-modal="true" aria-label={t.decision} data-testid="alert-drawer" onClick={(e) => e.stopPropagation()}
        className="flex h-full w-full max-w-md flex-col gap-4 overflow-y-auto bg-surface p-5 shadow-xl">
        <div className="flex items-start justify-between gap-3">
          <div className="flex flex-wrap items-center gap-2">
            {ev?.priority && <span data-testid="drawer-priority" className={`rounded-full px-2 py-0.5 text-xs font-extrabold ${PRIORITY_TONE[ev.priority]}`}>{ev.priority}</span>}
            <h2 className="text-lg font-extrabold text-text-primary" data-testid="drawer-field-problem">{pick(an?.problem, ar) || '…'}</h2>
          </div>
          <button ref={closeRef} type="button" onClick={onClose} aria-label={t.close} className="rounded-lg p-1.5 text-text-secondary hover:bg-surface-hover"><X size={18} /></button>
        </div>

        {q.isError && <p className="rounded-xl bg-danger/10 px-3 py-2 text-sm text-danger">{toApiError(q.error).message}</p>}

        {d && ev && (
          <>
            <dl className="flex flex-col gap-2 rounded-2xl border border-border p-4 text-sm">
              <Field label={t.evidence} testid="drawer-field-evidence">
                <p dir="auto">{pick(an?.numbers, ar) || '—'}</p>
                {evi && (
                  <p className="text-xs text-text-muted">
                    {t.completeness}: {t[evi.completeness]}{evi.threshold !== null && evi.threshold_unit === '%' ? ` · ${t.threshold} ${fmt(evi.threshold)}%` : ''}
                    {evi.window_days ? ` · ${t.window(evi.window_days)}` : ''}
                  </p>
                )}
              </Field>
              {evi?.sufficient === false ? (
                <Field label={t.action} testid="drawer-field-thin"><span className="font-semibold text-text-muted">{t.thin}</span></Field>
              ) : (
                <>
                  <Field label={t.impact} testid="drawer-field-impact">{pick(an?.impact, ar) || '—'}</Field>
                  <Field label={t.action} testid="drawer-field-action"><span className="font-semibold text-brand-700">{pick(an?.action, ar) || '—'}</span></Field>
                </>
              )}
              <Field label={t.owner} testid="drawer-field-owner">
                {canManage ? (
                  <select data-testid="drawer-owner" value={d.owner?.id ?? ''} disabled={assign.isPending}
                    onChange={(e) => assign.mutate(e.target.value === '' ? null : Number(e.target.value))}
                    className="w-full rounded-lg border border-border bg-background px-2.5 py-2 text-sm text-text-primary">
                    <option value="">{t.unowned}</option>
                    {d.assignees.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                  </select>
                ) : <span className="font-semibold">{d.owner?.name || t.unowned}</span>}
              </Field>
            </dl>

            {canManage && (
              <div className="flex flex-col gap-2" data-testid="drawer-decision">
                <p className="text-xs font-semibold text-text-secondary">{t.decision}: <span className="text-text-primary">{pick(STATUS_LABEL[current], ar)}</span></p>
                <div className="flex flex-wrap gap-2" role="group" aria-label={t.decision}>
                  {(['in_progress', 'done', 'dismissed'] as DecisionStatus[]).map((s) => (
                    <button key={s} type="button" data-testid={`drawer-status-${s}`} aria-pressed={status === s} onClick={() => setStatus(status === s ? null : s)}
                      className={`rounded-lg border px-3 py-1.5 text-xs font-bold ${status === s ? 'border-brand-600 bg-brand-600 text-white' : 'border-border text-text-secondary hover:border-brand-500'}`}>
                      {pick(STATUS_LABEL[s], ar)}
                    </button>
                  ))}
                </div>
                {status === 'done' && (
                  <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">{t.tag}
                    <select data-testid="drawer-action-tag" value={tag} onChange={(e) => setTag(e.target.value)} className="rounded-lg border border-border bg-background px-2.5 py-2 text-sm text-text-primary">
                      <option value="">{t.no_tag}</option>
                      {d.action_tags.map((k) => <option key={k} value={k}>{pick(ACTION_TAG_LABEL[k], ar) || k}</option>)}
                    </select>
                  </label>
                )}
                {status === 'dismissed' && (
                  <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">{t.reason}
                    <textarea data-testid="drawer-reason" value={reason} maxLength={500} rows={2} onChange={(e) => setReason(e.target.value)} className="rounded-lg border border-border bg-background px-2.5 py-2 text-sm text-text-primary" />
                  </label>
                )}
                {status !== null && (
                  <button type="button" data-testid="drawer-save" disabled={!canSave || decide.isPending} onClick={() => decide.mutate()}
                    className="rounded-lg bg-brand-600 px-3 py-2 text-sm font-bold text-white hover:bg-brand-700 disabled:opacity-50">{t.save}</button>
                )}
              </div>
            )}

            {error && <p role="alert" data-testid="drawer-error" className="rounded-xl bg-danger/10 px-3 py-2 text-sm text-danger">{error}</p>}

            <ImpactBox impact={d.impact} t={t} ar={ar} />

            <div className="flex flex-col gap-2">
              <h3 className="text-sm font-bold text-text-primary">{t.log}</h3>
              {canManage && (
                <div className="flex gap-2">
                  <input data-testid="drawer-note" value={note} maxLength={500} placeholder={t.note} onChange={(e) => setNote(e.target.value)} className="min-w-0 flex-1 rounded-lg border border-border bg-background px-2.5 py-2 text-sm text-text-primary" />
                  <button type="button" data-testid="drawer-note-add" disabled={note.trim() === '' || addNote.isPending} onClick={() => addNote.mutate()} className="rounded-lg border border-border px-3 py-2 text-xs font-bold text-text-secondary hover:border-brand-500 disabled:opacity-50">{t.add_note}</button>
                </div>
              )}
              <ul data-testid="drawer-log" className="flex flex-col gap-1.5">
                {[...d.log].reverse().map((l) => <LogLine key={l.id} l={l} t={t} ar={ar} />)}
              </ul>
            </div>
          </>
        )}
      </aside>
    </div>
  )
}

function Field({ label, testid, children }: { label: string; testid: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5" data-testid={testid}>
      <dt className="text-xs font-semibold text-text-muted">{label}</dt>
      <dd className="text-text-primary">{children}</dd>
    </div>
  )
}

function ImpactBox({ impact, t, ar }: { impact: AlertImpactResult; t: (typeof T)['ar']; ar: boolean }) {
  if (impact.state === 'none') return null
  const reason = impact.reason ? pick(IMPACT_REASON[impact.reason], ar) : ''
  const tone = impact.verdict === 'improved' ? 'text-success' : impact.verdict === 'worsened' ? 'text-danger' : 'text-text-secondary'

  return (
    <div data-testid="drawer-impact" data-state={impact.state} className="flex flex-col gap-1 rounded-2xl border border-border p-4 text-sm">
      <h3 className="text-sm font-bold text-text-primary">{t.impact_title}{impact.action_tag ? ` · ${pick(ACTION_TAG_LABEL[impact.action_tag], ar) || impact.action_tag}` : ''}</h3>
      {impact.state === 'computed' ? (
        <p className="flex flex-wrap items-baseline gap-2">
          <span className="tnum" dir="ltr">{t.before} {fmt(impact.before)} → {t.after} {fmt(impact.after)}</span>
          <span className={`tnum font-bold ${tone}`} dir="ltr">{impact.change_pct !== null && impact.change_pct !== undefined ? `${impact.change_pct > 0 ? '+' : ''}${fmt(impact.change_pct)}%` : ''}</span>
          <span className={`font-semibold ${tone}`}>{t[impact.verdict ?? 'unclear']}</span>
        </p>
      ) : (
        <p className="text-text-secondary">
          <span className="font-semibold">{impact.state === 'not_measurable' ? t.not_measurable : t.insufficient}</span>
          {reason ? ` — ${reason}` : ''}{impact.days_remaining ? ` (${t.remaining(impact.days_remaining)})` : ''}
        </p>
      )}
    </div>
  )
}

function LogLine({ l, t, ar }: { l: DecisionLogLine; t: (typeof T)['ar']; ar: boolean }) {
  const what = l.kind === 'status' && l.to_status ? pick(STATUS_LABEL[l.to_status as DecisionStatus], ar)
    : l.kind === 'assign' ? (l.to_owner ?? t.unowned) : ''
  return (
    <li className="rounded-lg bg-surface-secondary px-3 py-2 text-xs text-text-secondary">
      <span className="font-bold text-text-primary">{t.kind[l.kind] ?? l.kind}{what ? `: ${what}` : ''}</span>
      {l.action_tag ? ` · ${pick(ACTION_TAG_LABEL[l.action_tag], ar) || l.action_tag}` : ''}
      {l.note ? <span dir="auto" className="block text-text-primary">{l.note}</span> : null}
      <span className="block text-xs text-text-muted">{l.by ? `${t.by} ${l.by} · ` : ''}{fmtDateTime(l.at)}</span>
    </li>
  )
}
