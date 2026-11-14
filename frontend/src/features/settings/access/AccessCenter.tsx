import { useMemo, useState } from 'react'
import { SlidersHorizontal, UserPlus } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Skeleton } from '@/components/ui/States'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { fmtDate } from '@/lib/datetime'
import { clients as countedClients } from '@/lib/counted'
import { useUi } from '@/stores/ui'
import { useAccess, type AccessData, type AccessInvitation, type AccessMember, type Scope } from './accessApi'
import { ChipPicker } from './AreaControls'
import { InviteDialog } from './InviteDialog'
import { AccessDrawer, type DrawerSubject } from './AccessDrawer'
import { CategoriesPanel } from './CategoriesPanel'
import { PORTAL_ROLE_LABEL, STATUS_LABEL } from './labels'

type Filter = 'all' | 'team' | 'client' | 'invites'

/**
 * ACCESS-CENTER-001 — «الفريق والنطاقات»: the one board of every member and every invitation, for every portal.
 *
 * Counts on top, the category spread as one bar, then one row per person — category chip, clients chip, status,
 * last activity — and a row opens its drawer. One «دعوة» button for every audience. Phone width stacks the row;
 * nothing scrolls sideways.
 */
export function AccessCenter() {
  const ar = useUi((u) => u.locale) === 'ar'
  const q = useAccess()
  const [filter, setFilter] = useState<Filter>('all')
  const [inviting, setInviting] = useState(false)
  const [subject, setSubject] = useState<DrawerSubject | null>(null)

  if (q.isLoading) return <div className="space-y-3"><Skeleton className="h-20" /><Skeleton className="h-64" /></div>
  if (q.isError || !q.data) {
    return <QueryFailure error={q.error} ar={ar} testId="access-failure" fallbackTitle={ar ? 'تعذّر تحميل الفريق.' : 'The team could not be loaded.'} />
  }
  const data = q.data

  return (
    <div className="space-y-4" data-testid="access-center">
      <Board data={data} ar={ar} filter={filter} setFilter={setFilter} onInvite={() => setInviting(true)} onOpen={setSubject} />
      <CategoriesPanel data={data} ar={ar} />
      <InviteDialog open={inviting} onClose={() => setInviting(false)} data={data} ar={ar} />
      <AccessDrawer subject={subject} onClose={() => setSubject(null)} data={data} ar={ar} />
    </div>
  )
}

function Board({ data, ar, filter, setFilter, onInvite, onOpen }: {
  data: AccessData; ar: boolean; filter: Filter; setFilter: (f: Filter) => void; onInvite: () => void; onOpen: (s: DrawerSubject) => void
}) {
  const team = data.members.filter((m) => m.audience === 'team')
  const clients = data.members.filter((m) => m.audience !== 'team')
  const pending = data.invitations.filter((i) => i.status === 'pending')
  const expired = data.invitations.filter((i) => i.status === 'expired')

  const spread = useMemo(() => {
    const counts = new Map<string, number>()
    for (const m of team) if (m.category) counts.set(m.category, (counts.get(m.category) ?? 0) + 1)
    return data.categories.filter((c) => counts.has(c.slug)).map((c) => ({ slug: c.slug, name: ar ? c.name_ar : c.name_en, n: counts.get(c.slug)! }))
  }, [team, data.categories, ar])

  const showMembers = filter === 'all' || filter === 'team' || filter === 'client'
  const members = filter === 'team' ? team : filter === 'client' ? clients : data.members
  const showInvites = filter === 'all' || filter === 'invites'

  return (
    <section className="rounded-2xl border border-border bg-surface p-4 shadow-[var(--shadow-small)]">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-base font-bold text-text-primary">{ar ? 'الفريق والنطاقات' : 'Team & scopes'}</h2>
        <Button size="sm" onClick={onInvite} data-testid="access-invite"><UserPlus size={14} aria-hidden /> {ar ? 'دعوة' : 'Invite'}</Button>
      </div>

      <ul className="mb-3 grid grid-cols-2 gap-2 sm:grid-cols-4" data-testid="access-counts">
        <Count label={ar ? 'الفريق' : 'Team'} n={team.length} testId="count-team" />
        <Count label={ar ? 'العملاء' : 'Clients'} n={clients.length} testId="count-clients" />
        <Count label={ar ? 'بانتظار القبول' : 'Pending'} n={pending.length} testId="count-pending" />
        <Count label={ar ? 'انتهت' : 'Expired'} n={expired.length} testId="count-expired" tone={expired.length > 0 ? 'warn' : undefined} />
      </ul>

      {spread.length > 0 && (
        <div className="mb-3" data-testid="access-spread" aria-label={ar ? 'الفريق حسب الفئة' : 'Team by category'}>
          <div className="flex h-2.5 w-full overflow-hidden rounded-full bg-surface-secondary">
            {spread.map((s, i) => (
              <div key={s.slug} title={`${s.name}: ${s.n}`} style={{ width: `${(s.n / team.length) * 100}%` }} className={SEGMENT[i % SEGMENT.length]} />
            ))}
          </div>
          <ul className="mt-1.5 flex flex-wrap gap-x-3 gap-y-1 text-xs text-text-secondary">
            {spread.map((s, i) => (
              <li key={s.slug} className="inline-flex items-center gap-1"><span className={`size-2 rounded-full ${SEGMENT[i % SEGMENT.length]}`} aria-hidden />{s.name} <span className="tnum font-semibold">{s.n.toLocaleString('en-US')}</span></li>
            ))}
          </ul>
        </div>
      )}

      <div className="mb-2">
        <ChipPicker<Filter> testId="access-filter" label={ar ? 'عرض' : 'Show'} value={filter} onChange={setFilter} options={[
          { value: 'all', label: ar ? 'الكل' : 'All' },
          { value: 'team', label: ar ? 'الفريق' : 'Team', count: team.length },
          { value: 'client', label: ar ? 'العملاء' : 'Clients', count: clients.length },
          { value: 'invites', label: ar ? 'الدعوات' : 'Invitations', count: data.invitations.length },
        ]} />
      </div>

      <ul className="divide-y divide-border" data-testid="access-rows">
        {showInvites && data.invitations.map((i) => <InvitationRow key={`i-${i.id}`} row={i} ar={ar} onOpen={() => onOpen({ kind: 'invitation', row: i })} />)}
        {showMembers && members.map((m) => <MemberRow key={`m-${m.id}`} row={m} ar={ar} onOpen={() => onOpen({ kind: 'member', row: m })} />)}
        {showInvites && data.revoked.map((r) => (
          <li key={`r-${r.id}`} className="flex flex-wrap items-center gap-2 py-2 text-xs text-text-muted" data-testid="access-row-revoked">
            <span dir="ltr" className="min-w-0 truncate">{r.email}</span>
            <StatusChip status="revoked" ar={ar} />
            <span>{fmtDate(r.at)}</span>
          </li>
        ))}
      </ul>
    </section>
  )
}

const SEGMENT = ['bg-brand-600', 'bg-brand-300', 'bg-success', 'bg-warning', 'bg-text-muted']

function Count({ label, n, testId, tone }: { label: string; n: number; testId: string; tone?: 'warn' }) {
  return (
    <li data-testid={testId} className="rounded-xl border border-border px-3 py-2">
      <div className="text-xs text-text-muted">{label}</div>
      <div className={`tnum text-lg font-bold ${tone === 'warn' ? 'text-warning' : 'text-text-primary'}`}>{n.toLocaleString('en-US')}</div>
    </li>
  )
}

function StatusChip({ status, ar }: { status: string; ar: boolean }) {
  const tone = status === 'active' ? 'bg-[var(--positive-background)] text-success'
    : status === 'disabled' || status === 'revoked' ? 'bg-surface-secondary text-text-muted'
      : status === 'expired' ? 'bg-[var(--warning-background)] text-warning' : 'bg-brand-50 text-brand-700'
  return <span data-testid={`status-${status}`} className={`rounded-full px-2 py-0.5 text-xs font-semibold ${tone}`}>{ar ? STATUS_LABEL[status]?.ar : STATUS_LABEL[status]?.en}</span>
}

function CategoryChip({ row, ar }: { row: AccessMember | AccessInvitation; ar: boolean }) {
  const name = row.audience !== 'team'
    ? (ar ? PORTAL_ROLE_LABEL[row.category ?? '']?.ar : PORTAL_ROLE_LABEL[row.category ?? '']?.en) ?? row.category
    : (ar ? row.category_name_ar : row.category_name_en) ?? row.category
  const custom = row.overrides.allow + row.overrides.deny > 0
  return (
    <span className="inline-flex max-w-full items-center gap-1 truncate rounded-full border border-border px-2 py-0.5 text-xs font-semibold text-text-secondary" data-testid="row-category">
      {row.audience !== 'team' && <span className="text-text-muted">{ar ? 'بوابة' : 'Portal'} ·</span>}
      {name}
      {custom && <span className="inline-flex items-center gap-0.5 text-warning" title={ar ? 'مخصّص' : 'Customised'}><SlidersHorizontal size={11} aria-hidden />{ar ? 'مخصّص' : 'custom'}</span>}
    </span>
  )
}

function ScopeChip({ scope, ar }: { scope: Scope; ar: boolean }) {
  if (scope.all) return <span className="rounded-full bg-surface-secondary px-2 py-0.5 text-xs text-text-secondary">{ar ? 'كل العملاء' : 'Every client'}</span>
  if (scope.clients.length === 0) return null
  const label = scope.clients.length === 1 ? scope.clients[0].name : countedClients(scope.clients.length, ar ? 'ar' : 'en')
  return <span className="max-w-[10rem] truncate rounded-full bg-surface-secondary px-2 py-0.5 text-xs text-text-secondary" title={scope.clients.map((c) => c.name).join('، ')}>{label}</span>
}

function MemberRow({ row, ar, onOpen }: { row: AccessMember; ar: boolean; onOpen: () => void }) {
  return (
    <li>
      <button type="button" onClick={onOpen} data-testid={`access-row-member-${row.email}`} className="flex w-full min-w-0 flex-wrap items-center gap-x-3 gap-y-1 py-2.5 text-start hover:bg-surface-secondary/50">
        <span className="min-w-0 flex-1 basis-48">
          <span className="block truncate text-sm font-semibold text-text-primary">{row.name}{row.is_self && <span className="ms-1 text-xs font-normal text-text-muted">({ar ? 'أنت' : 'you'})</span>}</span>
          <span className="block truncate text-xs text-text-muted" dir="ltr">{row.email}</span>
        </span>
        <CategoryChip row={row} ar={ar} />
        <ScopeChip scope={row.scope} ar={ar} />
        <StatusChip status={row.status} ar={ar} />
        <span className="tnum text-xs text-text-muted">{row.last_login_at ? fmtDate(row.last_login_at) : '—'}</span>
      </button>
    </li>
  )
}

function InvitationRow({ row, ar, onOpen }: { row: AccessInvitation; ar: boolean; onOpen: () => void }) {
  return (
    <li>
      <button type="button" onClick={onOpen} data-testid={`access-row-invitation-${row.email}`} className="flex w-full min-w-0 flex-wrap items-center gap-x-3 gap-y-1 py-2.5 text-start hover:bg-surface-secondary/50">
        <span className="min-w-0 flex-1 basis-48 truncate text-sm font-semibold text-text-primary" dir="ltr">{row.email}</span>
        <CategoryChip row={row} ar={ar} />
        <ScopeChip scope={row.scope} ar={ar} />
        <StatusChip status={row.status} ar={ar} />
        <span className="tnum text-xs text-text-muted">{fmtDate(row.created_at)}</span>
      </button>
    </li>
  )
}
