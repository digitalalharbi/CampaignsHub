import { useEffect, useRef, useState, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Brain, CalendarClock, Gauge, KeyRound, Loader2, Play, Plug, Timer, X } from 'lucide-react'
import { Switch } from '@/components/ui/Switch'
import { toApiError } from '@/lib/api/client'
import {
  addAiCredential, getAssistantSettings, listAiCredentials, listRoutines, runRoutineNow, saveAssistantSettings, setRoutine, testAiCredential,
} from './api'
import { ChatRetentionCard } from './ChatRetentionCard'
import { MemoryManager } from './MemoryManager'
import { MODEL_LABEL, reasonText } from './agentCopy'

/**
 * AGENTS-ASSISTANT-001 — ONE «الإعدادات» drawer instead of the side cards: the AI key (masked, with a connection test), the model,
 * how long conversations are kept, the daily limit, my memory, and the scheduled tasks as named switches with their next run and
 * «تشغيل الآن». What only a manager may change is shown read-only to everyone else.
 */
const riyadh = (iso: string | null | undefined) => {
  if (!iso) return '—'
  const p = Object.fromEntries(new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Riyadh', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).formatToParts(new Date(iso)).map((x) => [x.type, x.value]))
  return `${p.year}-${p.month}-${p.day} ${p.hour}:${p.minute}`
}

const OUTCOME: Record<string, { ar: string; en: string }> = {
  queued: { ar: 'بدأ التشغيل', en: 'Started' },
  skipped_busy: { ar: 'التشغيل السابق لم ينتهِ', en: 'Previous run still answering' },
  no_ai_credential: { ar: 'لا يوجد مفتاح', en: 'No key' },
  model_unavailable: { ar: 'النموذج غير متاح', en: 'Model unavailable' },
  unavailable: { ar: 'غير متاح لهذا المشروع', en: 'Not available here' },
}

const TEST: Record<string, { ar: string; en: string }> = {
  ok: { ar: 'متصل', en: 'Connected' },
  refused_401: { ar: 'المفتاح مرفوض', en: 'Key refused' },
  rate_limited: { ar: 'تجاوز الحد لدى المزوّد', en: 'Provider rate limit' },
  model_not_configured: { ar: 'حدّد النموذج', en: 'Set the model' },
  refused: { ar: 'رفض المزوّد', en: 'Provider declined' },
  failed: { ar: 'تعذّر الاتصال', en: 'Could not connect' },
}

export function SettingsDrawer({ open, onClose, ar, projectId, canManage, onOpenThread }: {
  open: boolean
  onClose: () => void
  ar: boolean
  projectId: string
  canManage: boolean
  onOpenThread: (id: string) => void
}) {
  const panel = useRef<HTMLDivElement>(null)
  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose() }
    document.addEventListener('keydown', onKey)
    panel.current?.focus()
    return () => document.removeEventListener('keydown', onKey)
  }, [open, onClose])
  if (!open) return null

  return (
    <div className="fixed inset-0 z-50 flex justify-end" data-testid="settings-drawer">
      <button type="button" aria-label={ar ? 'إغلاق' : 'Close'} className="absolute inset-0 bg-black/30" onClick={onClose} />
      <div ref={panel} tabIndex={-1} role="dialog" aria-modal="true" aria-label={ar ? 'الإعدادات' : 'Settings'} className="relative flex h-full w-full max-w-md flex-col bg-surface shadow-[var(--shadow-large)] outline-none">
        <div className="flex items-center justify-between border-b border-border px-4 py-3">
          <p className="text-base font-bold text-text-primary">{ar ? 'الإعدادات' : 'Settings'}</p>
          <button type="button" onClick={onClose} aria-label={ar ? 'إغلاق' : 'Close'} data-testid="settings-close" className="text-text-muted hover:text-text-primary"><X size={18} /></button>
        </div>
        <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-4 py-4">
          <Section icon={<KeyRound size={14} />} title={ar ? 'مزوّد الذكاء الاصطناعي' : 'AI provider'}><KeySection ar={ar} canManage={canManage} /></Section>
          <Section icon={<Gauge size={14} />} title={ar ? 'النموذج والحد اليومي' : 'Model & daily limit'}><ModelSection ar={ar} canManage={canManage} /></Section>
          <Section icon={<Timer size={14} />} title={ar ? 'حفظ المحادثات' : 'Keep conversations'}>{canManage ? <ChatRetentionCard ar={ar} bare /> : <p className="text-xs text-text-muted">{ar ? 'يحددها مدير الوكلاء.' : 'Set by whoever manages the agents.'}</p>}</Section>
          <Section icon={<Brain size={14} />} title={ar ? 'ذاكرتي' : 'My memory'}><MemoryManager ar={ar} /></Section>
          <Section icon={<CalendarClock size={14} />} title={ar ? 'المهام المجدولة' : 'Scheduled tasks'}><RoutinesSection ar={ar} projectId={projectId} canManage={canManage} onOpenThread={onOpenThread} /></Section>
        </div>
      </div>
    </div>
  )
}

function Section({ icon, title, children }: { icon: ReactNode; title: string; children: ReactNode }) {
  return (
    <section className="space-y-2">
      <h3 className="flex items-center gap-1.5 text-sm font-bold text-text-primary"><span className="text-brand-600" aria-hidden>{icon}</span>{title}</h3>
      {children}
    </section>
  )
}

function KeySection({ ar, canManage }: { ar: boolean; canManage: boolean }) {
  const qc = useQueryClient()
  const [provider, setProvider] = useState<'anthropic' | 'openai' | 'gemini'>('anthropic')
  const [secret, setSecret] = useState('')
  const [model, setModel] = useState('')
  const [tests, setTests] = useState<Record<string, string>>({})
  const keys = useQuery({ queryKey: ['ai-credentials'], queryFn: listAiCredentials, retry: false })
  const add = useMutation({ meta: { feedback: 'none' }, mutationFn: () => addAiCredential(provider, secret, model.trim() || undefined), onSuccess: () => { setSecret(''); setModel(''); void qc.invalidateQueries({ queryKey: ['ai-credentials'] }) } })
  const test = useMutation({ meta: { feedback: 'none' }, mutationFn: (id: string) => testAiCredential(id), onSuccess: (r, id) => setTests((t) => ({ ...t, [id]: r.code })) })
  const active = (keys.data ?? []).filter((k) => k.status === 'active' && ['anthropic', 'openai', 'gemini'].includes(k.provider))
  const needsModel = provider !== 'anthropic' && model.trim() === ''
  const name = (p: string) => (p === 'openai' ? 'OpenAI' : p === 'gemini' ? 'Gemini' : 'Anthropic')

  return (
    <div className="space-y-2" data-testid="settings-key">
      {active.length === 0
        ? <p className="rounded-lg bg-[var(--warning-background)] px-2.5 py-1.5 text-xs text-warning">{reasonText('no_ai_credential', ar)}</p>
        : (
          <ul className="space-y-1">
            {active.map((k) => (
              <li key={k.id} className="flex items-center justify-between gap-2 rounded-lg border border-border px-2.5 py-1.5 text-xs" data-testid="settings-key-row">
                <span dir="ltr" className="tnum">{name(k.provider)} · {k.masked_key}</span>
                {canManage && (
                  <span className="flex items-center gap-1.5">
                    {tests[k.id] && <span className={tests[k.id] === 'ok' ? 'text-success' : 'text-danger'} data-testid="settings-key-test-result">{ar ? (TEST[tests[k.id]]?.ar ?? tests[k.id]) : (TEST[tests[k.id]]?.en ?? tests[k.id])}</span>}
                    <button type="button" onClick={() => test.mutate(k.id)} disabled={test.isPending} className="inline-flex items-center gap-1 rounded-md border border-border px-2 py-0.5 font-semibold" data-testid="settings-key-test">
                      {test.isPending && test.variables === k.id ? <Loader2 size={11} className="animate-spin" /> : <Plug size={11} />}{ar ? 'اختبار' : 'Test'}
                    </button>
                  </span>
                )}
              </li>
            ))}
          </ul>
        )}
      {canManage && (
        <form className="space-y-1.5" onSubmit={(e) => { e.preventDefault(); if (secret.length >= 8 && !needsModel) add.mutate() }}>
          <div className="flex gap-1.5">
            <select value={provider} onChange={(e) => setProvider(e.target.value as 'anthropic' | 'openai' | 'gemini')} aria-label={ar ? 'المزوّد' : 'Provider'} data-testid="agent-ai-key-provider" className="h-8 rounded-lg border border-border bg-surface px-2 text-xs">
              <option value="anthropic">Anthropic</option>
              <option value="openai">OpenAI</option>
              <option value="gemini">Gemini</option>
            </select>
            <input type="password" autoComplete="off" value={secret} onChange={(e) => setSecret(e.target.value)} aria-label={ar ? 'المفتاح' : 'Key'} placeholder={ar ? 'الصق المفتاح' : 'Paste the key'} dir="ltr" data-testid="agent-ai-key-input" className="h-8 min-w-0 flex-1 rounded-lg border border-border bg-surface px-2 text-xs" />
          </div>
          {provider !== 'anthropic' && (
            <input value={model} onChange={(e) => setModel(e.target.value)} aria-label={ar ? 'النموذج' : 'Model'} placeholder={ar ? 'اسم النموذج (مطلوب)' : 'Model name (required)'} dir="ltr" data-testid="agent-ai-key-model" className="h-8 w-full rounded-lg border border-border bg-surface px-2 text-xs" />
          )}
          <div className="flex items-center justify-between gap-2">
            <p className="text-xs text-text-muted">{ar ? 'يُحفظ مشفّرًا، ولا يظهر بعد الحفظ إلا آخر 4 أحرف. المفتاح الجديد يحل محل السابق.' : 'Stored encrypted; only the last 4 characters show after saving. A new key replaces the previous one.'}</p>
            <button type="submit" disabled={secret.length < 8 || needsModel || add.isPending} data-testid="agent-ai-key-save" className="shrink-0 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50">{ar ? 'حفظ' : 'Save'}</button>
          </div>
        </form>
      )}
      {(add.error ?? test.error) && <p role="alert" className="text-xs text-danger">{toApiError(add.error ?? test.error).message}</p>}
    </div>
  )
}

function ModelSection({ ar, canManage }: { ar: boolean; canManage: boolean }) {
  const qc = useQueryClient()
  const s = useQuery({ queryKey: ['assistant-settings'], queryFn: getAssistantSettings, retry: false })
  const [cap, setCap] = useState<string | null>(null)
  const save = useMutation({ meta: { feedback: 'none' }, mutationFn: saveAssistantSettings, onSuccess: (d) => { setCap(null); qc.setQueryData(['assistant-settings'], d) } })
  const d = s.data
  if (!d) return null
  const capValue = cap ?? String(d.daily_token_cap)
  const n = Number(capValue)
  const valid = Number.isInteger(n) && n >= d.cap_min && n <= d.cap_max
  const pct = Math.min(100, Math.round((d.used_today / Math.max(1, d.daily_token_cap)) * 100))

  return (
    <div className="space-y-3" data-testid="settings-model">
      <div className="flex flex-wrap gap-1.5" role="radiogroup" aria-label={ar ? 'النموذج' : 'Model'}>
        {d.models.map((m) => (
          <button key={m} type="button" role="radio" aria-checked={d.model === m} disabled={!canManage || save.isPending} onClick={() => save.mutate({ assistant_model: m })} data-testid={`settings-model-${m}`}
            className={`rounded-lg px-2.5 py-1 text-xs font-semibold ${d.model === m ? 'bg-brand-600 text-white' : 'border border-border text-text-secondary'} disabled:cursor-default`}>
            {MODEL_LABEL[m] ?? m}{m === d.default_model ? (ar ? ' · الافتراضي' : ' · default') : ''}
          </button>
        ))}
      </div>
      <div>
        <div className="flex items-center justify-between text-xs text-text-secondary">
          <span>{ar ? 'استخدامك اليوم' : 'Your use today'}</span>
          <bdi dir="ltr" className="tnum">{d.used_today.toLocaleString('en-US')} / {d.daily_token_cap.toLocaleString('en-US')}</bdi>
        </div>
        <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-surface-secondary" aria-hidden><div className={`h-full ${pct >= 90 ? 'bg-danger' : 'bg-brand-600'}`} style={{ width: `${pct}%` }} /></div>
      </div>
      {canManage && (
        <form className="flex items-center gap-1.5" onSubmit={(e) => { e.preventDefault(); if (valid) save.mutate({ daily_token_cap: n }) }}>
          <label className="text-xs text-text-secondary" htmlFor="daily-cap">{ar ? 'الحد اليومي لكل شخص' : 'Daily limit per person'}</label>
          <input id="daily-cap" type="number" min={d.cap_min} max={d.cap_max} step={10000} value={capValue} onChange={(e) => setCap(e.target.value)} dir="ltr" data-testid="settings-cap" className="h-8 w-28 rounded-lg border border-border bg-surface px-2 text-xs" />
          <button type="submit" disabled={!valid || n === d.daily_token_cap || save.isPending} data-testid="settings-cap-save" className="rounded-lg bg-brand-600 px-2.5 py-1 text-xs font-semibold text-white disabled:opacity-50">{ar ? 'حفظ' : 'Save'}</button>
        </form>
      )}
      <p className="text-xs text-text-muted">{ar ? `أقصى ${d.max_tool_rounds} خطوات قراءة في الرد الواحد.` : `At most ${d.max_tool_rounds} reading steps per reply.`}</p>
      {save.isError && <p role="alert" className="text-xs text-danger">{toApiError(save.error).message}</p>}
    </div>
  )
}

function RoutinesSection({ ar, projectId, canManage, onOpenThread }: { ar: boolean; projectId: string; canManage: boolean; onOpenThread: (id: string) => void }) {
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['agent-routines', projectId], queryFn: () => listRoutines(projectId), retry: false })
  const toggle = useMutation({ meta: { feedback: 'none' }, mutationFn: (v: { key: string; on: boolean }) => setRoutine(projectId, v.key, v.on), onSuccess: (d) => qc.setQueryData(['agent-routines', projectId], d) })
  const run = useMutation({
    meta: { feedback: 'none' },
    mutationFn: (key: string) => runRoutineNow(projectId, key),
    onSuccess: (d) => { qc.setQueryData(['agent-routines', projectId], { routines: d.routines }); void qc.invalidateQueries({ queryKey: ['agent-threads', projectId] }); if (d.thread_id) onOpenThread(d.thread_id) },
  })
  const rows = q.data?.routines ?? []

  return (
    <div className="space-y-1.5" data-testid="settings-routines">
      {rows.map((r) => (
        <div key={r.key} className="rounded-lg border border-border px-2.5 py-2" data-testid={`routine-${r.key}`}>
          <div className="flex items-center justify-between gap-2">
            <div className="min-w-0">
              <p className="text-xs font-semibold text-text-primary">{ar ? r.ar : r.en} <span className="font-normal text-text-muted">— {ar ? r.when_ar : r.when_en}</span></p>
              <p className="text-xs text-text-muted">
                {r.enabled ? <>{ar ? 'التالي:' : 'Next:'} <bdi dir="ltr" className="tnum">{riyadh(r.next_run_at)}</bdi></> : (ar ? 'متوقف' : 'Off')}
                {r.last_outcome && <> · {ar ? (OUTCOME[r.last_outcome]?.ar ?? r.last_outcome) : (OUTCOME[r.last_outcome]?.en ?? r.last_outcome)}</>}
              </p>
            </div>
            <Switch checked={r.enabled} disabled={!canManage || toggle.isPending} onCheckedChange={(on) => toggle.mutate({ key: r.key, on })} testId={`routine-toggle-${r.key}`} />
          </div>
          {canManage && (
            <button type="button" onClick={() => run.mutate(r.key)} disabled={run.isPending} data-testid={`routine-run-${r.key}`} className="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-brand-700 disabled:opacity-50">
              {run.isPending && run.variables === r.key ? <Loader2 size={11} className="animate-spin" /> : <Play size={11} />}{ar ? 'تشغيل الآن' : 'Run now'}
            </button>
          )}
        </div>
      ))}
      <p className="text-xs text-text-muted">{ar ? 'كل تشغيل يستخدم مفتاحك، وأي تغيير يصبح اقتراحًا بانتظار موافقتك.' : 'Each run uses your key; any change becomes a proposal waiting for your approval.'}</p>
      {(toggle.error ?? run.error) && <p role="alert" className="text-xs text-danger">{toApiError(toggle.error ?? run.error).message}</p>}
    </div>
  )
}
