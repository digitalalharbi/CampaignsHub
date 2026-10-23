import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Trash2, UserPlus } from 'lucide-react'
import { addProjectMember, fetchMemberEffective, listProjectTeam, listUsers, removeProjectMember, updateProjectMember } from './api'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardTitle } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { Select } from '@/components/ui/Select'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { usePortalPath } from '@/app/portalPath'
import { useT } from '@/lib/i18n'
import { useUi } from '@/stores/ui'

/**
 * PROJECT-ROLE-LABEL-001 — the role picker offered its own column values.
 *
 * `options={ROLES.map((r) => ({ value: r, label: r }))}` — the label WAS the key, so an operator
 * assigning somebody to a project chose between «account_manager» and «media_buyer» in an otherwise
 * Arabic page. Unlike a tenant role, none of these is customer-named: the list is fixed in
 * `ProjectMembershipController::ROLES`, so every one of them can and should be written properly.
 *
 * `projectRoles.test.ts` asserts this map matches that PHP list exactly, so a role added on one side
 * and not the other fails a test instead of reaching an operator as an identifier.
 */
export const PROJECT_ROLE_LABELS: Record<string, { ar: string; en: string }> = {
  account_manager: { ar: 'مدير الحساب', en: 'Account manager' },
  media_buyer: { ar: 'مشتري وسائط', en: 'Media buyer' },
  analyst: { ar: 'محلّل', en: 'Analyst' },
  content: { ar: 'محتوى', en: 'Content' },
  finance: { ar: 'مالية', en: 'Finance' },
  client_admin: { ar: 'مسؤول من جهة العميل', en: 'Client admin' },
  client_approver: { ar: 'معتمِد من جهة العميل', en: 'Client approver' },
  client_viewer: { ar: 'مُطّلع من جهة العميل', en: 'Client viewer' },
  viewer: { ar: 'مُطّلع', en: 'Viewer' },
}

export function projectRoleLabel(role: string, ar: boolean): string {
  const label = PROJECT_ROLE_LABELS[role]

  // An unrecognised role shows as itself: a value the product does not know is worth seeing.
  return label ? (ar ? label.ar : label.en) : role
}

const ROLES = Object.keys(PROJECT_ROLE_LABELS)

/**
 * MEMBER-VISIBILITY-001 — one label per project capability, the backend's `ProjectCapability::ALL`
 * vocabulary; an unknown token prints as itself so a newer server never shows an empty chip.
 */
const CAPABILITY_LABELS: Record<string, [string, string]> = {
  'dashboard.view': ['لوحة المشروع', 'Dashboard'],
  'analytics.view': ['التحليلات', 'Analytics'],
  'campaigns.view': ['عرض الحملات', 'View campaigns'],
  'campaigns.manage': ['إدارة الحملات', 'Manage campaigns'],
  'leads.view': ['عرض العملاء المحتملين', 'View leads'],
  'leads.pii.view': ['بيانات العملاء الشخصية', 'Lead personal data'],
  'leads.assign': ['إسناد العملاء المحتملين', 'Assign leads'],
  'leads.update': ['تحديث العملاء المحتملين', 'Update leads'],
  'leads.export': ['تصدير العملاء المحتملين', 'Export leads'],
  'tasks.view': ['عرض المهام', 'View tasks'],
  'tasks.manage': ['إدارة المهام', 'Manage tasks'],
  'reports.view': ['عرض التقارير', 'View reports'],
  'reports.manage': ['إدارة التقارير', 'Manage reports'],
  'budget.view': ['عرض الميزانية', 'View budget'],
  'budget.manage': ['إدارة الميزانية', 'Manage budget'],
  'team.manage': ['إدارة الفريق', 'Manage team'],
  'integrations.manage': ['إدارة الربط', 'Manage integrations'],
  'settings.manage': ['إعدادات المشروع', 'Project settings'],
  'money.view': ['الأرقام المالية', 'Money'],
}

export function capabilityLabel(capability: string, ar: boolean): string {
  const pair = CAPABILITY_LABELS[capability]
  return pair ? (ar ? pair[0] : pair[1]) : capability
}

export function ProjectTeamPage() {
  const t = useT()
  const ar = useUi((s) => s.locale) === 'ar'
  const portalPath = usePortalPath()
  const queryClient = useQueryClient()
  const { projectId = '' } = useParams()
  const [userId, setUserId] = useState('')
  const [role, setRole] = useState('account_manager')

  const team = useQuery({
    queryKey: ['project', projectId, 'team'],
    queryFn: () => listProjectTeam(projectId),
    enabled: Boolean(projectId),
  })
  const users = useQuery({ queryKey: ['users'], queryFn: listUsers })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['project', projectId, 'team'] })
  const addMutation = useMutation({
    mutationFn: () => addProjectMember(projectId, { user_id: Number(userId), role }),
    onSuccess: () => {
      setUserId('')
      invalidate()
    },
  })
  /*
   * MEMBER-VISIBILITY-001 — the money, per member. Withheld on the server: the toggle writes the
   * membership's deny list and every project-scoped answer to that person loses its money keys.
   */
  const denyMutation = useMutation({
    mutationFn: ({ id, denied }: { id: string; denied: string[] }) => updateProjectMember(projectId, id, { denied }),
    onSuccess: invalidate,
  })
  const toggleDenied = (m: { id: string; denied?: string[] }, capability: string) => {
    const current = m.denied ?? []
    const denied = current.includes(capability) ? current.filter((c) => c !== capability) : [...current, capability]
    denyMutation.mutate({ id: m.id, denied })
  }
  /** MEMBER-VISIBILITY-001 — the effective-access preview: the server's answer, per member, on demand. */
  const [previewFor, setPreviewFor] = useState<string | null>(null)
  const preview = useQuery({
    queryKey: ['project-member-effective', projectId, previewFor],
    queryFn: () => fetchMemberEffective(projectId, previewFor as string),
    enabled: previewFor !== null,
  })

  const removeMutation = useMutation({
    mutationFn: (membershipId: string) => removeProjectMember(projectId, membershipId),
    onSuccess: invalidate,
  })

  const addError = addMutation.isError ? toApiError(addMutation.error) : null
  const removeError = removeMutation.isError ? toApiError(removeMutation.error) : null

  return (
    <section className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="font-[var(--font-heading)] text-xl font-extrabold">{t('project_team')}</h1>
          <p className="mt-1 text-sm text-text-secondary">{t('project_team_hint')}</p>
        </div>
        <Link to={portalPath(`/projects/${projectId}/integrations`)} className="text-xs font-bold text-brand-600 hover:underline">
          {t('integrations')} →
        </Link>
      </div>

      {removeError && <Alert severity="warning" title={removeError.message} />}

      <Card>
        <CardTitle>{t('add_member')}</CardTitle>
        <div className="mt-3 flex flex-wrap items-end gap-3">
          <Field label={t('name')}>
            <Select
              value={userId}
              onChange={(e) => setUserId(e.target.value)}
              placeholder="—"
              options={(users.data ?? []).map((u) => ({ value: String(u.id), label: `${u.name} · ${u.email}` }))}
            />
          </Field>
          <Field label={t('role')}>
            <Select value={role} onChange={(e) => setRole(e.target.value)} options={ROLES.map((r) => ({ value: r, label: projectRoleLabel(r, ar) }))} />
          </Field>
          <Button loading={addMutation.isPending} disabled={!userId} onClick={() => addMutation.mutate()}>
            <UserPlus size={15} /> {t('add')}
          </Button>
        </div>
        {addError && !addError.errors && <p className="mt-2 text-xs text-danger">{addError.message}</p>}
      </Card>

      <Card>
        <CardTitle>{t('members')}</CardTitle>
        {team.isLoading ? (
          <div className="mt-3 space-y-2">
            <Skeleton className="h-10 w-full" />
          </div>
        ) : (team.data?.length ?? 0) === 0 ? (
          <div className="mt-3">
            <EmptyState title={t('no_members')} />
          </div>
        ) : (
          <div className="mt-3 space-y-2">
            {team.data?.map((m) => (
              <div key={m.id} className="flex flex-wrap items-center justify-between gap-2 rounded-[9px] border border-border p-3">
                <div>
                  <span className="text-sm font-bold">{m.name}</span>
                  <span className="ms-2 text-xs text-text-muted">{m.email}</span>
                </div>
                <div className="flex items-center gap-2">
                  {/*
                    PROJECT-ROLE-LABEL-001 — the same map the picker uses, one line below it.

                    The picker was fixed to stop offering «account_manager» in an Arabic page; the
                    LIST kept printing the stored value, so the role somebody had just chosen from a
                    translated menu came back as its column value. A fix applied to one of two places
                    is the shape of defect this file already carries a docblock about.
                  */}
                  <Badge tone="info">{projectRoleLabel(m.role, ar)}</Badge>
                  {(() => {
                    const hidden = (m.denied ?? []).includes('money.view')
                    const piiHidden = (m.denied ?? []).includes('leads.pii.view')
                    return (
                      <>
                        <button
                          type="button"
                          onClick={() => toggleDenied(m, 'money.view')}
                          disabled={denyMutation.isPending}
                          aria-pressed={hidden}
                          data-testid={`member-money-${m.id}`}
                          title={ar ? 'تُحجب الأرقام المالية (الإنفاق والإيراد والتكاليف) عن هذا العضو في هذا المشروع من الخادم، بما فيها التصدير.' : 'Money (spend, revenue, costs) is withheld from this member on this project by the server, exports included.'}
                          className={`rounded-lg border px-2 py-1 text-[11px] font-bold ${hidden ? 'border-warning text-warning' : 'border-border text-text-secondary'}`}
                        >
                          {hidden ? (ar ? 'الأرقام المالية مخفية' : 'Money hidden') : (ar ? 'الأرقام المالية ظاهرة' : 'Money visible')}
                        </button>
                        <button
                          type="button"
                          onClick={() => toggleDenied(m, 'leads.pii.view')}
                          disabled={denyMutation.isPending}
                          aria-pressed={piiHidden}
                          data-testid={`member-pii-${m.id}`}
                          title={ar ? 'تُحجب بيانات العملاء المحتملين الشخصية (الأسماء وأرقام الهواتف والبريد) عن هذا العضو في هذا المشروع.' : 'Lead personal data (names, phones, emails) is withheld from this member on this project.'}
                          className={`rounded-lg border px-2 py-1 text-[11px] font-bold ${piiHidden ? 'border-warning text-warning' : 'border-border text-text-secondary'}`}
                        >
                          {piiHidden ? (ar ? 'بيانات العملاء مخفية' : 'Lead data hidden') : (ar ? 'بيانات العملاء ظاهرة' : 'Lead data visible')}
                        </button>
                        <button
                          type="button"
                          onClick={() => setPreviewFor(previewFor === m.id ? null : m.id)}
                          aria-expanded={previewFor === m.id}
                          data-testid={`member-effective-${m.id}`}
                          className="rounded-lg border border-border px-2 py-1 text-[11px] font-bold text-text-secondary"
                        >
                          {ar ? 'ما يستطيع فعله' : 'What they can do'}
                        </button>
                      </>
                    )
                  })()}
                  <button
                    type="button"
                    onClick={() => removeMutation.mutate(m.id)}
                    className="rounded p-1 text-text-muted hover:text-danger"
                    aria-label={t('remove')}
                  >
                    <Trash2 size={15} />
                  </button>
                </div>
                {previewFor === m.id && (
                  <div className="basis-full rounded-lg bg-surface-2 p-3 text-xs" data-testid={`member-effective-panel-${m.id}`}>
                    {preview.isLoading ? (
                      <Skeleton className="h-6 w-full" />
                    ) : preview.isError ? (
                      <p className="text-danger">{toApiError(preview.error).message}</p>
                    ) : preview.data ? (
                      <>
                        <p className="font-bold">{ar ? 'الصلاحيات الفعلية في هذا المشروع' : 'Effective access in this project'}</p>
                        <ul className="mt-1 flex flex-wrap gap-1">
                          {preview.data.effective.map((c) => (
                            <li key={c} className="rounded border border-border px-1.5 py-0.5">{capabilityLabel(c, ar)}</li>
                          ))}
                        </ul>
                        {preview.data.withheld.length > 0 && (
                          <>
                            <p className="mt-2 font-bold text-text-secondary">{ar ? 'محجوب عنه' : 'Withheld'}</p>
                            <ul className="mt-1 flex flex-wrap gap-1">
                              {preview.data.withheld.map((c) => (
                                <li key={c} className="rounded border border-dashed border-border px-1.5 py-0.5 text-text-muted">{capabilityLabel(c, ar)}</li>
                              ))}
                            </ul>
                          </>
                        )}
                      </>
                    ) : null}
                  </div>
                )}
              </div>
            ))}
          </div>
        )}
      </Card>
    </section>
  )
}
