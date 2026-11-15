import { useState, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, ShieldCheck } from 'lucide-react'
import { Modal } from '@/components/ui/Modal'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { useAuth } from '@/stores/auth'
import { days as countedDays } from '@/lib/counted'
import { listClientWorkspaces } from '@/features/projects/api'
import {
  archiveClientWithProjects,
  archiveProjectWithEffects,
  getArchiveImpact,
  getClientArchiveImpact,
  getDeletionImpact,
  getMoveImpact,
  getRestoreImpact,
  lifecycleLabel,
  lifecycleTone,
  lt,
  moveProject,
  purgeProject,
  restoreProject,
  softDeleteProject,
  type LifecyclePermissions,
  type ProjectRowAction,
  type SharedAccount,
} from '@/lib/projectLifecycle'

/**
 * PROJECT-LIFECYCLE-001 §4 §5 §8 (builder N2) — the confirm windows: archive, delete, restore, permanent delete, move,
 * and «أرشفة العميل». Each window asks the server what will happen BEFORE it happens (impact endpoints), states that
 * the ad platforms are unaffected, warns about shared accounts, and — for the destructive ones — requires the name to be
 * typed; the server checks the same name. Shared by every workspace type (no type-specific code).
 */

export interface LifecycleTarget {
  id: string
  name: string
  client_workspace_id: string
  status: string
}

export type LifecycleDialogState = { kind: Exclude<ProjectRowAction, 'open'>; project: LifecycleTarget } | null

export function useLifecyclePermissions(): LifecyclePermissions {
  const has = useAuth((s) => s.hasPermission)

  return {
    archive: has('projects.archive'),
    restore: has('projects.restore'),
    delete: has('projects.delete'),
    purge: has('projects.purge'),
    move: has('projects.move'),
  }
}

export function SharedAccountsWarning({ accounts, ar, testid = 'shared-accounts-warning' }: { accounts: SharedAccount[]; ar: boolean; testid?: string }) {
  if (accounts.length === 0) return null

  return (
    <div data-testid={testid} role="note" className="rounded-xl border border-warning bg-warning-soft p-3 text-sm">
      <p className="flex items-center gap-1.5 font-bold text-warning"><AlertTriangle size={15} aria-hidden /> {lt('projects.lifecycle.shared.title', ar)}</p>
      <p className="mt-1 text-text-secondary">{lt('projects.lifecycle.shared.body', ar)}</p>
      <ul className="mt-2 grid gap-1">
        {accounts.map((a) => (
          <li key={a.external_account_id} className="flex min-w-0 flex-wrap items-center gap-1.5 text-xs text-text-secondary">
            <Badge tone="warning">{lt('projects.lifecycle.shared.badge', ar)}</Badge>
            <span className="truncate font-semibold text-text-primary">{a.name ?? a.external_id ?? a.provider}</span>
            <span className="truncate">← {a.other_projects.map((p) => p.name).join('، ')}</span>
          </li>
        ))}
      </ul>
    </div>
  )
}

function PlatformsUntouched({ ar }: { ar: boolean }) {
  return (
    <p data-testid="platforms-untouched" className="flex items-start gap-1.5 rounded-xl bg-surface-secondary p-3 text-xs text-text-secondary">
      <ShieldCheck size={14} className="mt-0.5 shrink-0 text-success" aria-hidden /> {lt('projects.lifecycle.platforms_untouched', ar)}
    </p>
  )
}

function TypedName({ id, expected, value, onChange, label }: { id: string; expected: string; value: string; onChange: (v: string) => void; label: string }) {
  return (
    <label htmlFor={id} className="grid gap-1 text-xs font-semibold text-text-secondary">
      <span>{label}: <bdi className="font-bold text-text-primary">{expected}</bdi></span>
      <Input id={id} data-testid={id} value={value} onChange={(e) => onChange(e.target.value)} autoComplete="off" dir="auto" />
    </label>
  )
}

const matches = (typed: string, expected: string) => typed.trim() !== '' && typed.trim() === expected.trim()

function Failure({ error }: { error: unknown }) {
  if (!error) return null

  return <p role="alert" data-testid="lifecycle-dialog-error" className="text-sm font-semibold text-danger">{toApiError(error).message}</p>
}

function Footer({ ar, onClose, confirm, disabled, pending, danger, testid }: { ar: boolean; onClose: () => void; confirm: string; disabled: boolean; pending: boolean; danger?: boolean; testid: string }) {
  return (
    <div className="flex flex-wrap justify-end gap-2">
      <Button variant="ghost" onClick={onClose}>{lt('projects.lifecycle.action.cancel', ar)}</Button>
      <Button data-testid={testid} variant={danger ? 'danger' : 'primary'} disabled={disabled || pending} type="submit" form="lifecycle-dialog-form">{confirm}</Button>
    </div>
  )
}

function Body({ children, onSubmit }: { children: ReactNode; onSubmit: () => void }) {
  return (
    <form id="lifecycle-dialog-form" className="grid gap-3" onSubmit={(e) => { e.preventDefault(); onSubmit() }}>
      {children}
    </form>
  )
}

/** The invalidations every lifecycle act needs: the lists, the client, the switcher, the plan usage. */
function useInvalidateLifecycle() {
  const qc = useQueryClient()

  return () => {
    for (const key of [['projects'], ['settings', 'projects'], ['client-workspaces'], ['plan-usage'], ['app', 'client'], ['project-lifecycle']]) {
      qc.invalidateQueries({ queryKey: key })
    }
  }
}

export function ProjectLifecycleDialog({ state, ar, onClose, onDone }: { state: LifecycleDialogState; ar: boolean; onClose: () => void; onDone: (message: string) => void }) {
  if (state === null) return null
  const props = { project: state.project, ar, onClose, onDone }
  switch (state.kind) {
    case 'archive': return <ArchiveDialog {...props} />
    case 'delete': return <DeleteDialog {...props} />
    case 'restore': return <RestoreDialog {...props} />
    case 'purge': return <PurgeDialog {...props} />
    case 'move': return <MoveDialog {...props} />
  }
}

interface DialogProps { project: LifecycleTarget; ar: boolean; onClose: () => void; onDone: (message: string) => void }

function ArchiveDialog({ project, ar, onClose, onDone }: DialogProps) {
  const invalidate = useInvalidateLifecycle()
  const [unlink, setUnlink] = useState(false)
  const impact = useQuery({ queryKey: ['project-lifecycle', 'archive-impact', project.id], queryFn: () => getArchiveImpact(project.id) })
  const act = useMutation({
    mutationFn: () => archiveProjectWithEffects(project.id, { unlink_accounts: unlink }),
    onSuccess: () => { invalidate(); onDone(lt('projects.lifecycle.done.archived', ar)) },
  })

  return (
    <Modal open onClose={onClose} title={`${lt('projects.lifecycle.archive.title', ar)} — ${project.name}`}
      footer={<Footer ar={ar} onClose={onClose} confirm={lt('projects.lifecycle.action.archive', ar)} disabled={impact.isLoading} pending={act.isPending} testid="lifecycle-archive-confirm" />}>
      <Body onSubmit={() => act.mutate()}>
        <p className="text-sm text-text-secondary">{lt('projects.lifecycle.archive.body', ar)}</p>
        {impact.isLoading ? <Skeleton className="h-16" /> : <SharedAccountsWarning accounts={impact.data?.shared_accounts ?? []} ar={ar} />}
        <label className="flex items-center gap-2 text-sm text-text-primary">
          <input type="checkbox" data-testid="lifecycle-archive-unlink" checked={unlink} onChange={(e) => setUnlink(e.target.checked)} />
          {lt('projects.lifecycle.archive.unlink', ar)}
        </label>
        <PlatformsUntouched ar={ar} />
        <Failure error={act.error} />
      </Body>
    </Modal>
  )
}

const IMPACT_ROWS: Array<{ keys: string[]; ar: string; en: string }> = [
  { keys: ['integration_bindings'], ar: 'الحسابات المرتبطة', en: 'Linked accounts' },
  { keys: ['campaigns'], ar: 'الحملات', en: 'Campaigns' },
  { keys: ['budgets', 'spend_limits'], ar: 'الميزانيات والحدود', en: 'Budgets and limits' },
  { keys: ['alert_rules', 'automation_rules'], ar: 'القواعد', en: 'Rules' },
  { keys: ['recipients'], ar: 'المستلمون', en: 'Recipients' },
  { keys: ['report_schedules'], ar: 'التقارير المجدولة', en: 'Scheduled reports' },
  { keys: ['active_shares'], ar: 'روابط مشاركة نشطة', en: 'Active share links' },
]

function DeleteDialog({ project, ar, onClose, onDone }: DialogProps) {
  const invalidate = useInvalidateLifecycle()
  const [typed, setTyped] = useState('')
  const [withClient, setWithClient] = useState(false)
  const [typedClient, setTypedClient] = useState('')
  const impact = useQuery({ queryKey: ['project-lifecycle', 'deletion-impact', project.id], queryFn: () => getDeletionImpact(project.id) })
  const client = impact.data?.client ?? null
  const act = useMutation({
    mutationFn: () => softDeleteProject(project.id, {
      confirm_name: typed,
      client_action: withClient ? 'project_and_client' : 'project_only',
      confirm_client_name: withClient ? typedClient : undefined,
    }),
    onSuccess: () => { invalidate(); onDone(lt('projects.lifecycle.done.deleted', ar)) },
  })
  const ready = matches(typed, project.name) && (!withClient || (client !== null && matches(typedClient, client.name)))

  return (
    <Modal open onClose={onClose} size="lg" title={`${lt('projects.lifecycle.delete.title', ar)} — ${project.name}`}
      footer={<Footer ar={ar} onClose={onClose} confirm={lt('projects.lifecycle.action.delete', ar)} disabled={!ready} pending={act.isPending} danger testid="lifecycle-delete-confirm" />}>
      <Body onSubmit={() => ready && act.mutate()}>
        <p className="text-sm text-text-secondary">{lt('projects.lifecycle.delete.body', ar)}</p>
        {impact.isLoading ? <Skeleton className="h-24" /> : impact.data && (
          <>
            <div>
              <p className="mb-1.5 text-xs font-bold text-text-muted">{lt('projects.lifecycle.delete.reach', ar)}</p>
              <dl data-testid="lifecycle-delete-impact" className="grid grid-cols-2 gap-1.5 sm:grid-cols-4">
                {IMPACT_ROWS.map((row) => (
                  <div key={row.en} className="min-w-0 rounded-lg bg-surface-secondary px-2.5 py-2">
                    <dt className="truncate text-[11px] text-text-muted">{ar ? row.ar : row.en}</dt>
                    <dd className="tnum text-base font-bold text-text-primary">{row.keys.reduce((n, k) => n + (impact.data.counts[k] ?? 0), 0).toLocaleString('en-US')}</dd>
                  </div>
                ))}
              </dl>
              <p className="mt-1.5 text-xs text-text-muted">{lt('projects.lifecycle.delete.retention', ar)}: <span className="tnum font-semibold">{countedDays(impact.data.retention_days, ar ? 'ar' : 'en')}</span></p>
            </div>
            <SharedAccountsWarning accounts={impact.data.shared_accounts} ar={ar} />
            {client && client.can_delete_client && (
              <fieldset data-testid="lifecycle-delete-client-choice" className="grid gap-1.5 rounded-xl border border-border p-3">
                <legend className="px-1 text-xs font-bold text-text-primary">{lt('projects.lifecycle.delete.client_question', ar)}</legend>
                <label className="flex items-center gap-2 text-sm"><input type="radio" name="client-action" checked={!withClient} onChange={() => setWithClient(false)} data-testid="lifecycle-delete-project-only" /> {lt('projects.lifecycle.delete.project_only', ar)}</label>
                <label className="flex items-center gap-2 text-sm"><input type="radio" name="client-action" checked={withClient} onChange={() => setWithClient(true)} data-testid="lifecycle-delete-project-and-client" /> {lt('projects.lifecycle.delete.project_and_client', ar)} (<bdi>{client.name}</bdi>)</label>
              </fieldset>
            )}
            {client && client.other_projects > 0 && <p data-testid="lifecycle-delete-client-kept" className="text-xs text-text-secondary">{lt('projects.lifecycle.delete.client_kept', ar)}</p>}
          </>
        )}
        <PlatformsUntouched ar={ar} />
        <TypedName id="lifecycle-delete-name" expected={project.name} value={typed} onChange={setTyped} label={lt('projects.lifecycle.type_project', ar)} />
        {withClient && client && <TypedName id="lifecycle-delete-client-name" expected={client.name} value={typedClient} onChange={setTypedClient} label={lt('projects.lifecycle.type_client', ar)} />}
        <Failure error={act.error} />
      </Body>
    </Modal>
  )
}

function RestoreDialog({ project, ar, onClose, onDone }: DialogProps) {
  const invalidate = useInvalidateLifecycle()
  const [relink, setRelink] = useState(true)
  const impact = useQuery({ queryKey: ['project-lifecycle', 'restore-impact', project.id], queryFn: () => getRestoreImpact(project.id) })
  const act = useMutation({
    mutationFn: () => restoreProject(project.id, relink),
    onSuccess: () => { invalidate(); onDone(lt('projects.lifecycle.done.restored', ar)) },
  })
  const accounts = impact.data?.relink_accounts ?? []

  return (
    <Modal open onClose={onClose} title={`${lt('projects.lifecycle.restore.title', ar)} — ${project.name}`}
      footer={<Footer ar={ar} onClose={onClose} confirm={lt('projects.lifecycle.action.restore', ar)} disabled={impact.isLoading} pending={act.isPending} testid="lifecycle-restore-confirm" />}>
      <Body onSubmit={() => act.mutate()}>
        <p className="text-sm text-text-secondary">{lt('projects.lifecycle.restore.body', ar)}</p>
        {impact.isLoading ? <Skeleton className="h-16" /> : impact.data && (
          <>
            <p className="text-sm">{lt('projects.lifecycle.restore.to', ar)}: <Badge tone={lifecycleTone(impact.data.restores_to)}>{lifecycleLabel(impact.data.restores_to, ar)}</Badge></p>
            {impact.data.client_restored && <p data-testid="lifecycle-restore-client" className="text-xs text-text-secondary">{lt('projects.lifecycle.restore.client_back', ar)}</p>}
            {accounts.length > 0 && (
              <div className="grid gap-1.5 rounded-xl border border-border p-3">
                <label className="flex items-center gap-2 text-sm font-semibold">
                  <input type="checkbox" data-testid="lifecycle-restore-relink" checked={relink} onChange={(e) => setRelink(e.target.checked)} />
                  {lt('projects.lifecycle.restore.relink', ar)}
                </label>
                <ul className="grid gap-1">
                  {accounts.map((a) => (
                    <li key={a.binding_id} className="flex min-w-0 items-center gap-2 text-xs text-text-secondary">
                      <span className="truncate">{a.name ?? a.external_id ?? a.provider}</span>
                      {!a.available && <Badge tone="neutral">{lt('projects.lifecycle.restore.unavailable', ar)}</Badge>}
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </>
        )}
        <Failure error={act.error} />
      </Body>
    </Modal>
  )
}

function PurgeDialog({ project, ar, onClose, onDone }: DialogProps) {
  const invalidate = useInvalidateLifecycle()
  const [typed, setTyped] = useState('')
  const act = useMutation({
    mutationFn: () => purgeProject(project.id, typed),
    onSuccess: () => { invalidate(); onDone(lt('projects.lifecycle.done.purged', ar)) },
  })
  const ready = matches(typed, project.name)

  return (
    <Modal open onClose={onClose} title={`${lt('projects.lifecycle.purge.title', ar)} — ${project.name}`}
      footer={<Footer ar={ar} onClose={onClose} confirm={lt('projects.lifecycle.action.purge', ar)} disabled={!ready} pending={act.isPending} danger testid="lifecycle-purge-confirm" />}>
      <Body onSubmit={() => ready && act.mutate()}>
        <p className="text-sm text-text-secondary">{lt('projects.lifecycle.purge.body', ar)}</p>
        <PlatformsUntouched ar={ar} />
        <TypedName id="lifecycle-purge-name" expected={project.name} value={typed} onChange={setTyped} label={lt('projects.lifecycle.type_project', ar)} />
        <Failure error={act.error} />
      </Body>
    </Modal>
  )
}

function MoveDialog({ project, ar, onClose, onDone }: DialogProps) {
  const invalidate = useInvalidateLifecycle()
  const [target, setTarget] = useState('')
  const [typed, setTyped] = useState('')
  const clients = useQuery({ queryKey: ['client-workspaces'], queryFn: listClientWorkspaces })
  const impact = useQuery({ queryKey: ['project-lifecycle', 'move-impact', project.id, target], queryFn: () => getMoveImpact(project.id, target), enabled: target !== '' })
  const act = useMutation({
    mutationFn: () => moveProject(project.id, target, typed),
    onSuccess: () => { invalidate(); onDone(lt('projects.lifecycle.done.moved', ar)) },
  })
  const options = (clients.data ?? []).filter((c) => c.id !== project.client_workspace_id).map((c) => ({ value: c.id, label: c.name }))
  const ready = target !== '' && matches(typed, project.name) && (impact.data?.refusal ?? null) === null

  return (
    <Modal open onClose={onClose} title={`${lt('projects.lifecycle.move.title', ar)} — ${project.name}`}
      footer={<Footer ar={ar} onClose={onClose} confirm={lt('projects.lifecycle.action.move', ar)} disabled={!ready} pending={act.isPending} testid="lifecycle-move-confirm" />}>
      <Body onSubmit={() => ready && act.mutate()}>
        <p className="text-sm text-text-secondary">{lt('projects.lifecycle.move.body', ar)}</p>
        <label htmlFor="lifecycle-move-target" className="grid gap-1 text-xs font-semibold text-text-secondary">
          {lt('projects.lifecycle.move.target', ar)}
          <Select id="lifecycle-move-target" data-testid="lifecycle-move-target" value={target} onChange={(e) => setTarget(e.target.value)} options={options} placeholder={lt('projects.lifecycle.move.target', ar)} />
        </label>
        {impact.data && (
          <div data-testid="lifecycle-move-impact" className="grid gap-1 rounded-xl bg-surface-secondary p-3 text-xs text-text-secondary">
            <p><bdi>{impact.data.from_client?.name ?? '—'}</bdi> ← <bdi className="font-bold text-text-primary">{impact.data.to_client?.name ?? '—'}</bdi></p>
            <p>{ar ? 'السجلات التي تنتقل مع المشروع' : 'Records that move with the project'}: <span className="tnum font-semibold">{Object.values(impact.data.moves).reduce((a, b) => a + b, 0).toLocaleString('en-US')}</span></p>
            {impact.data.from_client_portal_users > 0 && <p className="text-warning">{lt('projects.lifecycle.move.portal_users', ar)}: <span className="tnum">{impact.data.from_client_portal_users}</span></p>}
          </div>
        )}
        <PlatformsUntouched ar={ar} />
        <TypedName id="lifecycle-move-name" expected={project.name} value={typed} onChange={setTyped} label={lt('projects.lifecycle.type_project', ar)} />
        <Failure error={act.error} />
      </Body>
    </Modal>
  )
}

/** §8 — «أرشفة العميل»: lists every active project archived with it and requires the client name. */
export function ClientArchiveDialog({ clientId, clientName, ar, onClose, onDone }: { clientId: string; clientName: string; ar: boolean; onClose: () => void; onDone: () => void }) {
  const invalidate = useInvalidateLifecycle()
  const [typed, setTyped] = useState('')
  const impact = useQuery({ queryKey: ['project-lifecycle', 'client-archive-impact', clientId], queryFn: () => getClientArchiveImpact(clientId) })
  const act = useMutation({
    mutationFn: () => archiveClientWithProjects(clientId, typed),
    onSuccess: () => { invalidate(); onDone() },
  })
  const ready = matches(typed, clientName)

  return (
    <Modal open onClose={onClose} title={`${lt('projects.lifecycle.client.archive.title', ar)} — ${clientName}`}
      footer={<Footer ar={ar} onClose={onClose} confirm={lt('projects.lifecycle.client.archive.title', ar)} disabled={!ready} pending={act.isPending} danger testid="client-archive-confirm" />}>
      <Body onSubmit={() => ready && act.mutate()}>
        <p className="text-sm text-text-secondary">{lt('projects.lifecycle.client.archive.body', ar)}</p>
        {impact.isLoading ? <Skeleton className="h-16" /> : impact.data && (
          <>
            {impact.data.projects.length === 0
              ? <p className="text-xs text-text-muted">{lt('projects.lifecycle.client.archive.none', ar)}</p>
              : (
                <ul data-testid="client-archive-projects" className="grid gap-1">
                  {impact.data.projects.map((p) => (
                    <li key={p.id} className="flex min-w-0 items-center justify-between gap-2 rounded-lg bg-surface-secondary px-2.5 py-1.5 text-sm">
                      <span className="truncate font-semibold">{p.name}</span>
                      <Badge tone={lifecycleTone(p.status)}>{lifecycleLabel(p.status, ar)}</Badge>
                    </li>
                  ))}
                </ul>
              )}
            <SharedAccountsWarning accounts={impact.data.shared_accounts} ar={ar} />
          </>
        )}
        <PlatformsUntouched ar={ar} />
        <TypedName id="client-archive-name" expected={clientName} value={typed} onChange={setTyped} label={lt('projects.lifecycle.type_client', ar)} />
        <Failure error={act.error} />
      </Body>
    </Modal>
  )
}
