import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ChevronDown, ChevronLeft, Plus } from 'lucide-react'
import { Skeleton } from '@/components/ui/States'
import { ProviderMark } from '@/components/ProviderMark'
import { fmtDateTime } from '@/lib/datetime'
import { useUi } from '@/stores/ui'
import { AccountsPanel } from './AccountsPanel'
import { fetchEstate, type ConnectableProvider, type EstateProject } from './api'

/**
 * INTEGRATION-ESTATE-001 — the integrations page, the way an operator thinks about it: client first.
 *
 * The hub answers «what did we authorise» and the inventory «what can we see». Neither answers the question asked when something is
 * wrong — «what is Razzah Avenue connected to, is it syncing, and how do I take something off it». One row per project: its platforms,
 * how many accounts, the newest sync, anything needing attention. Opening a row shows ITS accounts (the inventory's own panel, scoped —
 * health, logs, backfill, and «remove from project» on each). «ربط منصة» lists the platforms and starts that client's OWN sign-in:
 * the login is filed under the client, so it can only offer — and only ever bind — that client's accounts.
 */
export function ProjectsEstate({ connectable, onLink }: {
  connectable: ConnectableProvider[]
  onLink: (projectId: string, provider: ConnectableProvider) => void
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const estate = useQuery({ queryKey: ['integration-estate'], queryFn: fetchEstate })
  const [open, setOpen] = useState<string | null>(null)
  const [linking, setLinking] = useState<string | null>(null)
  const providers = connectable.filter((p) => p.kind === 'advertising')

  if (estate.isLoading) return <Skeleton className="h-24 w-full" />
  const rows = estate.data?.projects ?? []

  return (
    <section className="flex flex-col gap-3" data-testid="projects-estate">
      <div>
        <h2 className="text-base font-bold text-text-primary">{ar ? 'المشاريع وما يغذّيها' : 'Projects and what feeds them'}</h2>
        <p className="text-xs text-text-secondary">
          {ar
            ? 'لكل عميل تسجيل دخول خاص به على كل منصة: يظهر له حساباته فقط ولا يمكن ربط حساب عميل آخر به. افتح المشروع لترى حساباته، أو أزل ما رُبط بالخطأ.'
            : 'Each client signs in to a platform on their own: only their accounts appear, and another client’s account cannot be attached. Open a project to see its accounts, or remove one linked by mistake.'}
        </p>
      </div>

      {rows.length === 0 ? (
        <p className="rounded-lg border border-dashed border-border p-4 text-center text-xs text-text-secondary" data-testid="estate-empty">
          {ar ? 'لا مشروع مرتبط بأي حساب بعد.' : 'No project is linked to an account yet.'}
        </p>
      ) : (
        <ul className="flex flex-col gap-2">
          {rows.map((project) => (
            <EstateRow
              key={project.project_id}
              project={project}
              ar={ar}
              open={open === project.project_id}
              onToggle={() => setOpen(open === project.project_id ? null : project.project_id)}
              menuOpen={linking === project.project_id}
              onMenu={() => setLinking(linking === project.project_id ? null : project.project_id)}
              providers={providers}
              onLink={(provider) => { setLinking(null); onLink(project.project_id, provider) }}
            />
          ))}
        </ul>
      )}
    </section>
  )
}

function EstateRow({ project, ar, open, onToggle, menuOpen, onMenu, providers, onLink }: {
  project: EstateProject
  ar: boolean
  open: boolean
  onToggle: () => void
  menuOpen: boolean
  onMenu: () => void
  providers: ConnectableProvider[]
  onLink: (provider: ConnectableProvider) => void
}) {
  const Chevron = open ? ChevronDown : ChevronLeft

  return (
    <li className="rounded-2xl border border-border bg-surface" data-testid="estate-row">
      <div className="flex flex-wrap items-center gap-3 p-3">
        <button type="button" onClick={onToggle} aria-expanded={open} data-testid={`estate-toggle-${project.project_id}`} className="flex min-w-0 flex-1 items-center gap-3 text-start">
          <Chevron size={16} className="shrink-0 text-text-muted" aria-hidden />
          <span className="truncate text-sm font-bold text-text-primary">{project.project_name}</span>
          <span className="flex items-center gap-1">
            {project.providers.map((p) => <ProviderMark key={p} provider={p} size={18} />)}
          </span>
          <span className="tnum text-xs text-text-secondary">
            {ar ? `${project.accounts} حساب` : `${project.accounts} account${project.accounts === 1 ? '' : 's'}`}
          </span>
          <span className="text-[11px] text-text-muted">
            {project.last_synced_at === null ? (ar ? 'لم تتم مزامنة بعد' : 'Not synced yet') : `${ar ? 'آخر مزامنة' : 'Last sync'} ${fmtDateTime(project.last_synced_at)}`}
          </span>
          {project.needs_attention > 0 ? (
            <span className="rounded-full bg-warning/15 px-2 py-0.5 text-[11px] font-semibold text-warning" data-testid="estate-attention">
              {ar ? `${project.needs_attention} تحتاج انتباهًا` : `${project.needs_attention} need attention`}
            </span>
          ) : (
            <span className="rounded-full bg-success/15 px-2 py-0.5 text-[11px] font-semibold text-success">{ar ? 'سليم' : 'Healthy'}</span>
          )}
        </button>

        <div className="relative">
          <button
            type="button"
            onClick={onMenu}
            aria-expanded={menuOpen}
            data-testid={`estate-link-${project.project_id}`}
            className="inline-flex items-center gap-1 rounded-xl bg-brand-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-brand-700"
          >
            <Plus size={14} /> {ar ? 'ربط منصة' : 'Link a platform'}
          </button>
          {menuOpen && (
            <ul role="menu" className="absolute end-0 z-20 mt-1 w-48 rounded-xl border border-border bg-surface p-1 shadow-lg" data-testid="estate-link-menu">
              {providers.map((provider) => (
                <li key={provider.key} role="none">
                  <button
                    type="button"
                    role="menuitem"
                    data-testid={`estate-link-${project.project_id}-${provider.key}`}
                    onClick={() => onLink(provider)}
                    className="flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-start text-sm text-text-primary hover:bg-surface-hover"
                  >
                    <ProviderMark provider={provider.key} size={18} /> {ar ? provider.label_ar : provider.label}
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>

      {open && (
        <div className="border-t border-border p-3" data-testid="estate-accounts">
          <AccountsPanel project={project.project_id} heading={false} />
        </div>
      )}
    </li>
  )
}
