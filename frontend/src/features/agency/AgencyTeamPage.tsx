import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2, Plus, ShieldCheck, X } from 'lucide-react'
import {
  fetchAgencyTeam,
  grantClientScopes,
  withdrawClientScope,
  type AgencyTeamMember,
} from './api'
import { Button } from '@/components/ui/Button'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { SearchableSelect } from '@/components/forms'
import { toApiError } from '@/lib/api/client'
import { PageIntro } from '@/components/ui/PageIntro'
import { StatCard } from '@/components/ui/StatCard'
import { useUi } from '@/stores/ui'

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

export function AgencyTeamPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const qc = useQueryClient()
  const team = useQuery({ queryKey: ['agency', 'team'], queryFn: fetchAgencyTeam })

  const invalidate = () => void qc.invalidateQueries({ queryKey: ['agency', 'team'] })

  const grant = useMutation({ mutationFn: ({ id, clientIds }: { id: string; clientIds: string[] }) => grantClientScopes(id, clientIds), onSuccess: invalidate })
  const withdraw = useMutation({ mutationFn: ({ id, clientId }: { id: string; clientId: string }) => withdrawClientScope(id, clientId), onSuccess: invalidate })

  const error = grant.isError ? toApiError(grant.error) : withdraw.isError ? toApiError(withdraw.error) : null

  /*
   * The head survives loading and failure — it is the answer to «where am I».
   *
   * Both states used to return INSTEAD of the header, so a reader waiting on a slow request, or
   * looking at a failed one, had no title, no purpose and no way to tell which page they were on.
   * That is precisely the moment the question is being asked. Caught by the cross-product walk,
   * which found `/agency/team` drawing no head at all on a run where the request had not settled.
   */
  const head = (
    <PageIntro
      testid="team-intro"
      title={ar ? 'الفريق والنطاقات' : 'Team & scopes'}
      purpose={ar
        ? 'كل عضو يصل إلى العملاء المذكورين باسمهم هنا — لا أكثر.'
        : 'Each member reaches the clients named here — and no others.'}
      kpis={team.data === undefined || team.data.members.length === 0 ? undefined : (
        <>
          <StatCard
            label={ar ? 'الأعضاء' : 'Members'}
            value={team.data.members.length.toLocaleString('en-US')}
            tone="brand"
            dot
            testid="team-kpi-members"
          />
          <StatCard
            label={ar ? 'مقيَّدون بعملاء' : 'Scoped to clients'}
            value={team.data.members.filter((m) => m.is_client_scoped).length.toLocaleString('en-US')}
            tone="info"
            dot
            testid="team-kpi-scoped"
          />
          {/*
            Unrestricted is counted from `has_unrestricted_permission` and never inferred from an
            empty scope list. That inference is the exact mistake the banner below warns about, and
            a card making it would state the opposite of the warning beside it.
          */}
          <StatCard
            label={ar ? 'وصول غير مقيّد' : 'Unrestricted'}
            value={team.data.members.filter((m) => m.has_unrestricted_permission).length.toLocaleString('en-US')}
            tone="neutral"
            dot
            testid="team-kpi-unrestricted"
          />
        </>
      )}
    />
  )

  if (team.isLoading) {
    return (
      <div className="w-full">
        {head}
        <div className="mt-4 grid gap-3">
          {[0, 1, 2].map((i) => <Skeleton key={i} className="h-28" />)}
        </div>
      </div>
    )
  }

  if (team.isError || !team.data) {
    return (
      <div className="w-full">
        {head}
        <div className="mt-4">
          <ErrorState
            error={team.error}
            title={ar ? 'تعذّر تحميل فريق الوكالة.' : 'The agency team could not be loaded.'}
            onRetry={() => void team.refetch()}
          />
        </div>
      </div>
    )
  }

  const { members, can_manage: canManage, assignable_clients: assignable } = team.data

  return (
    <div className="w-full">
      {head}

      <div className="mb-4 flex items-start gap-2.5 rounded-xl border border-border bg-surface-secondary px-4 py-3 text-sm text-text-secondary">
        <ShieldCheck size={17} className="mt-0.5 shrink-0 text-info" aria-hidden />
        <span>
          {ar
            ? 'عدم وجود عملاء محدّدين لا يعني الوصول إلى الجميع. الوصول غير المقيّد صلاحية صريحة يحملها العضو أو لا يحملها.'
            : 'No named clients does not mean access to all of them. Unrestricted access is an explicit permission a member either holds or does not.'}
        </span>
      </div>

      {!canManage && (
        <p className="mb-4 rounded-xl border border-border px-4 py-3 text-sm text-text-muted">
          {ar ? 'لديك صلاحية العرض فقط.' : 'You have view-only access here.'}
        </p>
      )}

      {error && (
        <p role="alert" className="mb-4 rounded-xl bg-[var(--negative-background)] px-4 py-3 text-sm text-danger">
          {error.message}
        </p>
      )}

      {members.length === 0 ? (
        <p className="rounded-xl border border-dashed border-border px-4 py-10 text-center text-sm text-text-muted">
          {ar ? 'لا أعضاء في بوابة الوكالة بعد.' : 'No members in the agency portal yet.'}
        </p>
      ) : (
        <ul data-testid="agency-team" className="grid gap-3">
          {members.map((m) => (
            <MemberRow
              key={m.id}
              member={m}
              ar={ar}
              canManage={canManage}
              assignable={assignable}
              busy={
                (grant.isPending && grant.variables?.id === m.id) ||
                (withdraw.isPending && withdraw.variables?.id === m.id)
              }
              onGrant={(clientId) => grant.mutate({ id: m.id, clientIds: [clientId] })}
              onWithdraw={(clientId) => withdraw.mutate({ id: m.id, clientId })}
            />
          ))}
        </ul>
      )}
    </div>
  )
}

function MemberRow({
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
    <li data-testid={`team-member-${member.user.email ?? member.id}`} className="rounded-2xl border border-border bg-surface p-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="font-heading text-[15px] font-bold text-text-primary">{member.user.name ?? '—'}</p>
          <p className="mt-0.5 truncate text-[13px] text-text-muted" dir="ltr">{member.user.email}</p>
        </div>
        {/*
          The role as a name, not as the column's value.

          This printed `member.role` raw, so an otherwise Arabic page showed «member» three times
          and «account_manager» once — identifiers where every neighbouring word is a sentence.

          A lookup against the `roles` table would not have helped: a membership stores
          `account_manager` while the Access role carrying that meaning is slugged
          `account-manager`, so the two do not meet. These five are the whole vocabulary written
          anywhere in the product, and none is customer-named — nothing validates or accepts a
          custom one — so they can be written properly. An unknown value still shows as itself, on
          purpose: a role the product does not recognise is worth seeing rather than hiding.
        */}
        <span
          data-testid={`team-member-role-${member.id}`}
          className="rounded-full bg-surface-secondary px-2.5 py-1 text-[11px] font-semibold text-text-secondary"
        >
          {membershipRoleLabel(member.role, ar)}
        </span>
      </div>

      <div className="mt-4">
        <p className="text-[12px] font-semibold uppercase tracking-wide text-text-muted">
          {ar ? 'الوصول إلى العملاء' : 'Client access'}
        </p>

        {member.has_unrestricted_permission ? (
          <p data-testid="scope-unrestricted" className="mt-2 inline-flex items-center gap-1.5 rounded-full bg-brand-primary-soft px-3 py-1.5 text-[13px] font-semibold text-brand-700">
            <ShieldCheck size={14} aria-hidden />
            {ar ? 'وصول غير مقيّد (صلاحية صريحة)' : 'Unrestricted (explicit permission)'}
          </p>
        ) : member.clients.length === 0 ? (
          <p data-testid="scope-none" className="mt-2 text-[13px] text-text-muted">
            {ar
              ? 'لا يصل إلى أي عميل. أضف عميلًا ليبدأ العمل.'
              : 'Reaches no clients. Add one to give them work.'}
          </p>
        ) : (
          <ul className="mt-2 flex flex-wrap gap-2">
            {member.clients.map((c) => (
              <li key={c.id}>
                <span className="inline-flex items-center gap-1.5 rounded-full bg-surface-secondary py-1.5 ps-3 pe-1.5 text-[13px] font-semibold text-text-primary">
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
          <p data-testid="scope-self" className="mt-3 text-[12px] text-text-muted">
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
    </li>
  )
}
