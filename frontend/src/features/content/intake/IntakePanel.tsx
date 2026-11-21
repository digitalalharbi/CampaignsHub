import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, CheckCircle2, Film, FileText, Link2, MinusCircle, Plus, ShieldCheck, Sparkles, XCircle } from 'lucide-react'
import { useSearchParams } from 'react-router-dom'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'
import { StudioDrawer } from '../studio/ui/StudioDrawer'
import { Num } from '@/components/ui/Num'
import { Textarea } from '@/components/ui/Textarea'
import { Input } from '@/components/ui/Input'
import { toApiError } from '@/lib/api/client'
import { fmtDateTime } from '@/lib/datetime'
import { formatFixed, formatNumber } from '@/lib/numerals'
import { platformLabel } from '@/lib/platforms'
import { useAuth } from '@/stores/auth'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'
import { listProjects } from '@/features/projects/api'
import { metricLabel } from '../metrics'
import {
  AI_FIELDS, INTAKE_STATUSES, createIntake, getIntake, intakeFileUrl, listIntake, recheckIntake, transitionIntake, updateIntake,
  type CheckStatus, type IntakeAction, type IntakeInput, type IntakeItem, type IntakeStatus,
} from './api'
import { ACTION, AI_FIELD, AI_STATE, CHECK_NAME, CHECK_STATUS, CHECK_TONE, EVENT, FAMILY, PLACEMENT, STATUS, STATUS_TONE, UI, tr } from './copy'

const REASON_REQUIRED: IntakeAction[] = ['reject', 'request_changes']

const CHECK_ICON: Record<CheckStatus, typeof CheckCircle2> = {
  pass: CheckCircle2, warn: AlertTriangle, fail: XCircle, not_measured: MinusCircle,
}

export function StatusBadge({ status, ar }: { status: IntakeStatus; ar: boolean }) {
  return (
    <span data-testid={`intake-status-${status}`} className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-bold ${STATUS_TONE[status]}`}>
      {tr(STATUS[status], ar)}
    </span>
  )
}

/** The readiness score as a bar: measured checks only, and «not measured» when nothing could be measured. */
function Readiness({ score, ar }: { score: number | null; ar: boolean }) {
  const t = UI[ar ? 'ar' : 'en']
  if (score === null) return <span className="text-xs text-text-muted">{t.noScore}</span>
  const tone = score >= 85 ? 'bg-success' : score >= 60 ? 'bg-warning' : 'bg-danger'
  return (
    <div className="flex items-center gap-2" data-testid="intake-readiness">
      <div className="h-2 w-20 overflow-hidden rounded-full bg-surface-secondary" role="img" aria-label={`${t.readiness} ${score}`}>
        <div className={`h-full rounded-full ${tone}`} style={{ width: `${score}%` }} />
      </div>
      <span className="text-xs font-bold text-text-primary" dir="ltr">{formatNumber(score)}</span>
    </div>
  )
}

function Thumb({ item, className }: { item: IntakeItem; className: string }) {
  const [broken, setBroken] = useState(false)
  if (item.kind === 'image' && item.has_file && !broken) {
    return <img src={intakeFileUrl(item.id)} alt="" loading="lazy" onError={() => setBroken(true)} className={`${className} bg-surface-secondary object-contain`} />
  }
  const Icon = item.kind === 'video' ? Film : item.kind === 'link' ? Link2 : FileText
  return (
    <div className={`${className} flex items-center justify-center bg-surface-secondary text-text-muted`}>
      <Icon className="h-6 w-6" aria-hidden />
    </div>
  )
}

export function IntakePanel() {
  const locale = useUi((s) => s.locale)
  const ar = locale === 'ar'
  const t = UI[ar ? 'ar' : 'en']
  const canView = useAuth((s) => s.hasPermission('content.view'))
  const canCreate = useAuth((s) => s.hasPermission('content.create'))
  const projectId = useProject((s) => s.currentProjectId)
  const [params, setParams] = useSearchParams()
  const [status, setStatus] = useState<string>(() => params.get('status') ?? '')
  const [uploading, setUploading] = useState(false)
  const openId = params.get('item')

  const q = useQuery({ queryKey: ['content-intake', projectId, status], queryFn: () => listIntake({ project: projectId, status }), enabled: canView })

  const setOpen = (id: string | null) => {
    const next = new URLSearchParams(params)
    if (id) next.set('item', id)
    else next.delete('item')
    setParams(next, { replace: true })
  }

  if (!canView) {
    return <p className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-text-secondary">{t.noPermission}</p>
  }

  const data = q.data
  const counts = data?.counts ?? {}
  const sum = INTAKE_STATUSES.reduce((a, s) => a + (counts[s] ?? 0), 0)

  return (
    <div className="flex flex-col gap-4" data-testid="intake-panel">
      <div className="flex flex-wrap items-center gap-2">
        <div role="group" className="flex flex-wrap gap-2">
          <button type="button" onClick={() => setStatus('')} aria-pressed={status === ''} data-testid="intake-chip-all"
            className={`rounded-full border px-3 py-1.5 text-sm font-semibold ${status === '' ? 'border-brand-600 bg-brand-600 text-white' : 'border-border bg-surface text-text-secondary hover:bg-surface-hover'}`}>
            {t.all} <span dir="ltr">({formatNumber(sum)})</span>
          </button>
          {INTAKE_STATUSES.map((s) => (
            <button key={s} type="button" onClick={() => setStatus(status === s ? '' : s)} aria-pressed={status === s} data-testid={`intake-chip-${s}`}
              className={`rounded-full border px-3 py-1.5 text-sm font-semibold ${status === s ? 'border-brand-600 bg-brand-600 text-white' : 'border-border bg-surface text-text-secondary hover:bg-surface-hover'}`}>
              {tr(STATUS[s], ar)} <span dir="ltr">({formatNumber(counts[s] ?? 0)})</span>
            </button>
          ))}
        </div>
        <div className="ms-auto flex items-center gap-2">
          <span data-testid="intake-ai-state" className="inline-flex items-center gap-1 rounded-full bg-surface-secondary px-3 py-1 text-xs font-semibold text-text-muted">
            <Sparkles className="h-3.5 w-3.5" aria-hidden />
            {tr(AI_STATE[data?.aiState ?? 'awaiting_credentials'], ar)}
          </span>
          {canCreate && (
            <Button onClick={() => setUploading(true)} data-testid="intake-upload">
              <Plus className="h-4 w-4" aria-hidden />
              {t.upload}
            </Button>
          )}
        </div>
      </div>

      {q.isLoading ? (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {[0, 1, 2].map((i) => <div key={i} className="h-36 animate-pulse rounded-2xl bg-surface-secondary" />)}
        </div>
      ) : (data?.items.length ?? 0) === 0 ? (
        <p data-testid="intake-empty" className="rounded-2xl border border-dashed border-border p-10 text-center text-sm text-text-secondary">
          {status ? t.emptyFiltered : t.empty}
        </p>
      ) : (
        <>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
            {data?.items.map((item) => (
              <button key={item.id} type="button" onClick={() => setOpen(item.id)} data-testid={`intake-card-${item.id}`}
                className="flex gap-3 rounded-2xl border border-border bg-surface p-3 text-start transition-colors hover:bg-surface-hover">
                <Thumb item={item} className="h-24 w-20 shrink-0 rounded-xl" />
                <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                  <p className="truncate text-sm font-bold text-text-primary">{item.name}</p>
                  <p className="truncate text-xs text-text-muted">
                    {[item.provider ? platformLabel(item.provider, ar) : null, item.objective ? tr(FAMILY[item.objective], ar) : null].filter(Boolean).join(' · ') || '—'}
                  </p>
                  <div className="flex flex-wrap items-center gap-2">
                    <StatusBadge status={item.status} ar={ar} />
                    {item.check_result && (
                      <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${CHECK_TONE[item.check_result]}`}>{tr(CHECK_STATUS[item.check_result], ar)}</span>
                    )}
                  </div>
                  <Readiness score={item.readiness_score} ar={ar} />
                </div>
              </button>
            ))}
          </div>
          {data && data.withheld > 0 && <p className="text-xs text-text-muted">{t.bounded(data.items.length, data.total)}</p>}
        </>
      )}

      {uploading && data && (
        <UploadModal
          ar={ar} platforms={data.platforms} objectives={data.objectives} maxKb={data.maxUploadKb} defaultProject={projectId}
          onClose={() => setUploading(false)}
          onCreated={(item) => { setUploading(false); setOpen(item.id) }}
        />
      )}
      {openId && <DetailModal id={openId} ar={ar} onClose={() => setOpen(null)} />}
    </div>
  )
}

function UploadModal({ ar, platforms, objectives, maxKb, defaultProject, onClose, onCreated }: {
  ar: boolean; platforms: string[]; objectives: string[]; maxKb: number; defaultProject: string | null
  onClose: () => void; onCreated: (item: IntakeItem) => void
}) {
  const t = UI[ar ? 'ar' : 'en']
  const qc = useQueryClient()
  const projects = useQuery({ queryKey: ['projects'], queryFn: () => listProjects(), retry: false })
  const [project, setProject] = useState(defaultProject ?? '')
  const [file, setFile] = useState<File | null>(null)
  const [studio, setStudio] = useState(false)
  const [kind, setKind] = useState<'copy' | 'link'>('copy')
  const [f, setF] = useState<IntakeInput>({})
  const [error, setError] = useState<string | null>(null)
  const maxMb = Math.floor(maxKb / 1024)
  const set = (k: keyof IntakeInput, v: string) => setF((p) => ({ ...p, [k]: v }))

  const m = useMutation({
    mutationFn: () => createIntake({ ...f, project_id: project, file, kind: file ? undefined : kind }),
    onSuccess: (item) => { void qc.invalidateQueries({ queryKey: ['content-intake'] }); onCreated(item) },
    onError: (e) => setError(toApiError(e).message),
  })

  const pick = (next: File | null) => {
    setError(null)
    if (next && next.size > maxKb * 1024) { setFile(null); setError(t.form.tooBig(maxMb)); return }
    setFile(next)
  }
  const label = 'flex flex-col gap-1 text-xs font-semibold text-text-secondary'

  return (
    <Modal open onClose={onClose} title={t.form.title} size="lg"
      footer={<><Button variant="secondary" onClick={onClose}>{t.detail.cancel}</Button><Button loading={m.isPending} disabled={!project || m.isPending} onClick={() => m.mutate()} data-testid="intake-submit">{t.form.submit}</Button></>}>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2" data-testid="intake-form">
        <label className={label}>{t.form.project}
          <select value={project} onChange={(e) => setProject(e.target.value)} className="rounded-xl border border-border bg-surface px-3 py-2 text-sm text-text-primary" data-testid="intake-project">
            <option value="">{t.form.none}</option>
            {(projects.data ?? []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </select>
        </label>
        <label className={label}>{t.form.name}<Input value={f.name ?? ''} onChange={(e) => set('name', e.target.value)} /></label>
        <label className={`${label} sm:col-span-2`}>{t.form.file}
          <input type="file" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm" onChange={(e) => pick(e.target.files?.[0] ?? null)} data-testid="intake-file"
            className="rounded-xl border border-dashed border-border bg-surface px-3 py-2 text-sm text-text-primary" />
          <span className="text-xs font-normal text-text-muted">{t.form.limit(maxMb)}</span>
          {file && file.type.startsWith('image/') && (
            // CONTENT-STUDIO-001 — an image can be opened in the studio before it is uploaded: 3 videos + 4 images from this one picture.
            <Button type="button" variant="secondary" size="sm" onClick={() => setStudio(true)} data-testid="intake-open-studio">{ar ? 'افتح الاستوديو' : 'Open the studio'}</Button>
          )}
        </label>
        {studio && file && <StudioDrawer file={file} onClose={() => setStudio(false)} />}
        {!file && (
          <label className={label}>{t.form.kind}
            <select value={kind} onChange={(e) => setKind(e.target.value as 'copy' | 'link')} className="rounded-xl border border-border bg-surface px-3 py-2 text-sm text-text-primary">
              <option value="copy">{t.form.kindCopy}</option>
              <option value="link">{t.form.kindLink}</option>
            </select>
          </label>
        )}
        <label className={label}>{t.form.platform}
          <select value={f.provider ?? ''} onChange={(e) => set('provider', e.target.value)} className="rounded-xl border border-border bg-surface px-3 py-2 text-sm text-text-primary" data-testid="intake-platform">
            <option value="">{t.form.none}</option>
            {platforms.map((p) => <option key={p} value={p}>{platformLabel(p, ar)}</option>)}
          </select>
        </label>
        <label className={label}>{t.form.objective}
          <select value={f.objective ?? ''} onChange={(e) => set('objective', e.target.value)} className="rounded-xl border border-border bg-surface px-3 py-2 text-sm text-text-primary" data-testid="intake-objective">
            <option value="">{t.form.none}</option>
            {objectives.map((o) => <option key={o} value={o}>{tr(FAMILY[o], ar, o)}</option>)}
          </select>
        </label>
        <label className={label}>{t.form.placement}
          <select value={f.placement ?? ''} onChange={(e) => set('placement', e.target.value)} className="rounded-xl border border-border bg-surface px-3 py-2 text-sm text-text-primary">
            <option value="">{t.form.none}</option>
            {Object.keys(PLACEMENT).map((p) => <option key={p} value={p}>{tr(PLACEMENT[p], ar)}</option>)}
          </select>
        </label>
        <label className={label}>{t.form.headline}<Input value={f.headline ?? ''} onChange={(e) => set('headline', e.target.value)} /></label>
        <label className={label}>{t.form.cta}<Input value={f.cta ?? ''} onChange={(e) => set('cta', e.target.value)} /></label>
        <label className={`${label} sm:col-span-2`}>{t.form.copy}<Textarea rows={3} value={f.copy_text ?? ''} onChange={(e) => set('copy_text', e.target.value)} /></label>
        <label className={`${label} sm:col-span-2`}>{t.form.url}<Input dir="ltr" value={f.destination_url ?? ''} onChange={(e) => set('destination_url', e.target.value)} placeholder="https://" /></label>
      </div>
      {error && <p role="alert" className="mt-3 rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger" data-testid="intake-error">{error}</p>}
    </Modal>
  )
}

function DetailModal({ id, ar, onClose }: { id: string; ar: boolean; onClose: () => void }) {
  const t = UI[ar ? 'ar' : 'en']
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['content-intake-item', id], queryFn: () => getIntake(id) })
  const [pending, setPending] = useState<IntakeAction | null>(null)
  const [reason, setReason] = useState('')
  const [notes, setNotes] = useState('')
  const [creativeId, setCreativeId] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [editing, setEditing] = useState(false)
  const item = q.data

  useEffect(() => { setError(null) }, [pending])

  const refresh = () => { void qc.invalidateQueries({ queryKey: ['content-intake'] }); void qc.invalidateQueries({ queryKey: ['content-intake-item', id] }); void qc.invalidateQueries({ queryKey: ['content-follow-up'] }) }

  const move = useMutation({
    mutationFn: (action: IntakeAction) => transitionIntake(id, action, { reason: reason.trim() || undefined, notes: notes.trim() || undefined, creative_id: creativeId.trim() || undefined }),
    onSuccess: () => { setPending(null); setReason(''); setNotes(''); setCreativeId(''); refresh() },
    onError: (e) => setError(toApiError(e).message),
  })
  const recheck = useMutation({ mutationFn: () => recheckIntake(id), onSuccess: refresh, onError: (e) => setError(toApiError(e).message) })

  const confirm = (action: IntakeAction) => {
    if (REASON_REQUIRED.includes(action) && reason.trim() === '') { setError(t.detail.reasonRequired); return }
    move.mutate(action)
  }

  const needsForm = (action: IntakeAction) => REASON_REQUIRED.includes(action) || action === 'approve' || action === 'mark_live'
  const run = (action: IntakeAction) => {
    if (needsForm(action)) { setPending(action); return }
    move.mutate(action)
  }

  return (
    <Modal open onClose={onClose} title={item?.name ?? ''} size="xl">
      {!item ? (
        <div className="h-40 animate-pulse rounded-2xl bg-surface-secondary" />
      ) : editing ? (
        <EditForm item={item} ar={ar} onDone={() => { setEditing(false); refresh() }} />
      ) : (
        <div className="grid grid-cols-1 gap-5 lg:grid-cols-[260px_1fr]" data-testid="intake-detail">
          <div className="flex flex-col gap-3">
            <Thumb item={item} className="aspect-[3/4] w-full rounded-2xl" />
            <div className="flex flex-wrap items-center gap-2">
              <StatusBadge status={item.status} ar={ar} />
              <Readiness score={item.readiness_score} ar={ar} />
            </div>
            <dl className="grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
              {item.provider && <><dt className="text-text-muted">{UI[ar ? 'ar' : 'en'].form.platform}</dt><dd className="font-semibold text-text-primary">{platformLabel(item.provider, ar)}</dd></>}
              {item.objective && <><dt className="text-text-muted">{t.form.objective}</dt><dd className="font-semibold text-text-primary">{tr(FAMILY[item.objective], ar)}</dd></>}
              {item.placement && <><dt className="text-text-muted">{t.form.placement}</dt><dd className="font-semibold text-text-primary">{tr(PLACEMENT[item.placement], ar)}</dd></>}
              {item.width && item.height && <><dt className="text-text-muted">{ar ? 'الأبعاد' : 'Size'}</dt><dd className="font-semibold text-text-primary"><Num>{item.width}x{item.height}</Num></dd></>}
              {item.duration_ms !== null && <><dt className="text-text-muted">{ar ? 'المدة' : 'Duration'}</dt><dd className="font-semibold text-text-primary"><Num>{formatFixed(item.duration_ms / 1000, 1)} s</Num></dd></>}
              {item.size_bytes !== null && <><dt className="text-text-muted">{ar ? 'الحجم' : 'Weight'}</dt><dd className="font-semibold text-text-primary"><Num>{formatFixed(item.size_bytes / 1048576, 2)} MB</Num></dd></>}
            </dl>
            {item.source === 'drive' && <p className="rounded-lg bg-surface-secondary px-3 py-2 text-xs text-text-secondary">{t.detail.driveNote}</p>}
            {(item.headline || item.copy_text || item.cta || item.destination_url) && (
              <div className="rounded-xl border border-border p-3 text-xs text-text-secondary">
                {item.headline && <p className="font-bold text-text-primary">{item.headline}</p>}
                {item.copy_text && <p className="mt-1 whitespace-pre-wrap">{item.copy_text}</p>}
                {item.cta && <p className="mt-1 text-text-muted">{item.cta}</p>}
                {item.destination_url && <p className="mt-1 break-all text-text-muted" dir="ltr">{item.destination_url}</p>}
              </div>
            )}
          </div>

          <div className="flex min-w-0 flex-col gap-5">
            {item.decision_reason && (
              <div className="rounded-xl border border-border bg-surface-secondary p-3 text-sm" data-testid="intake-decision">
                <p className="font-bold text-text-primary">{t.detail.decision}: {tr(STATUS[item.status], ar)} {item.decider_name ? `${t.detail.by} ${item.decider_name}` : ''}</p>
                <p className="mt-1 text-text-secondary">{t.detail.reason}: {item.decision_reason}</p>
                {item.decision_notes && <p className="mt-1 text-text-secondary">{t.detail.notes}: {item.decision_notes}</p>}
              </div>
            )}

            <Section title={t.detail.checks} aside={item.analysis ? t.detail.coverage(item.analysis.coverage.measured, item.analysis.coverage.total) : undefined}>
              <ul className="flex flex-col gap-1.5" data-testid="intake-checks">
                {(item.checks ?? []).map((c) => {
                  const Icon = CHECK_ICON[c.status]
                  return (
                    <li key={c.key} data-testid={`intake-check-${c.key}`} className="flex items-start gap-2 text-sm">
                      <span className={`mt-0.5 inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-xs font-bold ${CHECK_TONE[c.status]}`}>
                        <Icon className="h-3 w-3" aria-hidden />{tr(CHECK_STATUS[c.status], ar)}
                      </span>
                      <span><b className="text-text-primary">{tr(CHECK_NAME[c.key], ar, c.key)}</b> <span className="text-text-secondary">{ar ? c.reason_ar : c.reason_en}</span></span>
                    </li>
                  )
                })}
              </ul>
            </Section>

            <Section title={t.detail.recs}>
              {(item.analysis?.recommendations.length ?? 0) === 0 ? (
                <p className="text-sm text-text-muted">{t.detail.noRecs}</p>
              ) : (
                <ul className="flex flex-col gap-1.5" data-testid="intake-recs">
                  {item.analysis?.recommendations.map((r) => (
                    <li key={r.key} className="flex items-start gap-2 text-sm text-text-secondary">
                      <ShieldCheck className={`mt-0.5 h-4 w-4 shrink-0 ${r.severity === 'must' ? 'text-danger' : r.severity === 'should' ? 'text-warning' : 'text-info'}`} aria-hidden />
                      {ar ? r.fact_ar : r.fact_en}
                    </li>
                  ))}
                </ul>
              )}
            </Section>

            <Section title={t.detail.history}><HistoryBlock item={item} ar={ar} /></Section>

            {item.status === 'live' && (
              <Section title={t.detail.after}><AfterBlock item={item} ar={ar} /></Section>
            )}

            <Section title={t.detail.ai}>
              <p className="mb-2 text-xs text-text-muted" data-testid="intake-ai-note">{tr(AI_STATE[item.ai?.state ?? 'awaiting_credentials'], ar)}</p>
              <dl className="grid grid-cols-1 gap-2 sm:grid-cols-2" data-testid="intake-ai">
                {AI_FIELDS.map((k) => (
                  <div key={k} className="rounded-xl border border-border px-3 py-2">
                    <dt className="text-xs text-text-muted">{tr(AI_FIELD[k], ar)}</dt>
                    <dd className="text-sm font-semibold text-text-muted">{item.ai?.fields[k] ?? (ar ? 'غير مفعّل' : 'Not enabled')}</dd>
                  </div>
                ))}
              </dl>
            </Section>

            <Section title={t.detail.trail}>
              <ol className="flex flex-col gap-2 border-s border-border ps-4" data-testid="intake-trail">
                {(item.events ?? []).map((e) => (
                  <li key={e.id} className="text-sm">
                    <p className="font-semibold text-text-primary">
                      {tr(EVENT[e.action], ar, e.action)} {e.actor && <span className="font-normal text-text-muted">{t.detail.by} {e.actor}</span>}
                    </p>
                    <p className="text-xs text-text-muted" dir="ltr">{fmtDateTime(e.at)}</p>
                    {e.reason && <p className="text-xs text-text-secondary">{t.detail.reason}: {e.reason}</p>}
                    {e.notes && <p className="text-xs text-text-secondary">{t.detail.notes}: {e.notes}</p>}
                  </li>
                ))}
              </ol>
            </Section>

            {pending && (
              <div className="flex flex-col gap-2 rounded-xl border border-border p-3" data-testid="intake-decision-form">
                {(pending === 'reject' || pending === 'request_changes') && (
                  <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">{t.detail.reason}
                    <Textarea rows={2} value={reason} onChange={(e) => setReason(e.target.value)} data-testid="intake-reason" />
                  </label>
                )}
                <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">{t.detail.notes}
                  <Textarea rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} data-testid="intake-notes" />
                </label>
                {pending === 'mark_live' && (
                  <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">{t.detail.creativeId}
                    <Input dir="ltr" value={creativeId} onChange={(e) => setCreativeId(e.target.value)} />
                  </label>
                )}
                <div className="flex gap-2">
                  <Button loading={move.isPending} onClick={() => confirm(pending)} data-testid="intake-confirm">{tr(ACTION[pending], ar)}</Button>
                  <Button variant="secondary" onClick={() => setPending(null)}>{t.detail.cancel}</Button>
                </div>
              </div>
            )}
            {error && <p role="alert" className="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger" data-testid="intake-error">{error}</p>}

            <div className="flex flex-wrap gap-2 border-t border-border pt-3">
              {item.available_actions.map((a) => (
                <Button key={a} variant={a === 'reject' ? 'danger' : a === 'approve' || a === 'submit' ? 'primary' : 'secondary'} loading={move.isPending && pending === null}
                  onClick={() => run(a)} data-testid={`intake-action-${a}`}>
                  {tr(ACTION[a], ar)}
                </Button>
              ))}
              {item.can_edit && <Button variant="secondary" onClick={() => setEditing(true)} data-testid="intake-edit">{t.detail.edit}</Button>}
              {(item.can_edit || item.status === 'in_review') && <Button variant="ghost" loading={recheck.isPending} onClick={() => recheck.mutate()} data-testid="intake-recheck">{t.detail.recheck}</Button>}
            </div>
          </div>
        </div>
      )}
    </Modal>
  )
}

function Section({ title, aside, children }: { title: string; aside?: string; children: React.ReactNode }) {
  return (
    <section>
      <div className="mb-2 flex items-baseline gap-2">
        <h3 className="text-sm font-bold text-text-primary">{title}</h3>
        {aside && <span className="text-xs text-text-muted">{aside}</span>}
      </div>
      {children}
    </section>
  )
}

function HistoryBlock({ item, ar }: { item: IntakeItem; ar: boolean }) {
  const t = UI[ar ? 'ar' : 'en']
  const h = item.analysis?.history
  if (!h) return <p className="text-sm text-text-muted">{t.history.none}</p>
  if (h.state === 'no_target') return <p className="text-sm text-text-muted">{t.history.none}</p>
  if (h.state === 'not_reported') return <p className="text-sm text-text-muted">{t.history.notReported}</p>
  if (h.state === 'insufficient') return <p className="text-sm text-text-muted" data-testid="intake-history">{t.history.insufficient(h.peers ?? 0, h.needed ?? 0)}</p>
  const metric = h.metric ? metricLabel(h.metric, ar ? 'ar' : 'en') : ''
  return (
    <div className="flex flex-col gap-2 text-sm text-text-secondary" data-testid="intake-history">
      {h.fit_percent !== undefined && h.format && (
        <>
          <div className="h-2 w-full overflow-hidden rounded-full bg-surface-secondary" role="img" aria-label={`${h.fit_percent}%`}>
            <div className="h-full rounded-full bg-brand-600" style={{ width: `${h.fit_percent}%` }} />
          </div>
          <p>{t.history.fit(h.fit_percent, h.top_quartile_same_format ?? 0, h.top_quartile_count ?? 0, tr({ ar: h.format === 'video' ? 'فيديو' : 'صورة', en: h.format }, ar))}</p>
        </>
      )}
      {h.best && <p>{ar ? 'الأفضل' : 'Best'}: {h.best.name} · {metric} <span dir="ltr">{formatFixed(h.best.value, 2)}</span> ({formatNumber(h.peers ?? 0)} {ar ? 'محتوى مقاس' : 'measured'}, {formatNumber(h.window_days ?? 0)} {ar ? 'يوم' : 'days'})</p>}
    </div>
  )
}

function AfterBlock({ item, ar }: { item: IntakeItem; ar: boolean }) {
  const t = UI[ar ? 'ar' : 'en']
  const a = item.after_launch
  if (!a || a.state === 'not_linked' || a.state === 'no_target') return <p className="text-sm text-text-muted" data-testid="intake-after">{t.after.none}</p>
  if (a.state === 'no_delivery' || a.state === 'not_reported') return <p className="text-sm text-text-muted" data-testid="intake-after">{t.after.no_delivery}</p>
  if (a.state !== 'ok' || a.value === undefined) return <p className="text-sm text-text-muted" data-testid="intake-after">{t.after.insufficient}</p>
  const delta = a.delta_percent === null || a.delta_percent === undefined ? '—' : `${a.delta_percent > 0 ? '+' : ''}${formatFixed(a.delta_percent, 1)}%`
  return (
    <div className="text-sm text-text-secondary" data-testid="intake-after">
      <p>{metricLabel(a.metric ?? '', ar ? 'ar' : 'en')}: {t.after.vs(formatFixed(a.value, 2), a.average === null || a.average === undefined ? '—' : formatFixed(a.average, 2), delta)}</p>
      {a.rank && a.ranked && <p className="text-xs text-text-muted">{t.after.rank(a.rank, a.ranked)}</p>}
    </div>
  )
}

function EditForm({ item, ar, onDone }: { item: IntakeItem; ar: boolean; onDone: () => void }) {
  const t = UI[ar ? 'ar' : 'en']
  const list = useQuery({ queryKey: ['content-intake', 'form-options'], queryFn: () => listIntake({}) })
  const [f, setF] = useState<IntakeInput>({
    name: item.name, provider: item.provider ?? '', objective: item.objective ?? '', placement: item.placement ?? '', headline: item.headline ?? '',
    cta: item.cta ?? '', copy_text: item.copy_text ?? '', destination_url: item.destination_url ?? '',
  })
  const [file, setFile] = useState<File | null>(null)
  const [error, setError] = useState<string | null>(null)
  const set = (k: keyof IntakeInput, v: string) => setF((p) => ({ ...p, [k]: v }))
  const m = useMutation({ mutationFn: () => updateIntake(item.id, { ...f, file }), onSuccess: onDone, onError: (e) => setError(toApiError(e).message) })
  const platforms = useMemo(() => list.data?.platforms ?? [], [list.data])
  const label = 'flex flex-col gap-1 text-xs font-semibold text-text-secondary'
  return (
    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2" data-testid="intake-edit-form">
      <label className={label}>{t.form.name}<Input value={f.name ?? ''} onChange={(e) => set('name', e.target.value)} /></label>
      <label className={label}>{t.form.platform}
        <select value={f.provider ?? ''} onChange={(e) => set('provider', e.target.value)} className="rounded-xl border border-border bg-surface px-3 py-2 text-sm text-text-primary">
          <option value="">{t.form.none}</option>
          {platforms.map((p) => <option key={p} value={p}>{platformLabel(p, ar)}</option>)}
        </select>
      </label>
      <label className={label}>{t.form.objective}
        <select value={f.objective ?? ''} onChange={(e) => set('objective', e.target.value)} className="rounded-xl border border-border bg-surface px-3 py-2 text-sm text-text-primary">
          <option value="">{t.form.none}</option>
          {(list.data?.objectives ?? []).map((o) => <option key={o} value={o}>{tr(FAMILY[o], ar, o)}</option>)}
        </select>
      </label>
      <label className={label}>{t.form.placement}
        <select value={f.placement ?? ''} onChange={(e) => set('placement', e.target.value)} className="rounded-xl border border-border bg-surface px-3 py-2 text-sm text-text-primary">
          <option value="">{t.form.none}</option>
          {Object.keys(PLACEMENT).map((p) => <option key={p} value={p}>{tr(PLACEMENT[p], ar)}</option>)}
        </select>
      </label>
      <label className={label}>{t.form.headline}<Input value={f.headline ?? ''} onChange={(e) => set('headline', e.target.value)} /></label>
      <label className={label}>{t.form.cta}<Input value={f.cta ?? ''} onChange={(e) => set('cta', e.target.value)} /></label>
      <label className={`${label} sm:col-span-2`}>{t.form.copy}<Textarea rows={3} value={f.copy_text ?? ''} onChange={(e) => set('copy_text', e.target.value)} /></label>
      <label className={`${label} sm:col-span-2`}>{t.form.url}<Input dir="ltr" value={f.destination_url ?? ''} onChange={(e) => set('destination_url', e.target.value)} /></label>
      {item.kind !== 'copy' && item.kind !== 'link' && (
        <label className={`${label} sm:col-span-2`}>{t.form.file}
          <input type="file" onChange={(e) => setFile(e.target.files?.[0] ?? null)} className="rounded-xl border border-dashed border-border bg-surface px-3 py-2 text-sm" />
        </label>
      )}
      {error && <p role="alert" className="text-sm text-danger sm:col-span-2">{error}</p>}
      <div className="flex gap-2 sm:col-span-2">
        <Button loading={m.isPending} onClick={() => m.mutate()} data-testid="intake-save">{t.detail.save}</Button>
        <Button variant="secondary" onClick={onDone}>{t.detail.cancel}</Button>
      </div>
    </div>
  )
}

