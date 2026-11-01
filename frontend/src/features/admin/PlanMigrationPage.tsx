import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight, CalendarClock, CheckCircle2, MessageCircle } from 'lucide-react'
import {
  bulkPlanMigration, fetchPlanMigration, schedulePlanMigration, withdrawPlanMigration,
  type MigrationMetric, type MigrationWorkspace,
} from './api'
import { Button } from '@/components/ui/Button'
import { Num } from '@/components/ui/Num'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { fmtDate } from '@/lib/datetime'
import { useUi } from '@/stores/ui'

const METRIC_LABELS: Record<MigrationMetric['metric'], { ar: string; en: string }> = {
  projects: { ar: 'المشاريع', en: 'Projects' },
  clients: { ar: 'العملاء', en: 'Clients' },
  team_members: { ar: 'المستخدمون', en: 'Users' },
  ad_accounts: { ar: 'الحسابات الإعلانية', en: 'Ad accounts' },
}

const QUERY = ['admin', 'plan-migration'] as const

/**
 * `/admin/plan-migration` — PRICING-MIGRATION-001: launch-catalogue subscribers, one row each, and what moving them would mean.
 *
 * Each row states usage against today's caps and the suggested plan's, and both prices in their own currencies — never
 * converted. A move is typed back (the workspace's slug, or «MOVE n» for a batch), carries a reason, and is booked for the end
 * of the period already paid for. Nothing here charges anyone.
 */
export function PlanMigrationPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const list = useQuery({ queryKey: QUERY, queryFn: fetchPlanMigration })
  const [selected, setSelected] = useState<string[]>([])
  const rows = list.data?.workspaces ?? []
  const scheduled = rows.filter((r) => r.scheduled !== null).length
  const ready = rows.filter((r) => r.scheduled === null && r.suggested?.fits).length
  const conversation = rows.filter((r) => r.suggested === null).length

  return (
    <div data-testid="admin-plan-migration" className="w-full">
      <header className="mb-5">
        <h1 className="font-heading text-3xl font-extrabold tracking-tight text-text-primary">{ar ? 'الانتقال إلى الباقات الجديدة' : 'Moving to the new plans'}</h1>
        <p className="mt-1 max-w-3xl text-sm text-text-secondary">
          {ar
            ? 'المشتركون على باقات الإطلاق يحتفظون بباقتهم وسعرها وعملتها. النقل قرار صريح لكل مساحة، يُسجَّل في السجل ويُطبَّق عند نهاية الفترة المدفوعة، بلا أي تحصيل.'
            : 'Launch-plan subscribers keep their plan, price and currency. A move is an explicit, audited decision per workspace that takes effect when the paid period ends — nothing is charged.'}
        </p>
      </header>

      {list.isPending && <Skeleton className="h-48" />}
      {list.isError && <ErrorState error={list.error} ar={ar} title={ar ? 'تعذّر تحميل المعاينة.' : 'The preview could not be loaded.'} onRetry={() => void list.refetch()} />}

      {list.data && (
        <>
          <dl className="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
            <Tally label={ar ? 'على باقات الإطلاق' : 'On launch plans'} value={rows.length} />
            <Tally label={ar ? 'جاهزة للنقل' : 'Ready to move'} value={ready} tone="text-success" />
            <Tally label={ar ? 'نقل مجدول' : 'Move scheduled'} value={scheduled} tone="text-brand-600" />
            <Tally label={ar ? 'تحتاج محادثة' : 'Need a conversation'} value={conversation} tone="text-warning" />
          </dl>

          {rows.length === 0 ? (
            <p data-testid="plan-migration-empty" className="rounded-xl border border-border bg-surface px-4 py-6 text-sm text-text-secondary">
              {ar ? 'لا توجد مساحة على باقات الإطلاق.' : 'No workspace is on a launch plan.'}
            </p>
          ) : (
            <>
              {selected.length > 0 && <BulkBar ids={selected} ar={ar} onDone={() => setSelected([])} />}
              <ul className="grid gap-2">
                {rows.map((r) => (
                  <MigrationRow
                    key={r.tenant_id}
                    row={r}
                    plans={list.data.catalogue.plans}
                    ar={ar}
                    selected={selected.includes(r.tenant_id)}
                    onSelect={(on) => setSelected((s) => (on ? [...s, r.tenant_id] : s.filter((id) => id !== r.tenant_id)))}
                  />
                ))}
              </ul>
            </>
          )}
        </>
      )}
    </div>
  )
}

function Tally({ label, value, tone = 'text-text-primary' }: { label: string; value: number; tone?: string }) {
  return (
    <div className="rounded-xl border border-border bg-surface px-3 py-2">
      <dt className="text-[12px] text-text-muted">{label}</dt>
      <dd className={`tnum text-xl font-extrabold ${tone}`}><Num>{value}</Num></dd>
    </div>
  )
}

function Price({ amount, currency, interval, ar }: { amount: string | null; currency: string | null; interval?: string | null; ar: boolean }) {
  if (amount === null) return <span className="text-text-muted">—</span>
  const per = interval === 'annual' ? (ar ? '/سنة' : '/yr') : (ar ? '/شهر' : '/mo')

  return <span className="tnum"><Num>{amount} {currency}</Num>{per}</span>
}

function MigrationRow({ row, plans, ar, selected, onSelect }: {
  row: MigrationWorkspace
  plans: { code: string; name: string }[]
  ar: boolean
  selected: boolean
  onSelect: (on: boolean) => void
}) {
  const [open, setOpen] = useState(false)
  const Arrow = ar ? ArrowLeft : ArrowRight
  const s = row.suggested
  const selectable = row.scheduled === null && s?.fits === true

  return (
    <li data-testid={`migration-row-${row.tenant_slug}`} className="rounded-xl border border-border bg-surface px-4 py-3">
      <div className="flex flex-wrap items-center gap-3">
        <input
          type="checkbox"
          data-testid={`migration-select-${row.tenant_slug}`}
          aria-label={ar ? `تحديد ${row.tenant_name}` : `Select ${row.tenant_name}`}
          disabled={!selectable}
          checked={selected}
          onChange={(e) => onSelect(e.target.checked)}
          className="size-4 accent-brand-600"
        />
        <span className="min-w-0 flex-1">
          <span className="block truncate font-semibold text-text-primary">{row.tenant_name}</span>
          <span className="block text-[12px] text-text-muted">
            <bdi dir="ltr">{row.tenant_slug}</bdi> · {ar ? 'تنتهي الفترة' : 'period ends'} <Num>{fmtDate(row.current_period_end)}</Num>
          </span>
        </span>
        <span className="text-sm text-text-secondary">
          {row.from.plan_name} · <Price amount={row.from.amount} currency={row.from.currency} interval={row.from.interval} ar={ar} />
        </span>
        <Arrow size={16} className="text-text-muted" aria-hidden />
        {s ? (
          <span className="text-sm font-semibold text-text-primary">
            {s.plan_name} · <Price amount={s.amount} currency={s.currency} interval={s.interval} ar={ar} />
          </span>
        ) : (
          <span className="text-sm font-semibold text-warning">{ar ? 'لا باقة معروضة تكفي — Enterprise' : 'No plan on sale fits — Enterprise'}</span>
        )}
        <StatusPill row={row} ar={ar} />
      </div>

      {s && (
        <ul className="mt-2 flex flex-wrap gap-1.5" aria-label={ar ? 'الاستخدام مقابل الحدود الجديدة' : 'Usage against the new limits'}>
          {s.metrics.map((m) => (
            <li key={m.metric} data-testid={`migration-metric-${row.tenant_slug}-${m.metric}`}
              className={`rounded-full border px-2 py-0.5 text-[12px] ${m.fits ? 'border-border text-text-secondary' : 'border-danger/40 bg-danger/5 font-semibold text-danger'}`}>
              {ar ? METRIC_LABELS[m.metric].ar : METRIC_LABELS[m.metric].en}{' '}
              <Num>{m.usage} / {m.new_limit ?? '∞'}</Num>
            </li>
          ))}
        </ul>
      )}

      <div className="mt-2 flex justify-end">
        {row.scheduled ? <WithdrawForm row={row} ar={ar} /> : (
          <Button size="sm" variant="secondary" data-testid={`migration-open-${row.tenant_slug}`} onClick={() => setOpen((o) => !o)}>
            {ar ? 'جدولة النقل' : 'Schedule move'}
          </Button>
        )}
      </div>
      {open && row.scheduled === null && <ScheduleForm row={row} plans={plans} ar={ar} onDone={() => setOpen(false)} />}
    </li>
  )
}

function StatusPill({ row, ar }: { row: MigrationWorkspace; ar: boolean }) {
  if (row.scheduled) {
    return (
      <span data-testid={`migration-status-${row.tenant_slug}`} className="inline-flex items-center gap-1 rounded-full bg-brand-600/10 px-2 py-0.5 text-[12px] font-semibold text-brand-600">
        <CalendarClock size={13} aria-hidden /> {ar ? 'مجدول إلى' : 'Moving to'} {row.scheduled.plan_name} · <Num>{fmtDate(row.scheduled.effective_at)}</Num>
      </span>
    )
  }
  if (row.suggested?.fits) {
    return (
      <span data-testid={`migration-status-${row.tenant_slug}`} className="inline-flex items-center gap-1 rounded-full bg-success/10 px-2 py-0.5 text-[12px] font-semibold text-success">
        <CheckCircle2 size={13} aria-hidden /> {ar ? 'جاهزة' : 'Ready'}
      </span>
    )
  }

  return (
    <span data-testid={`migration-status-${row.tenant_slug}`} className="inline-flex items-center gap-1 rounded-full bg-warning/10 px-2 py-0.5 text-[12px] font-semibold text-warning">
      <MessageCircle size={13} aria-hidden /> {ar ? 'تحتاج محادثة' : 'Needs a conversation'}
    </span>
  )
}

function ScheduleForm({ row, plans, ar, onDone }: { row: MigrationWorkspace; plans: { code: string; name: string }[]; ar: boolean; onDone: () => void }) {
  const qc = useQueryClient()
  const [plan, setPlan] = useState(row.suggested?.plan ?? plans[0]?.code ?? '')
  const [reason, setReason] = useState('')
  const [confirm, setConfirm] = useState('')
  const save = useMutation({
    mutationFn: () => schedulePlanMigration(row.tenant_id, { plan_code: plan, reason: reason.trim(), confirm: confirm.trim() }),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: QUERY }); onDone() },
  })
  const ok = plan !== '' && reason.trim().length >= 5 && confirm.trim() === row.tenant_slug

  return (
    <div data-testid={`migration-form-${row.tenant_slug}`} className="mt-3 grid gap-2 rounded-lg border border-border bg-surface-2 p-3 sm:grid-cols-[auto_1fr_1fr_auto] sm:items-end">
      <label className="grid gap-1 text-[12px] text-text-muted">
        {ar ? 'الباقة' : 'Plan'}
        <select data-testid={`migration-plan-${row.tenant_slug}`} value={plan} onChange={(e) => setPlan(e.target.value)}
          className="h-9 rounded-lg border border-border bg-surface px-2 text-sm text-text-primary">
          {plans.map((p) => <option key={p.code} value={p.code}>{p.name}</option>)}
        </select>
      </label>
      <label className="grid gap-1 text-[12px] text-text-muted">
        {ar ? 'السبب' : 'Reason'}
        <input data-testid={`migration-reason-${row.tenant_slug}`} value={reason} onChange={(e) => setReason(e.target.value)}
          className="h-9 min-w-0 rounded-lg border border-border bg-surface px-2 text-sm text-text-primary" />
      </label>
      <label className="grid gap-1 text-[12px] text-text-muted">
        {ar ? <>اكتب <bdi dir="ltr" className="font-mono">{row.tenant_slug}</bdi> للتأكيد</> : <>Type <span className="font-mono">{row.tenant_slug}</span> to confirm</>}
        <input data-testid={`migration-confirm-${row.tenant_slug}`} value={confirm} onChange={(e) => setConfirm(e.target.value)} autoComplete="off"
          className="h-9 min-w-0 rounded-lg border border-border bg-surface px-2 font-mono text-sm text-text-primary" />
      </label>
      <Button size="sm" data-testid={`migration-submit-${row.tenant_slug}`} disabled={!ok || save.isPending} onClick={() => save.mutate()}>
        {ar ? 'جدولة عند نهاية الفترة' : 'Schedule for period end'}
      </Button>
      {save.isError && <p role="alert" className="text-xs font-semibold text-danger sm:col-span-4">{toApiError(save.error).message}</p>}
    </div>
  )
}

function WithdrawForm({ row, ar }: { row: MigrationWorkspace; ar: boolean }) {
  const qc = useQueryClient()
  const [reason, setReason] = useState('')
  const withdraw = useMutation({
    mutationFn: () => withdrawPlanMigration(row.tenant_id, reason.trim()),
    onSuccess: () => void qc.invalidateQueries({ queryKey: QUERY }),
  })

  return (
    <div className="flex flex-wrap items-center gap-2">
      <input data-testid={`migration-withdraw-reason-${row.tenant_slug}`} value={reason} onChange={(e) => setReason(e.target.value)}
        placeholder={ar ? 'سبب السحب' : 'Reason to withdraw'} aria-label={ar ? 'سبب السحب' : 'Reason to withdraw'}
        className="h-9 w-48 min-w-0 rounded-lg border border-border bg-surface px-2 text-sm" />
      <Button size="sm" variant="secondary" data-testid={`migration-withdraw-${row.tenant_slug}`} disabled={reason.trim().length < 5 || withdraw.isPending} onClick={() => withdraw.mutate()}>
        {ar ? 'سحب النقل' : 'Withdraw move'}
      </Button>
      {withdraw.isError && <p role="alert" className="w-full text-xs font-semibold text-danger">{toApiError(withdraw.error).message}</p>}
    </div>
  )
}

function BulkBar({ ids, ar, onDone }: { ids: string[]; ar: boolean; onDone: () => void }) {
  const qc = useQueryClient()
  const [reason, setReason] = useState('')
  const [confirm, setConfirm] = useState('')
  const phrase = `MOVE ${ids.length}`
  const run = useMutation({
    mutationFn: () => bulkPlanMigration({ tenant_ids: ids, reason: reason.trim(), confirm: confirm.trim() }),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: QUERY }); setReason(''); setConfirm(''); onDone() },
  })

  return (
    <div data-testid="migration-bulk" className="sticky top-2 z-10 mb-3 flex flex-wrap items-center gap-2 rounded-xl border border-brand-600/30 bg-surface px-4 py-3 shadow-sm">
      <span className="text-sm font-semibold text-text-primary">
        {ar ? <>محدد: <Num>{ids.length}</Num> — كل مساحة إلى الباقة المقترحة لها</> : <><Num>{ids.length}</Num> selected — each onto its suggested plan</>}
      </span>
      <input data-testid="migration-bulk-reason" value={reason} onChange={(e) => setReason(e.target.value)} placeholder={ar ? 'السبب' : 'Reason'}
        aria-label={ar ? 'السبب' : 'Reason'} className="h-9 w-48 min-w-0 rounded-lg border border-border bg-surface px-2 text-sm" />
      <input data-testid="migration-bulk-confirm" value={confirm} onChange={(e) => setConfirm(e.target.value)} placeholder={phrase} autoComplete="off"
        aria-label={ar ? `اكتب ${phrase} للتأكيد` : `Type ${phrase} to confirm`} className="h-9 w-32 rounded-lg border border-border bg-surface px-2 font-mono text-sm" />
      <Button size="sm" data-testid="migration-bulk-submit" disabled={reason.trim().length < 5 || confirm.trim() !== phrase || run.isPending} onClick={() => run.mutate()}>
        {ar ? 'جدولة المحدد' : 'Schedule selected'}
      </Button>
      {run.isError && <p role="alert" className="w-full text-xs font-semibold text-danger">{toApiError(run.error).message}</p>}
    </div>
  )
}
