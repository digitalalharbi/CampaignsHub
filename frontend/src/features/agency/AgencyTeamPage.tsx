import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Loader2, PanelRightOpen, Plus, ShieldCheck, UserCheck, UserCog, Users, X } from 'lucide-react'
import {
  fetchAgencyTeam,
  grantClientScopes,
  withdrawClientScope,
  type AgencyClientCoverage,
  type AgencyTeamMember,
} from './api'
import { Button } from '@/components/ui/Button'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { SearchableSelect } from '@/components/forms'
import { toApiError } from '@/lib/api/client'
import { FitPage } from '@/components/fit/FitPage'
import { KpiMini, KpiStrip } from '@/components/fit/KpiMini'
import { TabStrip } from '@/components/fit/TabStrip'
import { Pager } from '@/components/fit/Pager'
import { ResponsiveList, type ListColumn } from '@/components/fit/ResponsiveList'
import { DetailPanel } from '@/components/fit/DetailPanel'
import { useFitRows } from '@/components/fit/useFitRows'
import { useUi } from '@/stores/ui'
import { useAuth } from '@/stores/auth'
import { AccessCenter } from '@/features/settings/access/AccessCenter'

/**
 * `/agency/team` — who is on the team, and which clients each of them may reach (AGENCY-004).
 *
 * The page keeps the shape of the underlying rules rather than flattening them into a save button:
 * adding a client is a separate action from withdrawing one, and there is no control that quietly
 * replaces a member's whole set. That distinction exists because collapsing it once meant "give this
 * manager one more client" silently removed the clients they already had.
 *
 * It also states the difference the model turns on: NO scopes is not "everything". Unrestricted
 * access is a permission a member either holds or does not, and the rows say which.
 */

/**
 * The membership roles this product writes, as names.
 *
 * These five are the whole vocabulary — `owner`, `member`, `account_manager`, `client_viewer` and
 * `creator` are every value written anywhere in the application — and none of them is
 * customer-named: no endpoint validates or accepts a custom one, unlike a tenant's Access roles,
 * which a customer may rename and which are therefore shown as the customer wrote them.
 *
 * A value not listed here shows as ITSELF rather than as a blank or a guess: a role the product does
 * not recognise is worth seeing. `projectRoleLabel` makes the same choice for the same reason.
 */
const MEMBERSHIP_ROLE_LABELS: Record<string, { ar: string; en: string }> = {
  owner: { ar: 'مالك', en: 'Owner' },
  member: { ar: 'عضو', en: 'Member' },
  account_manager: { ar: 'مدير الحساب', en: 'Account manager' },
  client_viewer: { ar: 'مُطّلع من جهة العميل', en: 'Client viewer' },
  creator: { ar: 'صانع محتوى', en: 'Creator' },
}

export function membershipRoleLabel(role: string, ar: boolean): string {
  const label = MEMBERSHIP_ROLE_LABELS[role]

  return label ? (ar ? label.ar : label.en) : role
}

type TeamTab = 'members' | 'coverage' | 'access'

export function AgencyTeamPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const canRunTeam = useAuth((s) => s.hasPermission('settings.manage'))
  const qc = useQueryClient()
  const team = useQuery({ queryKey: ['agency', 'team'], queryFn: fetchAgencyTeam })
  const [tab, setTab] = useState<TeamTab>('members')
  const [page, setPage] = useState(1)
  const [openId, setOpenId] = useState<string | null>(null)
  const fit = useFitRows({ min: 3, max: 30, fallback: 10 })

  const invalidate = () => void qc.invalidateQueries({ queryKey: ['agency', 'team'] })

  const grant = useMutation({ mutationFn: ({ id, clientIds }: { id: string; clientIds: string[] }) => grantClientScopes(id, clientIds), onSuccess: invalidate })
  const withdraw = useMutation({ mutationFn: ({ id, clientId }: { id: string; clientId: string }) => withdrawClientScope(id, clientId), onSuccess: invalidate })

  const error = grant.isError ? toApiError(grant.error) : withdraw.isError ? toApiError(withdraw.error) : null

  // Fewer rows fit after a resize, or a tab changes: stand on a page that exists.
  const perPage = fit.rows
  const total = tab === 'coverage' ? (team.data?.coverage?.length ?? 0) : (team.data?.members.length ?? 0)
  const pages = Math.max(1, Math.ceil(total / perPage))
  useEffect(() => { if (page > pages) setPage(pages) }, [page, pages])
  useEffect(() => setPage(1), [tab])

  /*
   * The head survives loading and failure — it is the answer to «where am I».
   *
   * Both states used to return INSTEAD of the header, so a reader waiting on a slow request, or
   * looking at a failed one, had no title, no purpose and no way to tell which page they were on.
   * That is precisely the moment the question is being asked. Caught by the cross-product walk,
   * which found `/agency/team` drawing no head at all on a run where the request had not settled.
   */
  const frame = (kpis: React.ReactNode, children: React.ReactNode, fitted = true) => (
    <FitPage
      testid="team-page"
      introTestid="team-intro"
      fit={fitted}
      title={ar ? 'الفريق والنطاقات' : 'Team & scopes'}
      info={ar ? 'كل عضو يصل إلى العملاء المذكورين باسمهم هنا — لا أكثر.' : 'Each member reaches the clients named here — and no others.'}
      kpis={kpis}
    >
      {children}
    </FitPage>
  )

  if (team.isLoading) {
    return frame(undefined, <div className="grid gap-3">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-16" />)}</div>)
  }

  if (team.isError || !team.data) {
    return frame(undefined, (
      <ErrorState
        error={team.error}
        title={ar ? 'تعذّر تحميل فريق الوكالة.' : 'The agency team could not be loaded.'}
        onRetry={() => void team.refetch()}
      />
    ))
  }

  const { members, can_manage: canManage, assignable_clients: assignable } = team.data
  const coverage = team.data.coverage ?? []
  const missing = coverage.filter((r) => !r.has_manager).length
  const open = members.find((m) => m.id === openId) ?? null

  const kpis = members.length === 0 ? undefined : (
    <KpiStrip testid="team-intro-kpis" cols={3}>
      <KpiMini label={ar ? 'الأعضاء' : 'Members'} value={members.length.toLocaleString('en-US')} tone="brand" icon={Users} testid="team-kpi-members" />
      <KpiMini label={ar ? 'مقيَّدون بعملاء' : 'Scoped to clients'} value={members.filter((m) => m.is_client_scoped).length.toLocaleString('en-US')} tone="info" icon={UserCog} testid="team-kpi-scoped" />
      {/*
        Unrestricted is counted from `has_unrestricted_permission` and never inferred from an
        empty scope list. That inference is the exact mistake the banner below warns about, and
        a card making it would state the opposite of the warning beside it.
      */}
      <KpiMini label={ar ? 'وصول غير مقيّد' : 'Unrestricted'} value={members.filter((m) => m.has_unrestricted_permission).length.toLocaleString('en-US')} tone="neutral" icon={ShieldCheck} testid="team-kpi-unrestricted" />
    </KpiStrip>
  )

  const tabs = [
    { key: 'members', label: ar ? 'الأعضاء' : 'Members', icon: Users, count: members.length },
    ...(coverage.length > 0 ? [{ key: 'coverage', label: ar ? 'متابعة العملاء' : 'Client coverage', icon: UserCheck, count: missing > 0 ? `⚠ ${missing}` : coverage.length }] : []),
    ...(canRunTeam ? [{ key: 'access', label: ar ? 'الصلاحيات' : 'Permissions', icon: ShieldCheck }] : []),
  ]
  const active = tabs.some((t) => t.key === tab) ? tab : 'members'
  const from = (page - 1) * perPage

  return frame(kpis, (
    <>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <TabStrip testid="team-tabs" label={ar ? 'أقسام الفريق' : 'Team sections'} items={tabs} active={active} onChange={(k) => setTab(k as TeamTab)} />
        {/* One line, always visible: the rule the whole page turns on is a safety statement, not decoration. */}
        <p className="flex min-w-0 items-center gap-1.5 text-sm text-text-secondary">
          <ShieldCheck size={16} className="shrink-0 text-info" aria-hidden />
          <span>
            {ar
              ? 'عدم وجود عملاء محدّدين لا يعني الوصول إلى الجميع. الوصول غير المقيّد صلاحية صريحة يحملها العضو أو لا يحملها.'
              : 'No named clients does not mean access to all of them. Unrestricted access is an explicit permission a member either holds or does not.'}
          </span>
        </p>
      </div>

      {!canManage && (
        <p className="mt-2 rounded-xl border border-border px-3 py-2 text-sm text-text-muted">
          {ar ? 'لديك صلاحية العرض فقط.' : 'You have view-only access here.'}
        </p>
      )}

      {error && (
        <p role="alert" className="mt-2 rounded-xl bg-[var(--negative-background)] px-3 py-2 text-sm text-danger">
          {error.message}
        </p>
      )}

      {active === 'access' ? (
        /*
          ACCESS-CENTER-001 — invitations, categories and per-person areas, for whoever runs the team. It is a
          long form of its own, so this tab lets the page flow instead of locking it (see `fit` above).
        */
        <div className="mt-3"><AccessCenter /></div>
      ) : (
        <div ref={fit.ref} className="mt-2 flex min-h-0 flex-1 flex-col">
          <div className="min-h-0 flex-1">
            {active === 'coverage' ? (
              <CoverageList rows={sortCoverage(coverage).slice(from, from + perPage)} ar={ar} />
            ) : members.length === 0 ? (
              <p className="rounded-xl border border-dashed border-border px-4 py-10 text-center text-sm text-text-muted">
                {ar ? 'لا أعضاء في بوابة الوكالة بعد.' : 'No members in the agency portal yet.'}
              </p>
            ) : (
              <MembersList rows={members.slice(from, from + perPage)} ar={ar} onOpen={(m) => setOpenId(m.id)} />
            )}
          </div>
          <Pager testid="team-pager" ar={ar} page={page} pages={pages} total={total} onPage={setPage} />
        </div>
      )}

      <DetailPanel
        open={open !== null}
        onClose={() => setOpenId(null)}
        title={open?.user.name ?? open?.user.email ?? '—'}
        closeLabel={ar ? 'إغلاق' : 'Close'}
        testid="team-member-detail"
      >
        {open && (
          <>
            <p className="mb-3 truncate text-xs text-text-muted" dir="ltr">{open.user.email}</p>
            <MemberAccess
              member={open}
              ar={ar}
              canManage={canManage}
              assignable={assignable}
              busy={
                (grant.isPending && grant.variables?.id === open.id) ||
                (withdraw.isPending && withdraw.variables?.id === open.id)
              }
              onGrant={(clientId) => grant.mutate({ id: open.id, clientIds: [clientId] })}
              onWithdraw={(clientId) => withdraw.mutate({ id: open.id, clientId })}
            />
          </>
        )}
      </DetailPanel>
    </>
  ), active !== 'access')
}

/** What a member reaches, in one chip — the full list and its controls are in the member's panel. */
function AccessChip({ member, ar }: { member: AgencyTeamMember; ar: boolean }) {
  if (member.has_unrestricted_permission) {
    return (
      <span data-testid="scope-unrestricted" className="inline-flex items-center gap-1.5 rounded-full bg-brand-primary-soft px-2.5 py-1 text-xs font-semibold text-brand-700">
        <ShieldCheck size={13} aria-hidden />
        {ar ? 'وصول غير مقيّد (صلاحية صريحة)' : 'Unrestricted (explicit permission)'}
      </span>
    )
  }
  if (member.clients.length === 0) {
    return <span data-testid="scope-none" className="text-xs text-text-muted">{ar ? 'لا يصل إلى أي عميل' : 'Reaches no clients'}</span>
  }
  const names = member.clients.map((c) => c.name ?? (ar ? 'عميل خارج نطاقك' : 'A client outside your access')).join('، ')

  return (
    <span title={names} className="inline-flex items-center gap-1.5 rounded-full bg-surface-secondary px-2.5 py-1 text-xs font-semibold text-text-primary">
      <Users size={13} aria-hidden />
      <span className="tnum">{member.clients.length}</span>
      <span className="truncate">{names}</span>
    </span>
  )
}

function MembersList({ rows, ar, onOpen }: { rows: AgencyTeamMember[]; ar: boolean; onOpen: (m: AgencyTeamMember) => void }) {
  const columns: ListColumn<AgencyTeamMember>[] = [
    {
      key: 'member',
      header: ar ? 'العضو' : 'Member',
      cell: (m) => (
        <div data-testid={`team-member-${m.user.email ?? m.id}`} className="min-w-0 leading-tight">
          <p className="truncate font-heading text-sm font-bold text-text-primary">{m.user.name ?? '—'}</p>
          <p className="truncate text-xs text-text-muted" dir="ltr">{m.user.email}</p>
        </div>
      ),
    },
    {
      key: 'role',
      header: ar ? 'الدور' : 'Role',
      width: '18%',
      cell: (m) => (
        /*
          The role as a name, not as the column's value.

          This printed `member.role` raw, so an otherwise Arabic page showed «member» three times
          and «account_manager» once — identifiers where every neighbouring word is a sentence.

          A lookup against the `roles` table would not have helped: a membership stores
          `account_manager` while the Access role carrying that meaning is slugged
          `account-manager`, so the two do not meet. These five are the whole vocabulary written
          anywhere in the product, and none is customer-named — nothing validates or accepts a
          custom one — so they can be written properly. An unknown value still shows as itself, on
          purpose: a role the product does not recognise is worth seeing rather than hiding.
        */
        <span data-testid={`team-member-role-${m.id}`} className="rounded-full bg-surface-secondary px-2.5 py-1 text-xs font-semibold text-text-secondary">
          {membershipRoleLabel(m.role, ar)}
        </span>
      ),
    },
    { key: 'access', header: ar ? 'الوصول إلى العملاء' : 'Client access', width: '42%', cell: (m) => <AccessChip member={m} ar={ar} /> },
    {
      key: 'open',
      header: '',
      width: '8%',
      align: 'end',
      cell: (m) => (
        <button
          type="button"
          onClick={(e) => { e.stopPropagation(); onOpen(m) }}
          aria-label={`${ar ? 'الوصول' : 'Access'}: ${m.user.name ?? m.user.email ?? ''}`}
          title={ar ? 'الوصول' : 'Access'}
          className="inline-flex h-9 w-9 items-center justify-center rounded-lg text-text-muted hover:bg-surface-hover hover:text-text-primary"
        >
          <PanelRightOpen size={17} aria-hidden />
        </button>
      ),
    },
  ]

  return (
    <div data-testid="agency-team">
      <ResponsiveList
        testid="team-list"
        rows={rows}
        rowKey={(m) => m.id}
        columns={columns}
        onOpen={onOpen}
        renderCard={(m) => (
          <button
            type="button"
            onClick={() => onOpen(m)}
            data-testid={`team-member-${m.user.email ?? m.id}`}
            aria-label={`${ar ? 'الوصول' : 'Access'}: ${m.user.name ?? m.user.email ?? ''}`}
            className="flex w-full min-w-0 flex-col gap-2 rounded-xl border border-border bg-surface p-3 text-start"
          >
            <span className="flex items-start justify-between gap-2">
              <span className="min-w-0">
                <span className="block truncate font-heading text-sm font-bold text-text-primary">{m.user.name ?? '—'}</span>
                <span className="block truncate text-xs text-text-muted" dir="ltr">{m.user.email}</span>
              </span>
              <span data-testid={`team-member-role-${m.id}`} className="shrink-0 rounded-full bg-surface-secondary px-2.5 py-1 text-xs font-semibold text-text-secondary">{membershipRoleLabel(m.role, ar)}</span>
            </span>
            <AccessChip member={m} ar={ar} />
          </button>
        )}
      />
    </div>
  )
}

function MemberAccess({
  member,
  ar,
  canManage,
  assignable,
  busy,
  onGrant,
  onWithdraw,
}: {
  member: AgencyTeamMember
  ar: boolean
  canManage: boolean
  assignable: { id: string; name: string }[]
  busy: boolean
  onGrant: (clientId: string) => void
  onWithdraw: (clientId: string) => void
}) {
  const [adding, setAdding] = useState(false)
  const [picked, setPicked] = useState<string | null>(null)

  // Only clients they do not already have — offering a duplicate implies it would do something.
  const options = assignable
    .filter((c) => !member.client_scope_ids.includes(c.id))
    .map((c) => ({ value: c.id, label: c.name }))

  return (
    <div className="min-w-0">
        <p className="text-xs font-semibold text-text-muted">
          {ar ? 'الوصول إلى العملاء' : 'Client access'}
        </p>
        {member.has_unrestricted_permission ? (
          <p data-testid="scope-unrestricted" className="mt-2 inline-flex items-center gap-1.5 rounded-full bg-brand-primary-soft px-3 py-1.5 text-xs font-semibold text-brand-700">
            <ShieldCheck size={14} aria-hidden />
            {ar ? 'وصول غير مقيّد (صلاحية صريحة)' : 'Unrestricted (explicit permission)'}
          </p>
        ) : member.clients.length === 0 ? (
          <p data-testid="scope-none" className="mt-2 text-xs text-text-muted">
            {ar
              ? 'لا يصل إلى أي عميل. أضف عميلًا ليبدأ العمل.'
              : 'Reaches no clients. Add one to give them work.'}
          </p>
        ) : (
          <ul className="mt-2 flex flex-wrap gap-2">
            {member.clients.map((c) => (
              <li key={c.id}>
                <span className="inline-flex items-center gap-1.5 rounded-full bg-surface-secondary py-1.5 ps-3 pe-1.5 text-xs font-semibold text-text-primary">
                  {/* A client outside the reader's own access is counted but not named. */}
                  {c.name ?? (ar ? 'عميل خارج نطاقك' : 'A client outside your access')}
                  {canManage && !member.is_self && c.name !== null && (
                    <button
                      type="button"
                      aria-label={ar ? `إزالة ${c.name}` : `Remove ${c.name}`}
                      disabled={busy}
                      onClick={() => onWithdraw(c.id)}
                      className="flex h-5 w-5 items-center justify-center rounded-full text-text-muted transition-colors hover:bg-danger/15 hover:text-danger disabled:opacity-50"
                    >
                      <X size={13} />
                    </button>
                  )}
                </span>
              </li>
            ))}
          </ul>
        )}

        {member.is_self && !member.has_unrestricted_permission && (
          <p data-testid="scope-self" className="mt-3 text-xs text-text-muted">
            {ar
              ? 'هذه عضويتك. لا يمكنك توسيع وصولك بنفسك — اطلب ذلك من زميل يملك صلاحية أوسع.'
              : 'This is your own membership. You cannot widen your own access — ask a colleague with wider access.'}
          </p>
        )}

        {canManage && !member.is_self && !member.has_unrestricted_permission && (
          <div className="mt-3">
            {adding ? (
              <div className="flex flex-wrap items-center gap-2">
                <div className="w-60">
                  <SearchableSelect
                    value={picked}
                    onChange={setPicked}
                    options={options}
                    placeholder={ar ? 'اختر عميلًا' : 'Choose a client'}
                  />
                </div>
                <Button
                  size="sm"
                  disabled={!picked || busy}
                  onClick={() => { if (picked) { onGrant(picked); setPicked(null); setAdding(false) } }}
                >
                  {busy ? <Loader2 size={14} className="animate-spin" /> : null}
                  {ar ? 'منح الوصول' : 'Grant access'}
                </Button>
                <Button size="sm" variant="ghost" onClick={() => { setAdding(false); setPicked(null) }}>
                  {ar ? 'إلغاء' : 'Cancel'}
                </Button>
              </div>
            ) : (
              <Button
                size="sm"
                variant="secondary"
                disabled={options.length === 0}
                onClick={() => setAdding(true)}
              >
                <Plus size={14} />
                {options.length === 0
                  ? (ar ? 'لا عملاء متاحون لك للمنح' : 'No clients of yours left to grant')
                  : (ar ? 'إضافة عميل' : 'Add a client')}
              </Button>
            )}
          </div>
        )}
    </div>
  )
}

function sortCoverage(rows: AgencyClientCoverage[]): AgencyClientCoverage[] {
  // Clients without a manager first, because they are the ones to act on.
  return [...rows].sort((a, b) => Number(a.has_manager) - Number(b.has_manager))
}

/**
 * TEAM-CLIENT-COVERAGE-001 — «من يتابع كل عميل، والعميل بلا مدير مشروع يظهر بعلامة» (Owner,
 * MASTER_FINAL Appendix A task 7). Clients without a manager first, because they are the ones to act on.
 */
function CoverageList({ rows, ar }: { rows: AgencyClientCoverage[]; ar: boolean }) {
  const people = (c: AgencyClientCoverage) => [...new Set([...c.managers, ...c.followers])]
  const columns: ListColumn<AgencyClientCoverage>[] = [
    {
      key: 'client',
      header: ar ? 'العميل' : 'Client',
      width: '34%',
      cell: (c) => <span data-testid={`coverage-${c.id}`} title={c.name} className="block truncate font-semibold text-text-primary">{c.name}</span>,
    },
    {
      key: 'manager',
      header: ar ? 'مدير المشروع' : 'Project manager',
      width: '22%',
      cell: (c) => c.has_manager
        ? <span className="inline-flex items-center gap-1 text-xs font-semibold text-brand-700"><UserCheck size={14} aria-hidden />{ar ? 'موجود' : 'Assigned'}</span>
        : (
          <span data-testid={`coverage-flag-${c.id}`} title={ar ? 'لا يوجد مدير مشروع لهذا العميل' : 'No project manager for this client'} className="inline-flex items-center gap-1 rounded-full bg-warning/15 px-2 py-0.5 text-xs font-semibold text-warning">
            <AlertTriangle size={12} aria-hidden /> {ar ? 'بدون مدير' : 'No manager'}
          </span>
        ),
    },
    {
      key: 'people',
      header: ar ? 'من يتابعه' : 'Followed by',
      cell: (c) => <span title={people(c).join('، ')} className="block truncate text-xs text-text-secondary">{people(c).length === 0 ? (ar ? 'لا أحد' : 'Nobody') : people(c).join('، ')}</span>,
    },
  ]

  return (
    <section data-testid="team-coverage" aria-label={ar ? 'متابعة العملاء' : 'Client coverage'}>
      <ResponsiveList
        testid="coverage-list"
        rows={rows}
        rowKey={(c) => c.id}
        columns={columns}
        renderCard={(c) => (
          <div data-testid={`coverage-card-${c.id}`} className={`min-w-0 rounded-xl border px-3 py-2.5 ${c.has_manager ? 'border-border bg-surface' : 'border-warning/50 bg-warning/5'}`}>
            <div className="flex items-center justify-between gap-2">
              <span className="truncate text-sm font-semibold text-text-primary">{c.name}</span>
              {!c.has_manager && <span className="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-warning"><AlertTriangle size={12} aria-hidden /> {ar ? 'بدون مدير' : 'No manager'}</span>}
            </div>
            <p className="mt-1 truncate text-xs text-text-secondary">{people(c).length === 0 ? (ar ? 'لا أحد' : 'Nobody') : people(c).join('، ')}</p>
          </div>
        )}
      />
    </section>
  )
}
