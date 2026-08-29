import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarClock, Pause, Play, Plus, Send, Trash2 } from 'lucide-react'
import {
  createSchedule, deleteSchedule, listSchedules, runScheduleNow, toggleSchedule,
  type ReportScheduleRow,
} from './api'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Select } from '@/components/ui/Select'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { useAuth } from '@/stores/auth'
import { fmtDateTime } from '@/lib/datetime'

const FREQUENCIES = [
  { value: 'daily', label: 'يومي' },
  { value: 'weekly', label: 'أسبوعي' },
  { value: 'monthly', label: 'شهري' },
]

const WEEKDAYS = [
  { value: 'sunday', label: 'الأحد' }, { value: 'monday', label: 'الاثنين' },
  { value: 'tuesday', label: 'الثلاثاء' }, { value: 'wednesday', label: 'الأربعاء' },
  { value: 'thursday', label: 'الخميس' }, { value: 'friday', label: 'الجمعة' },
  { value: 'saturday', label: 'السبت' },
]

const AUDIENCES = [
  { value: 'client', label: 'العميل' },
  { value: 'internal', label: 'داخلي' },
  { value: 'executive', label: 'تنفيذي' },
]

/** Delivery states as the ledger records them — never invented, never upgraded to "تم الإرسال". */
const DELIVERY_LABEL: Record<string, { ar: string; tone: 'success' | 'warning' | 'danger' | 'neutral' }> = {
  sent: { ar: 'مُرسلة', tone: 'success' },
  awaiting_provider_credentials: { ar: 'بانتظار مزود بريد', tone: 'warning' },
  suppressed_internal: { ar: 'محجوبة (تقرير داخلي)', tone: 'neutral' },
  failed: { ar: 'فاشلة', tone: 'danger' },
}

const freqLabel = (s: ReportScheduleRow) => {
  if (s.frequency === 'weekly') return `أسبوعي · ${WEEKDAYS.find((d) => d.value === s.day)?.label ?? s.day ?? ''}`
  if (s.frequency === 'monthly') return `شهري · يوم ${s.day ?? 1}`
  if (s.frequency === 'custom') return `مخصص · ${s.cron ?? ''}`
  return 'يومي'
}

/**
 * REPORT-SCHEDULING — the scheduling UI, built only now that a real HTTP API exists behind it.
 *
 * It shows exactly what the backend knows: when a schedule fires next (computed by the same code the
 * cron uses), when it last ran, and the delivery ledger per state. Nothing here claims a report was
 * emailed — with no mail provider configured the honest state is "بانتظار مزود بريد".
 */
export function SchedulesPanel({ projectId }: { projectId: string }) {
  const qc = useQueryClient()
  const canManage = useAuth((s) => s.hasPermission('reports.export'))
  const [formOpen, setFormOpen] = useState(false)

  const q = useQuery({
    queryKey: ['report-schedules', projectId],
    queryFn: () => listSchedules(projectId),
    enabled: Boolean(projectId),
  })
  const invalidate = () => qc.invalidateQueries({ queryKey: ['report-schedules', projectId] })

  const toggle = useMutation({ mutationFn: (id: string) => toggleSchedule(projectId, id), onSuccess: invalidate })
  const run = useMutation({ mutationFn: (id: string) => runScheduleNow(projectId, id), onSuccess: invalidate })
  const remove = useMutation({ mutationFn: (id: string) => deleteSchedule(projectId, id), onSuccess: invalidate })

  const rows = q.data ?? []

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-text-secondary">
          الجدولة تُنشئ التقرير تلقائيًا في موعده وتسجّل محاولة تسليم لكل مستلم — ولا تُعلن «تم الإرسال» قبل تأكيد مزوّد بريد فعلي.
        </p>
        {canManage && (
          <Button size="sm" onClick={() => setFormOpen((v) => !v)}>
            <Plus size={16} /> جدولة جديدة
          </Button>
        )}
      </div>

      {formOpen && canManage && (
        <ScheduleForm
          projectId={projectId}
          onDone={() => { setFormOpen(false); invalidate() }}
          onCancel={() => setFormOpen(false)}
        />
      )}

      {q.isLoading ? (
        <Skeleton className="h-40" />
      ) : q.isError ? (
        <EmptyState title="تعذّر تحميل الجدولة" description="حاول تحديث الصفحة." />
      ) : rows.length === 0 ? (
        <EmptyState title="لا توجد جدولة بعد" description="أنشئ جدولة ليتولّى النظام إنتاج التقرير وإرساله في موعده." />
      ) : (
        <ul data-testid="schedule-list" className="space-y-2">
          {rows.map((s) => (
            <li key={s.id} className="rounded-2xl border border-border bg-surface p-4 shadow-[var(--shadow-small)]">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-bold text-text-primary">{s.name}</span>
                    <Badge tone={s.active ? 'success' : 'neutral'}>{s.active ? 'مفعّلة' : 'موقوفة'}</Badge>
                    {s.is_demo && <Badge tone="warning">تجريبية</Badge>}
                  </div>
                  <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-text-muted">
                    <span className="inline-flex items-center gap-1"><CalendarClock size={13} /> {freqLabel(s)} · {s.time ?? '—'} ({s.timezone ?? '—'})</span>
                    <span>الجمهور: {AUDIENCES.find((a) => a.value === s.audience)?.label ?? s.audience ?? '—'}</span>
                    <span>الصيغ: {s.formats.length ? s.formats.join(' · ') : '—'}</span>
                    <span>المستلمون: <span className="tnum">{s.recipients.length}</span></span>
                  </div>
                  <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                    <span className="text-text-secondary">التشغيل القادم: <span className="tnum font-semibold text-text-primary">{s.active && s.next_run_at ? fmtDateTime(s.next_run_at) : 'لا يوجد — موقوفة'}</span></span>
                    <span className="text-text-secondary">آخر تشغيل: <span className="tnum">{s.last_run_at ? fmtDateTime(s.last_run_at) : 'لم تُشغَّل بعد'}</span></span>
                  </div>
                  {Object.keys(s.deliveries).length > 0 && (
                    <div className="mt-2 flex flex-wrap gap-1.5">
                      {Object.entries(s.deliveries).map(([status, count]) => {
                        const meta = DELIVERY_LABEL[status] ?? { ar: status, tone: 'neutral' as const }
                        return <Badge key={status} tone={meta.tone}>{meta.ar}: <span className="tnum">{count}</span></Badge>
                      })}
                    </div>
                  )}
                </div>

                {canManage && (
                  <div className="flex shrink-0 items-center gap-1.5">
                    <button
                      data-testid="schedule-run"
                      onClick={() => run.mutate(s.id)}
                      disabled={run.isPending}
                      title="شغّل الآن"
                      className="inline-flex h-8 items-center gap-1 rounded-lg border border-border px-2 text-xs font-semibold text-text-secondary hover:bg-surface-hover disabled:opacity-50"
                    >
                      <Send size={13} /> شغّل الآن
                    </button>
                    <button
                      data-testid="schedule-toggle"
                      onClick={() => toggle.mutate(s.id)}
                      disabled={toggle.isPending}
                      title={s.active ? 'إيقاف' : 'تفعيل'}
                      className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-border text-text-secondary hover:bg-surface-hover disabled:opacity-50"
                    >
                      {s.active ? <Pause size={14} /> : <Play size={14} />}
                    </button>
                    <button
                      onClick={() => remove.mutate(s.id)}
                      disabled={remove.isPending}
                      title="حذف"
                      className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-border text-text-secondary hover:text-danger disabled:opacity-50"
                    >
                      <Trash2 size={14} />
                    </button>
                  </div>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

function ScheduleForm({ projectId, onDone, onCancel }: { projectId: string; onDone: () => void; onCancel: () => void }) {
  const [name, setName] = useState('')
  const [type, setType] = useState('executive')
  const [frequency, setFrequency] = useState('weekly')
  const [day, setDay] = useState('sunday')
  const [time, setTime] = useState('08:00')
  const [audience, setAudience] = useState('client')
  const [formats, setFormats] = useState<string[]>(['pdf'])
  const [emails, setEmails] = useState('')

  const create = useMutation({
    mutationFn: () => createSchedule(projectId, {
      name: name.trim(),
      type,
      frequency: frequency as 'daily' | 'weekly' | 'monthly',
      day: frequency === 'daily' ? null : day,
      time,
      timezone: 'Asia/Riyadh',
      audience,
      language: 'ar',
      formats,
      recipients: emails.split(/[,\s]+/).filter(Boolean).map((email) => ({ email })),
    }),
    onSuccess: onDone,
  })

  const invalid = name.trim() === '' || formats.length === 0

  return (
    <form
      data-testid="schedule-form"
      onSubmit={(e) => { e.preventDefault(); if (!invalid) create.mutate() }}
      className="space-y-3 rounded-2xl border border-border bg-surface p-4 shadow-[var(--shadow-small)]"
    >
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <label className="space-y-1">
          <span className="text-xs font-semibold text-text-secondary">اسم الجدولة</span>
          <input value={name} onChange={(e) => setName(e.target.value)} placeholder="تقرير الأداء الأسبوعي"
            className="h-10 w-full rounded-xl border border-border bg-surface px-3 text-sm outline-none focus:border-brand-500" />
        </label>
        <label className="space-y-1">
          <span className="text-xs font-semibold text-text-secondary">نوع التقرير</span>
          <Select value={type} onChange={(e) => setType(e.target.value)} options={[
            { value: 'executive', label: 'تنفيذي' }, { value: 'performance', label: 'أداء' }, { value: 'custom', label: 'مخصص' },
          ]} />
        </label>
        <label className="space-y-1">
          <span className="text-xs font-semibold text-text-secondary">التكرار</span>
          <Select value={frequency} onChange={(e) => setFrequency(e.target.value)} options={FREQUENCIES} />
        </label>
        {frequency === 'weekly' && (
          <label className="space-y-1">
            <span className="text-xs font-semibold text-text-secondary">اليوم</span>
            <Select value={day} onChange={(e) => setDay(e.target.value)} options={WEEKDAYS} />
          </label>
        )}
        {frequency === 'monthly' && (
          <label className="space-y-1">
            <span className="text-xs font-semibold text-text-secondary">يوم الشهر</span>
            <Select value={day} onChange={(e) => setDay(e.target.value)}
              options={Array.from({ length: 28 }, (_, i) => ({ value: String(i + 1), label: String(i + 1) }))} />
          </label>
        )}
        <label className="space-y-1">
          <span className="text-xs font-semibold text-text-secondary">الوقت (24 ساعة)</span>
          <input value={time} onChange={(e) => setTime(e.target.value)} dir="ltr" placeholder="08:00"
            className="tnum h-10 w-full rounded-xl border border-border bg-surface px-3 text-left text-sm outline-none focus:border-brand-500" />
        </label>
        <label className="space-y-1">
          <span className="text-xs font-semibold text-text-secondary">الجمهور</span>
          <Select value={audience} onChange={(e) => setAudience(e.target.value)} options={AUDIENCES} />
        </label>
      </div>

      <div className="space-y-1">
        <span className="text-xs font-semibold text-text-secondary">الصيغ</span>
        <div className="flex gap-1.5">
          {['pdf', 'xlsx', 'csv'].map((f) => (
            <button key={f} type="button" aria-pressed={formats.includes(f)}
              onClick={() => setFormats((prev) => prev.includes(f) ? prev.filter((x) => x !== f) : [...prev, f])}
              className={`rounded-full border px-3 py-1 text-xs font-semibold uppercase ${
                formats.includes(f) ? 'border-brand-500 bg-brand-primary-soft text-brand-700' : 'border-border text-text-secondary hover:bg-surface-hover'
              }`}>{f}</button>
          ))}
        </div>
      </div>

      <label className="block space-y-1">
        <span className="text-xs font-semibold text-text-secondary">المستلمون (بريد إلكتروني، مفصولة بفاصلة)</span>
        <input value={emails} onChange={(e) => setEmails(e.target.value)} dir="ltr" placeholder="client@example.com, manager@example.com"
          className="h-10 w-full rounded-xl border border-border bg-surface px-3 text-left text-sm outline-none focus:border-brand-500" />
        <span className="block text-[11px] text-text-muted">
          تُسجَّل محاولة تسليم لكل مستلم × صيغة. بدون مزوّد بريد مُهيّأ تبقى الحالة «بانتظار مزود بريد» ولا تتحول إلى «مُرسلة».
        </span>
      </label>

      {create.isError && <p className="text-sm text-danger">تعذّر إنشاء الجدولة — تحقق من الحقول ثم أعد المحاولة.</p>}

      <div className="flex items-center gap-2">
        <Button type="submit" size="sm" disabled={invalid || create.isPending}>
          {create.isPending ? 'جارٍ الحفظ…' : 'حفظ الجدولة'}
        </Button>
        <button type="button" onClick={onCancel} className="rounded-lg px-3 py-1.5 text-sm font-semibold text-text-secondary hover:bg-surface-hover">
          إلغاء
        </button>
      </div>
    </form>
  )
}
