import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { BellRing, Check, RotateCcw } from 'lucide-react'
import {
  acknowledgePlatformAlert, fetchPlatformAlerts,
  type PlatformAlertRow, type PlatformAlertState, type PlatformAlertViz,
} from './api'
import { Num } from '@/components/ui/Num'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { fmtDateTime } from '@/lib/datetime'
import { useUi } from '@/stores/ui'

/**
 * PLATFORM-PAYMENT-ALERTS-001 — «تنبيهات الدفع والتسجيل» at the top of the platform owner's «الطلبات».
 *
 * A customer who reached payment and left, a payment Moyasar took that was not confirmed here, money and activation
 * that disagree, and the technical errors that stopped someone signing up. The server writes the words and draws the
 * card from recorded facts — this screen lays it out exactly as the owner's email does, and adds nothing of its own.
 * Only the platform owner reaches it: the route is `platform`-only on the server.
 */

const COPY = {
  ar: {
    title: 'تنبيهات الدفع والتسجيل',
    subtitle: 'عملاء تعثّروا في الدفع أو التسجيل. من سجلات الدفع والتسجيل كما هي، والأوقات بتوقيت الرياض.',
    open: 'مفتوحة', critical: 'عاجلة', showAll: 'اعرض الكل', showOpen: 'المفتوحة فقط',
    empty: 'ما فيه عميل متعثر الحين.', loadError: 'ما قدرنا نحمّل التنبيهات.',
    handled: 'تم التعامل', reopen: 'أعد فتحه', cleared: 'انحلّت', done: 'تعاملت معه',
    firstSeen: 'أول ظهور', lastSeen: 'آخر تحديث', mailed: 'وصل بريدك', contact: 'للتواصل',
    settlement: 'فحص وصول المبالغ متوقف: ما فيه مفتاح ميسر محفوظ لقراءة التسويات.',
    settlementRefused: 'فحص وصول المبالغ متوقف مؤقتًا: ميسر رفضت المفتاح لقراءة التسويات، ونعيد المحاولة تلقائيًا.',
    settlementNoHistory: 'فحص وصول المبالغ غير متاح — لا توجد تسويات في ميسر. أول ما تبدأ التسويات يشتغل الفحص تلقائيًا.',
    urgent: 'عاجل', watch: 'انتبه',
  },
  en: {
    title: 'Payment and sign-up alerts',
    subtitle: 'Customers stuck at payment or sign-up. From the payment and sign-up records as they stand; times are Riyadh time.',
    open: 'Open', critical: 'Urgent', showAll: 'Show all', showOpen: 'Open only',
    empty: 'No customer is stuck right now.', loadError: 'The alerts could not be loaded.',
    handled: 'Handled', reopen: 'Reopen', cleared: 'Cleared', done: 'Handled',
    firstSeen: 'First seen', lastSeen: 'Last update', mailed: 'Emailed', contact: 'Contact',
    settlement: 'The settlement check is off: no Moyasar key is stored to read settlements.',
    settlementRefused: 'The settlement check is paused: Moyasar refused the key for settlements; it retries on its own.',
    settlementNoHistory: 'The settlement check is unavailable — Moyasar has no settlements for this account yet. It starts on its own with the first one.',
    urgent: 'Urgent', watch: 'Watch',
  },
} as const

type Copy = (typeof COPY)['ar'] | (typeof COPY)['en']

const CHIP: Record<PlatformAlertState, string> = {
  ok: 'bg-[var(--positive-background)] text-success',
  watch: 'bg-[var(--warning-background)] text-warning',
  bad: 'bg-[var(--negative-background)] text-danger',
  none: 'bg-surface-secondary text-text-muted',
}

const GLYPH: Record<PlatformAlertState, string> = { ok: '✓', watch: '!', bad: '✕', none: '·' }

export function PlatformAlertsPanel() {
  const ar = useUi((s) => s.locale) === 'ar'
  const t: Copy = ar ? COPY.ar : COPY.en
  const [scope, setScope] = useState<'open' | 'all'>('open')
  const [params] = useSearchParams()
  const focus = params.get('alert')
  const qc = useQueryClient()
  const list = useQuery({ queryKey: ['admin', 'platform-alerts', scope], queryFn: () => fetchPlatformAlerts(scope) })
  const ack = useMutation({
    mutationFn: ({ id, value }: { id: string; value: boolean }) => acknowledgePlatformAlert(id, value),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['admin', 'platform-alerts'] }),
  })

  return (
    <section data-testid="platform-alerts" aria-labelledby="platform-alerts-title" className="mb-6 rounded-xl border border-border bg-surface p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <h2 id="platform-alerts-title" className="flex items-center gap-2 text-lg font-extrabold text-text-primary">
            <BellRing size={18} aria-hidden /> {t.title}
          </h2>
          <p className="mt-1 max-w-2xl text-xs text-text-secondary">{t.subtitle}</p>
        </div>
        <div className="flex items-center gap-2">
          <span data-testid="platform-alerts-count-open" className="rounded-full bg-surface-secondary px-3 py-1 text-xs font-semibold text-text-secondary">
            {t.open} <Num>{list.data?.counts.open ?? '—'}</Num>
          </span>
          {(list.data?.counts.critical ?? 0) > 0 && (
            <span data-testid="platform-alerts-count-critical" className={`rounded-full px-3 py-1 text-xs font-semibold ${CHIP.bad}`}>
              {t.critical} <Num>{list.data?.counts.critical}</Num>
            </span>
          )}
          <button type="button" data-testid="platform-alerts-scope" onClick={() => setScope(scope === 'open' ? 'all' : 'open')}
            className="rounded-full border border-border px-3 py-1 text-xs font-semibold text-text-secondary">
            {scope === 'open' ? t.showAll : t.showOpen}
          </button>
        </div>
      </div>

      {list.data && list.data.settlement_check !== 'available' && (
        <p data-testid="platform-alerts-settlement" className="mt-3 rounded-lg bg-surface-secondary px-3 py-2 text-xs text-text-secondary">
          {list.data.settlement_check === 'refused_by_gateway' ? t.settlementRefused
            : list.data.settlement_check === 'no_settlement_history' ? t.settlementNoHistory : t.settlement}
        </p>
      )}

      <div className="mt-4">
        {list.isPending && <Skeleton className="h-24" />}
        {list.isError && <ErrorState error={list.error} ar={ar} title={t.loadError} onRetry={() => void list.refetch()} />}
        {list.data && list.data.alerts.length === 0 && (
          <p data-testid="platform-alerts-empty" className="text-sm text-text-muted">{t.empty}</p>
        )}
        {list.data && list.data.alerts.length > 0 && (
          <ul className="grid min-w-0 grid-cols-1 gap-3 lg:grid-cols-2">
            {list.data.alerts.map((a) => (
              <AlertCard key={a.id} a={a} t={t} ar={ar} focused={focus === a.id}
                busy={ack.isPending} onAck={(value) => ack.mutate({ id: a.id, value })} />
            ))}
          </ul>
        )}
      </div>
    </section>
  )
}

function AlertCard({ a, t, ar, focused, busy, onAck }: {
  a: PlatformAlertRow; t: Copy; ar: boolean; focused: boolean; busy: boolean; onAck: (value: boolean) => void
}) {
  const state: PlatformAlertState = a.severity === 'critical' ? 'bad' : 'watch'
  const edge = state === 'bad' ? 'border-s-danger' : 'border-s-warning'
  const steps = a.card.steps.split(/\s*[←→]\s*/).filter((s) => s.trim() !== '')
  const handled = a.acknowledged_at !== null

  return (
    <li data-testid={`platform-alert-${a.id}`} data-kind={a.kind}
      className={`min-w-0 rounded-xl border border-border border-s-4 ${edge} bg-surface px-4 py-3 ${focused ? 'ring-2 ring-brand-500' : ''}`}>
      <div className="flex flex-wrap items-center gap-2">
        <span className={`rounded-full px-2 py-0.5 text-xs font-extrabold ${CHIP[state]}`}>{GLYPH[state]} {state === 'bad' ? t.urgent : t.watch}</span>
        {a.card.context !== '' && <span className="min-w-0 truncate text-xs font-bold text-text-secondary">{a.card.context}</span>}
        {a.resolved_at !== null && <span className={`rounded-full px-2 py-0.5 text-xs font-bold ${CHIP.ok}`}>{t.cleared}</span>}
        {handled && <span className={`rounded-full px-2 py-0.5 text-xs font-bold ${CHIP.none}`}>{t.done}</span>}
      </div>
      <h3 className="mt-1 text-base font-extrabold text-text-primary">{a.card.title}</h3>

      <Viz viz={a.card.viz} ar={ar} />

      {a.card.line !== '' && <p data-testid="platform-alert-line" className="mt-2 text-sm text-text-secondary">{a.card.line}</p>}
      {a.card.more.length > 0 && (
        <ul className="mt-1 space-y-0.5 text-xs text-text-secondary">
          {a.card.more.map((m) => <li key={m}>• {m}</li>)}
        </ul>
      )}

      {steps.length > 0 && (
        <ol data-testid="platform-alert-steps" className="mt-2 flex flex-wrap items-center gap-1 text-xs font-bold">
          {steps.map((s, i) => (
            <li key={s} className="flex items-center gap-1">
              {i > 0 && <span aria-hidden className="text-text-muted">{ar ? '←' : '→'}</span>}
              <span className="rounded-md border border-border bg-surface-secondary px-2 py-0.5 text-text-primary"><Num>{i + 1}</Num> {s}</span>
            </li>
          ))}
        </ol>
      )}

      {a.contact && (a.contact.email || a.contact.phone) && (
        <p data-testid="platform-alert-contact" className="mt-2 text-xs text-text-secondary">
          {t.contact}: {a.contact.email && <bdi>{a.contact.email}</bdi>}{a.contact.email && a.contact.phone && ' · '}{a.contact.phone && <Num>{a.contact.phone}</Num>}
        </p>
      )}

      <div className="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-text-muted">
        <span>
          {t.firstSeen} <Num>{fmtDateTime(a.first_seen_at)}</Num>
          {a.last_seen_at !== a.first_seen_at && <> · {t.lastSeen} <Num>{fmtDateTime(a.last_seen_at)}</Num></>}
          {a.emailed_at && <> · {t.mailed} <Num>{fmtDateTime(a.emailed_at)}</Num></>}
        </span>
        <span className="flex items-center gap-2">
          {a.card.links.map((l) => (
            <Link key={l.path} to={l.path} className="font-semibold text-brand-700 underline-offset-4 hover:underline">{l.label}</Link>
          ))}
          <button type="button" data-testid={`platform-alert-ack-${a.id}`} disabled={busy} onClick={() => onAck(!handled)}
            className="inline-flex items-center gap-1 rounded-md border border-border px-2 py-1 font-semibold text-text-secondary disabled:opacity-50">
            {handled ? <RotateCcw size={12} aria-hidden /> : <Check size={12} aria-hidden />}
            {handled ? t.reopen : t.handled}
          </button>
        </span>
      </div>
    </li>
  )
}

/** The drawing from the card: the customer's way in as stages, or one block per affected customer. */
function Viz({ viz, ar }: { viz: PlatformAlertViz; ar: boolean }) {
  if (viz.type === 'count') {
    return (
      <div data-testid="platform-alert-viz" data-viz="count" className="mt-2 flex items-center gap-1">
        {Array.from({ length: viz.blocks }, (_, i) => (
          <span key={i} aria-hidden className={`inline-block h-3 w-3 rounded-sm ${viz.state === 'bad' ? 'bg-danger' : 'bg-warning'}`} />
        ))}
        <span className="ms-1 text-xs font-extrabold text-text-primary"><Num>{viz.more ? `+${viz.n}` : viz.n}</Num> {viz.label}</span>
      </div>
    )
  }

  return (
    <ol data-testid="platform-alert-viz" data-viz="steps" className="mt-2 flex flex-wrap items-center gap-1">
      {viz.steps.map((s, i) => (
        <li key={`${s.label}-${i}`} data-step-state={s.state} className="flex items-center gap-1">
          {i > 0 && <span aria-hidden className="text-xs text-text-muted">{ar ? '←' : '→'}</span>}
          <span className={`whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-extrabold ${CHIP[s.state]}`}>{GLYPH[s.state]} {s.label}</span>
        </li>
      ))}
    </ol>
  )
}
