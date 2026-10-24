import { useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, ClipboardCopy, EyeOff, FileUp, Loader2, Pencil, Send, X } from 'lucide-react'
import { DeliverySettingsCard } from './DeliverySettingsCard'
import { PackNotesEditor } from './PackNotesEditor'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { Num } from '@/components/ui/Num'
import { ProviderMark } from '@/components/ProviderMark'
import { PLATFORM_ORDER } from '@/lib/platforms'
import { providerLabel } from '@/features/campaigns/labels'
import { listInvoices } from '@/features/billing/api'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'
import {
  COMPONENT_KEYS, approvePack, attachInvoice, deleteEvidence, editRecommendation, evidenceFileUrl, getPack, listPacks, markPackSent,
  preparePack, setComponents, setRecommendationStatus, uploadEvidence, whatsappText,
  type DeliveryPack, type PackDetail, type PackRecommendation,
} from './deliveryApi'

/**
 * WD-PACK-001, WD-DRAFTS-001, WD-WHATSAPP-001, WD-EVIDENCE-001 — the periodic delivery, as the Owner works it.
 *
 * One list of weeks and one panel for the week in front of them: what stands between it and approval
 * (the report, every recommendation's decision, evidence, the invoice), the choices that make it theirs
 * (edit a draft in their own words, attach a screenshot, pick which pieces this client receives), and — only
 * once approved — the message to paste into WhatsApp. Nothing here computes a figure; it shows the report
 * engine's and asks the Owner to decide.
 */
const COPY = {
  ar: {
    title: 'التسليم الدوري', prepare: 'تجهيز الأسبوع الماضي', none: 'لا توجد حزم تسليم بعد. جهّز الأسبوع الماضي لتبدأ.',
    week: 'الأسبوع', draft: 'مسودة', approved: 'معتمدة', sent: 'أُرسلت', ready: 'جاهزة للاعتماد',
    checklist: 'قبل الاعتماد', report: 'التقرير', reportStates: { completed: 'جاهز', processing: 'قيد الإعداد', failed: 'فشل', missing: 'غير موجود' } as Record<string, string>,
    undecided: 'توصيات بلا قرار', evidenceCount: 'لقطات منصات', invoice: 'الفاتورة', invoiceNone: 'غير مرتبطة',
    recs: 'الملاحظات والتوصيات', recsHint: 'هذه مسودات من النظام. لا تصل العميل إلا ما تعتمده.',
    approve: 'اعتماد', hide: 'إخفاء', reject: 'رفض', edit: 'تعديل', save: 'حفظ', cancel: 'إلغاء',
    status: { draft: 'مسودة', reviewed: 'روجعت', approved: 'معتمدة', hidden: 'مخفية', rejected: 'مرفوضة' } as Record<string, string>,
    edited: 'معدّلة',
    evidence: 'لقطات المنصات', drop: 'اسحب لقطة هنا أو اضغط للاختيار', provider: 'المنصة', note: 'ملاحظة (اختياري)', remove: 'حذف',
    pieces: 'ما يستلمه العميل',
    pieceNames: { whatsapp: 'ملخص واتساب', link: 'رابط التقرير', report_pdf: 'تقرير PDF', evidence: 'لقطات المنصات', invoice_pdf: 'فاتورة PDF', amount_due: 'المبلغ المستحق', spend: 'الإنفاق في الرسالة' } as Record<string, string>,
    attach: 'ربط فاتورة', pickInvoice: 'اختر فاتورة',
    approveAll: 'اعتماد الحزمة', approving: 'جارٍ الاعتماد…', blocked: 'لا يمكن الاعتماد بعد',
    wa: 'رسالة واتساب', copy: 'نسخ', copied: 'تم النسخ', markSent: 'تم الإرسال للعميل', 
  },
  en: {
    title: 'Periodic delivery', prepare: 'Prepare last week', none: 'No delivery packs yet. Prepare last week to begin.',
    week: 'Week', draft: 'Draft', approved: 'Approved', sent: 'Sent', ready: 'Ready to approve',
    checklist: 'Before approval', report: 'Report', reportStates: { completed: 'Ready', processing: 'Building', failed: 'Failed', missing: 'Missing' } as Record<string, string>,
    undecided: 'Recommendations without a decision', evidenceCount: 'Platform screenshots', invoice: 'Invoice', invoiceNone: 'Not linked',
    recs: 'Observations and recommendations', recsHint: 'These are drafts from the system. The client receives only what you approve.',
    approve: 'Approve', hide: 'Hide', reject: 'Reject', edit: 'Edit', save: 'Save', cancel: 'Cancel',
    status: { draft: 'Draft', reviewed: 'Reviewed', approved: 'Approved', hidden: 'Hidden', rejected: 'Rejected' } as Record<string, string>,
    edited: 'Edited',
    evidence: 'Platform evidence', drop: 'Drop a screenshot here or click to choose', provider: 'Platform', note: 'Note (optional)', remove: 'Remove',
    pieces: 'What the client receives',
    pieceNames: { whatsapp: 'WhatsApp summary', link: 'Report link', report_pdf: 'Report PDF', evidence: 'Platform evidence', invoice_pdf: 'Invoice PDF', amount_due: 'Amount due', spend: 'Spend in the message' } as Record<string, string>,
    attach: 'Link an invoice', pickInvoice: 'Choose an invoice',
    approveAll: 'Approve the pack', approving: 'Approving…', blocked: 'Cannot approve yet',
    wa: 'WhatsApp message', copy: 'Copy', copied: 'Copied', markSent: 'Mark as sent to the client',
  },
}

type Project = { id: string; name: string }

export function TabDelivery({ clientId, projects }: { clientId: string; projects: Project[] }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const t = ar ? COPY.ar : COPY.en
  const qc = useQueryClient()
  const [selected, setSelected] = useState<string | null>(null)
  const [projectId, setProjectId] = useState(projects[0]?.id ?? '')
  const packs = useQuery({ queryKey: ['delivery', 'packs', clientId], queryFn: () => listPacks(clientId) })
  const prepare = useMutation({
    mutationFn: () => preparePack(projectId),
    onSuccess: (p) => { void qc.invalidateQueries({ queryKey: ['delivery', 'packs', clientId] }); setSelected(p.id) },
  })

  if (packs.isLoading) return <div className="h-40 animate-pulse rounded-xl bg-surface-secondary" />
  if (packs.isError) return <QueryFailure error={packs.error} ar={ar} testId="delivery-failure" onRetry={() => void packs.refetch()} fallbackTitle={ar ? 'تعذّر تحميل التسليم الدوري.' : 'Could not load the periodic delivery.'} />

  const rows = packs.data ?? []

  return (
    <div data-testid="delivery-tab" className="space-y-4 text-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="font-heading text-base font-bold text-text-primary">{t.title}</h3>
        <div className="flex items-center gap-2">
          {projects.length > 1 && (
            <select value={projectId} onChange={(e) => setProjectId(e.target.value)} aria-label="project" className="rounded-lg border border-border bg-surface px-2 py-2 text-xs">
              {projects.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
          )}
          <button type="button" data-testid="delivery-prepare" disabled={prepare.isPending || projectId === ''} onClick={() => prepare.mutate()} className="flex items-center gap-1.5 rounded-lg bg-brand-primary px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">
            {prepare.isPending && <Loader2 size={13} className="animate-spin" />} {t.prepare}
          </button>
        </div>
      </div>
      {prepare.isError && <p role="alert" className="text-xs text-danger">{toApiError(prepare.error).message}</p>}
      <DeliverySettingsCard clientId={clientId} ar={ar} canEdit />

      {rows.length === 0 ? (
        <p className="rounded-xl border border-dashed border-border p-8 text-center text-text-secondary">{t.none}</p>
      ) : (
        <ul className="grid gap-2 sm:grid-cols-2">
          {rows.map((p) => <PackCard key={p.id} pack={p} t={t} active={selected === p.id} onOpen={() => setSelected(p.id)} />)}
        </ul>
      )}

      {selected && <PackPanel packId={selected} clientId={clientId} t={t} ar={ar} />}
    </div>
  )
}

function PackCard({ pack, t, active, onOpen }: { pack: DeliveryPack; t: typeof COPY.ar; active: boolean; onOpen: () => void }) {
  const tone = pack.status === 'sent' ? 'bg-success/15 text-success' : pack.status === 'approved' ? 'bg-brand-primary-soft text-brand-700' : 'bg-surface-secondary text-text-secondary'

  return (
    <li>
      <button type="button" onClick={onOpen} data-testid={`pack-${pack.id}`} className={`w-full rounded-xl border p-3 text-start hover:border-brand-400 ${active ? 'border-brand-500' : 'border-border'}`}>
        <div className="flex items-center justify-between gap-2">
          <span className="text-xs text-text-muted">{t.week}</span>
          <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${tone}`}>{pack.status === 'draft' ? (pack.readiness.ready_to_approve ? t.ready : t.draft) : pack.status === 'approved' ? t.approved : t.sent}</span>
        </div>
        <div className="mt-1 font-semibold text-text-primary" dir="ltr"><Num>{pack.period.from} → {pack.period.to}</Num></div>
      </button>
    </li>
  )
}

function PackPanel({ packId, clientId, t, ar }: { packId: string; clientId: string; t: typeof COPY.ar; ar: boolean }) {
  const qc = useQueryClient()
  const pack = useQuery({ queryKey: ['delivery', 'pack', packId], queryFn: () => getPack(packId) })
  const refresh = () => { void qc.invalidateQueries({ queryKey: ['delivery', 'pack', packId] }); void qc.invalidateQueries({ queryKey: ['delivery', 'packs', clientId] }) }
  const approve = useMutation({ mutationFn: () => approvePack(packId), onSuccess: refresh })
  const sent = useMutation({ mutationFn: () => markPackSent(packId), onSuccess: refresh })

  if (pack.isLoading) return <div className="h-32 animate-pulse rounded-xl bg-surface-secondary" />
  if (pack.isError || !pack.data) return <QueryFailure error={pack.error} ar={ar} testId="pack-failure" onRetry={() => void pack.refetch()} fallbackTitle={ar ? 'تعذّر فتح الحزمة.' : 'Could not open the pack.'} />

  const p = pack.data
  const r = p.readiness
  const open = p.status !== 'sent'

  return (
    <section data-testid="pack-panel" className="space-y-5 rounded-2xl border border-border bg-surface p-4">
      <div>
        <h4 className="mb-2 font-bold text-text-primary">{t.checklist}</h4>
        <ul className="grid gap-2 sm:grid-cols-4">
          <Check1 label={t.report} value={t.reportStates[r.report] ?? r.report} ok={r.report === 'completed'} />
          <Check1 label={t.undecided} value={String(r.undecided_recommendations)} ok={r.undecided_recommendations === 0} testId="check-undecided" />
          <Check1 label={t.evidenceCount} value={String(r.evidence.count)} ok={!r.evidence.included || r.evidence.count > 0} />
          <Check1 label={t.invoice} value={r.invoice.status === 'none' ? t.invoiceNone : r.invoice.status} ok={!r.invoice.included || r.invoice.status !== 'none'} />
        </ul>
      </div>

      <Recommendations pack={p} t={t} open={open} onChange={refresh} />
      <PackNotesEditor packId={p.id} notes={p.notes ?? []} ar={ar} open={open} onChange={refresh} />
      <Evidence pack={p} clientId={clientId} t={t} ar={ar} open={open} onChange={refresh} />
      <Pieces pack={p} t={t} open={open} onChange={refresh} />
      <InvoiceLink pack={p} clientId={clientId} t={t} open={open} onChange={refresh} />

      <div className="flex flex-wrap items-center gap-2 border-t border-border pt-4">
        {p.status === 'draft' && (
          <button type="button" data-testid="pack-approve" disabled={!r.ready_to_approve || approve.isPending} onClick={() => approve.mutate()} className="flex items-center gap-1.5 rounded-lg bg-brand-primary px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">
            {approve.isPending ? <Loader2 size={13} className="animate-spin" /> : <Check size={13} />} {approve.isPending ? t.approving : t.approveAll}
          </button>
        )}
        {p.status === 'draft' && !r.ready_to_approve && <span className="text-xs text-text-muted">{t.blocked}</span>}
        {approve.isError && <p role="alert" className="text-xs text-danger">{toApiError(approve.error).message}</p>}
        {p.status === 'approved' && (
          <button type="button" data-testid="pack-sent" disabled={sent.isPending} onClick={() => sent.mutate()} className="flex items-center gap-1.5 rounded-lg border border-border px-3 py-2 text-xs font-semibold text-text-primary">
            <Send size={13} /> {t.markSent}
          </button>
        )}
      </div>

      {p.status !== 'draft' && <WhatsApp packId={p.id} t={t} ar={ar} />}
    </section>
  )
}

function Check1({ label, value, ok, testId }: { label: string; value: string; ok: boolean; testId?: string }) {
  return (
    <li data-testid={testId} className={`rounded-xl border p-3 ${ok ? 'border-border' : 'border-warning/50 bg-warning/5'}`}>
      <div className="text-[11px] text-text-muted">{label}</div>
      <div className="mt-0.5 flex items-center gap-1.5 font-bold text-text-primary">
        <span className={`inline-block h-2 w-2 rounded-full ${ok ? 'bg-success' : 'bg-warning'}`} aria-hidden />
        <Num>{value}</Num>
      </div>
    </li>
  )
}

function Recommendations({ pack, t, open, onChange }: { pack: PackDetail; t: typeof COPY.ar; open: boolean; onChange: () => void }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const [editing, setEditing] = useState<string | null>(null)
  const [draft, setDraft] = useState('')
  const decide = useMutation({ mutationFn: (v: { id: string; status: PackRecommendation['status'] }) => setRecommendationStatus(pack.project_id, pack.report_id ?? '', v.id, v.status), onSuccess: onChange })
  const save = useMutation({ mutationFn: (id: string) => editRecommendation(pack.id, id, ar ? { text_ar: draft } : { text_en: draft }), onSuccess: () => { setEditing(null); onChange() } })

  return (
    <div>
      <h4 className="font-bold text-text-primary">{t.recs}</h4>
      <p className="mb-2 text-xs text-text-muted">{t.recsHint}</p>
      <ul className="space-y-2">
        {pack.recommendations.map((rec) => {
          const text = (ar ? rec.text_ar : rec.text_en) || rec.text_ar || rec.text_en || ''
          return (
            <li key={rec.id} data-testid={`rec-${rec.id}`} className="rounded-xl border border-border p-3">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                  {editing === rec.id ? (
                    <textarea value={draft} onChange={(e) => setDraft(e.target.value)} rows={3} className="w-full rounded-lg border border-border bg-surface p-2 text-sm" aria-label={t.edit} />
                  ) : <p className="text-text-primary">{text}</p>}
                  {rec.proposed_action && editing !== rec.id && <p className="mt-1 text-xs text-text-secondary">{rec.proposed_action}</p>}
                </div>
                <span className="flex shrink-0 items-center gap-1.5">
                  {rec.source === 'edited' && <span className="rounded-full bg-surface-secondary px-2 py-0.5 text-[10px] font-semibold text-text-secondary">{t.edited}</span>}
                  <span data-testid={`rec-status-${rec.id}`} className="rounded-full bg-surface-secondary px-2 py-0.5 text-[10px] font-semibold text-text-secondary">{t.status[rec.status] ?? rec.status}</span>
                </span>
              </div>
              {open && (
                <div className="mt-2 flex flex-wrap gap-1.5">
                  {editing === rec.id ? (
                    <>
                      <button type="button" onClick={() => save.mutate(rec.id)} disabled={save.isPending || draft.trim() === ''} className="rounded-lg bg-brand-primary px-2.5 py-1 text-xs font-semibold text-white disabled:opacity-50">{t.save}</button>
                      <button type="button" onClick={() => setEditing(null)} className="rounded-lg border border-border px-2.5 py-1 text-xs font-semibold">{t.cancel}</button>
                    </>
                  ) : (
                    <>
                      <button type="button" onClick={() => decide.mutate({ id: rec.id, status: 'approved' })} disabled={rec.status === 'approved' || decide.isPending} className="flex items-center gap-1 rounded-lg border border-border px-2.5 py-1 text-xs font-semibold disabled:opacity-40"><Check size={12} /> {t.approve}</button>
                      <button type="button" onClick={() => decide.mutate({ id: rec.id, status: 'hidden' })} disabled={rec.status === 'hidden' || decide.isPending} className="flex items-center gap-1 rounded-lg border border-border px-2.5 py-1 text-xs font-semibold disabled:opacity-40"><EyeOff size={12} /> {t.hide}</button>
                      <button type="button" onClick={() => decide.mutate({ id: rec.id, status: 'rejected' })} disabled={rec.status === 'rejected' || decide.isPending} className="flex items-center gap-1 rounded-lg border border-border px-2.5 py-1 text-xs font-semibold disabled:opacity-40"><X size={12} /> {t.reject}</button>
                      <button type="button" onClick={() => { setEditing(rec.id); setDraft(text) }} className="flex items-center gap-1 rounded-lg border border-border px-2.5 py-1 text-xs font-semibold"><Pencil size={12} /> {t.edit}</button>
                    </>
                  )}
                </div>
              )}
            </li>
          )
        })}
      </ul>
    </div>
  )
}

function Evidence({ pack, clientId, t, ar, open, onChange }: { pack: PackDetail; clientId: string; t: typeof COPY.ar; ar: boolean; open: boolean; onChange: () => void }) {
  const input = useRef<HTMLInputElement>(null)
  const [provider, setProvider] = useState<string>(PLATFORM_ORDER[0])
  const [note, setNote] = useState('')
  const [over, setOver] = useState(false)
  const upload = useMutation({
    mutationFn: (file: File) => uploadEvidence({ clientId, projectId: pack.project_id, provider, from: pack.period.from, to: pack.period.to, file, note }),
    onSuccess: () => { setNote(''); onChange() },
  })
  const remove = useMutation({ mutationFn: (id: string) => deleteEvidence(id), onSuccess: onChange })
  const take = (files: FileList | null) => { const f = files?.[0]; if (f) upload.mutate(f) }

  return (
    <div>
      <h4 className="mb-2 font-bold text-text-primary">{t.evidence}</h4>
      {open && (
        <div className="mb-3 grid gap-2 sm:grid-cols-[10rem_1fr]">
          <select value={provider} onChange={(e) => setProvider(e.target.value)} aria-label={t.provider} className="rounded-lg border border-border bg-surface px-2 py-2 text-xs">
            {PLATFORM_ORDER.map((p) => <option key={p} value={p}>{providerLabel(p, ar ? 'ar' : 'en')}</option>)}
          </select>
          <input value={note} onChange={(e) => setNote(e.target.value)} placeholder={t.note} className="rounded-lg border border-border bg-surface px-3 py-2 text-xs" />
          <button
            type="button"
            data-testid="evidence-drop"
            onClick={() => input.current?.click()}
            onDragOver={(e) => { e.preventDefault(); setOver(true) }}
            onDragLeave={() => setOver(false)}
            onDrop={(e) => { e.preventDefault(); setOver(false); take(e.dataTransfer.files) }}
            className={`sm:col-span-2 flex items-center justify-center gap-2 rounded-xl border-2 border-dashed p-5 text-xs text-text-secondary ${over ? 'border-brand-500 bg-brand-primary-soft' : 'border-border'}`}
          >
            {upload.isPending ? <Loader2 size={15} className="animate-spin" /> : <FileUp size={15} />} {t.drop}
          </button>
          <input ref={input} data-testid="evidence-input" type="file" accept="image/png,image/jpeg,image/webp,application/pdf" hidden onChange={(e) => { take(e.target.files); e.target.value = '' }} />
        </div>
      )}
      {upload.isError && <p role="alert" className="mb-2 text-xs text-danger">{toApiError(upload.error).message}</p>}
      <ul className="grid gap-2 sm:grid-cols-2">
        {pack.evidence.map((e) => (
          <li key={e.id} data-testid={`evidence-${e.id}`} className="flex items-center justify-between gap-2 rounded-xl border border-border p-2.5">
            <a href={evidenceFileUrl(e.id)} target="_blank" rel="noreferrer" className="flex min-w-0 items-center gap-2 hover:underline">
              <ProviderMark provider={e.provider} />
              <span className="truncate text-xs text-text-primary">{e.note || e.original_name}</span>
            </a>
            {open && <button type="button" onClick={() => remove.mutate(e.id)} aria-label={t.remove} className="rounded p-1 text-text-muted hover:text-danger"><X size={14} /></button>}
          </li>
        ))}
      </ul>
    </div>
  )
}

function Pieces({ pack, t, open, onChange }: { pack: PackDetail; t: typeof COPY.ar; open: boolean; onChange: () => void }) {
  const set = useMutation({ mutationFn: (c: Record<string, boolean>) => setComponents(pack.id, c), onSuccess: onChange })

  return (
    <div>
      <h4 className="mb-2 font-bold text-text-primary">{t.pieces}</h4>
      <ul className="flex flex-wrap gap-x-5 gap-y-2">
        {COMPONENT_KEYS.map((k) => (
          <li key={k}>
            <label className="flex items-center gap-1.5 text-xs text-text-secondary">
              <input type="checkbox" data-testid={`piece-${k}`} disabled={!open || set.isPending} checked={pack.components[k] === true} onChange={(e) => set.mutate({ [k]: e.target.checked })} />
              {t.pieceNames[k]}
            </label>
          </li>
        ))}
      </ul>
    </div>
  )
}

function InvoiceLink({ pack, clientId, t, open, onChange }: { pack: PackDetail; clientId: string; t: typeof COPY.ar; open: boolean; onChange: () => void }) {
  const invoices = useQuery({ queryKey: ['billing', 'invoices', 'all'], queryFn: () => listInvoices(), enabled: open })
  const mine = (invoices.data?.items ?? []).filter((i) => i.client_workspace_id === clientId && !['draft', 'reviewed', 'void'].includes(String(i.status)))
  const attach = useMutation({ mutationFn: (id: number) => attachInvoice(pack.id, id), onSuccess: onChange })

  if (!open) return null

  return (
    <div>
      <h4 className="mb-2 font-bold text-text-primary">{t.invoice}</h4>
      <select data-testid="pack-invoice" value={pack.invoice_id ?? ''} onChange={(e) => e.target.value && attach.mutate(Number(e.target.value))} aria-label={t.pickInvoice} className="rounded-lg border border-border bg-surface px-2 py-2 text-xs">
        <option value="">{t.pickInvoice}</option>
        {mine.map((i) => <option key={i.id} value={i.id}>{i.number} — {Number(i.total).toLocaleString('en-US')} {i.currency}</option>)}
      </select>
    </div>
  )
}

function WhatsApp({ packId, t, ar }: { packId: string; t: typeof COPY.ar; ar: boolean }) {
  const text = useQuery({ queryKey: ['delivery', 'whatsapp', packId, ar ? 'ar' : 'en'], queryFn: () => whatsappText(packId, ar ? 'ar' : 'en') })
  const [copied, setCopied] = useState(false)

  return (
    <div data-testid="pack-whatsapp">
      <div className="mb-1 flex items-center justify-between">
        <h4 className="font-bold text-text-primary">{t.wa}</h4>
        <button
          type="button"
          disabled={!text.data}
          onClick={() => { void navigator.clipboard?.writeText(text.data ?? '').then(() => setCopied(true)) }}
          className="flex items-center gap-1 rounded-lg border border-border px-2.5 py-1 text-xs font-semibold disabled:opacity-40"
        >
          <ClipboardCopy size={12} /> {copied ? t.copied : t.copy}
        </button>
      </div>
      <textarea readOnly value={text.data ?? ''} rows={8} className="w-full rounded-xl border border-border bg-surface-secondary p-3 text-sm" aria-label={t.wa} />
    </div>
  )
}
