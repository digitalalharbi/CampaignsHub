import { useQuery } from '@tanstack/react-query'
import { Gauge, KeyRound, Wallet } from 'lucide-react'
import { getUsage, type OwnKeyUsage, type UsageMeterRow } from './api'
import { Num } from '@/components/ui/Num'

const fmt = (n: number) => n.toLocaleString('en-US')

/** How far through a monthly cap: the bar's tone follows the 75 / 90 / 100% alerts the owner receives. */
function Meter({ used, cap, testId }: { used: number; cap: number | null; testId: string }) {
  if (cap === null || cap <= 0) return null
  const pct = Math.min(100, Math.round((used / cap) * 100))
  const tone = pct >= 100 ? 'bg-danger' : pct >= 90 ? 'bg-warning' : pct >= 75 ? 'bg-warning/70' : 'bg-brand-600'

  return (
    <div className="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-surface-secondary" role="meter" aria-valuemin={0} aria-valuemax={100} aria-valuenow={pct} data-testid={testId}>
      <div className={`h-full rounded-full ${tone}`} style={{ width: `${pct}%` }} />
    </div>
  )
}

/**
 * PRICING-USAGE-CREDITS-001 — this month's usage beside the plan.
 *
 * Usage on the workspace's own AI keys is listed key by key against the cap set on each key, and says plainly that it is paid to
 * their provider and never taken from the balance here. Platform-funded meters show the plan allowance and what credits paid.
 * Nothing on this card charges anything.
 */
export function UsageCard({ ar }: { ar: boolean }) {
  const usage = useQuery({ queryKey: ['subscriptions', 'usage'], queryFn: getUsage })
  if (!usage.data) return null
  const { meters, own_keys: keys, credits } = usage.data
  const platform = meters.filter((m) => m.funded_by === 'platform')

  return (
    <section data-testid="usage-card" className="rounded-2xl border border-border bg-surface p-5">
      <h2 className="flex items-center gap-2 font-heading text-base font-bold text-text-primary">
        <Gauge size={16} className="text-brand-600" aria-hidden /> {ar ? 'الاستهلاك هذا الشهر' : 'Usage this month'}
      </h2>

      <div data-testid="usage-credits" className="mt-3 flex items-center gap-2 rounded-xl bg-surface-secondary px-3 py-2 text-[13px]">
        <Wallet size={14} className="text-text-muted" aria-hidden />
        <span className="text-text-secondary">{ar ? 'رصيد الاستهلاك' : 'Usage balance'}</span>
        <span className="ms-auto font-semibold text-text-primary"><Num>{credits.balance}</Num> {credits.currency}</span>
      </div>

      <ul className="mt-3 grid gap-2">
        {keys.map((k) => <OwnKey key={k.id} k={k} ar={ar} />)}
        {platform.map((m) => <PlatformMeter key={m.code} m={m} ar={ar} />)}
      </ul>

      <p className="mt-3 text-[12px] text-text-muted">
        {keys.length > 0 && (ar
          ? 'استهلاك مفاتيح الذكاء الاصطناعي الخاصة بك يُدفع لمزوّدك مباشرة ولا يُخصم من رصيدك. '
          : 'Usage on your own AI keys is paid to your provider directly and never taken from your balance. ')}
        {ar ? 'نُنبّهك عند 75% و90% و100% من كل حد شهري. الأسعار لا تشمل ضريبة القيمة المضافة.'
          : 'You are alerted at 75%, 90% and 100% of each monthly cap. Prices exclude VAT.'}
      </p>
    </section>
  )
}

function OwnKey({ k, ar }: { k: OwnKeyUsage; ar: boolean }) {
  return (
    <li data-testid={`usage-key-${k.id}`} className="rounded-xl border border-border px-3 py-2 text-[13px]">
      <div className="flex flex-wrap items-center gap-2">
        <KeyRound size={14} className="text-text-muted" aria-hidden />
        <span className="font-semibold text-text-primary">{ar ? 'مفتاحك' : 'Your key'} · {k.provider}</span>
        <span className="text-[12px] text-text-muted"><bdi>{k.key}</bdi></span>
        <span data-testid={`usage-key-scope-${k.id}`} className="rounded-full bg-surface-secondary px-2 py-0.5 text-[11px] text-text-secondary">
          {k.scope === 'project' ? `${ar ? 'مشروع' : 'Project'}: ${k.scope_name ?? ''}`
            : k.scope === 'client' ? `${ar ? 'عميل' : 'Client'}: ${k.scope_name ?? ''}`
            : (ar ? 'كل مساحة العمل' : 'Whole workspace')}
        </span>
        <span className="ms-auto text-text-secondary">
          <Num>{fmt(k.used)}</Num>{k.monthly_token_limit !== null && <> / <Num>{fmt(k.monthly_token_limit)}</Num></>} {ar ? 'رمز' : 'tokens'}
        </span>
      </div>
      <Meter used={k.used} cap={k.monthly_token_limit} testId={`usage-key-meter-${k.id}`} />
    </li>
  )
}

function PlatformMeter({ m, ar }: { m: UsageMeterRow; ar: boolean }) {
  return (
    <li data-testid={`usage-meter-${m.code}`} className="rounded-xl border border-border px-3 py-2 text-[13px]">
      <div className="flex flex-wrap items-center gap-2">
        <span className="font-semibold text-text-primary">{ar ? m.name_ar : m.name}</span>
        <span className="ms-auto text-text-secondary">
          <Num>{fmt(m.used)}</Num>{m.included !== null && <> / <Num>{fmt(m.included)}</Num></>}
        </span>
      </div>
      <Meter used={m.used} cap={m.included} testId={`usage-meter-bar-${m.code}`} />
      {Number(m.credits_charged) > 0 && (
        <p className="mt-1 text-[12px] text-text-muted">{ar ? 'خُصم من الرصيد:' : 'Taken from balance:'} <Num>{m.credits_charged}</Num> {m.currency}</p>
      )}
    </li>
  )
}
