import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ChevronDown, ChevronLeft, Plus, Search } from 'lucide-react'
import { listProjects } from '@/features/projects/api'
import { PageSizer, Pager, type PageSize } from './PageSizer'
import { Skeleton } from '@/components/ui/States'
import { ProviderMark } from '@/components/ProviderMark'
import { fmtDateTime } from '@/lib/datetime'
import { accounts } from '@/lib/counted'
import { useUi } from '@/stores/ui'
import { SelectedAccounts } from './SelectedAccounts'
import { accountHealthLabel } from './accountHealth'
import { SYNC_COPY } from './connectionCopy'
import { fetchEstate, type AccountRow, type ConnectableProvider, type EstateProject } from './api'

/**
 * INTEGRATION-ESTATE-001 — the integrations page, the way an operator thinks about it: client first.
 *
 * The hub answers «what did we authorise». This answers the question asked when something is wrong — «what is Razzah Avenue
 * connected to, is it syncing, and how do I take something off it». One row per project: its platforms, how many accounts, the newest
 * sync, anything needing attention. Opening a row shows ITS SELECTED accounts only (state, last and next sync, sync now, pull history,
 * manage link, remove from project) — never the rest of what the sign-in could see. «ربط منصة» is not a second connect flow: it opens
 * the centre's one `ConnectionFlow` with this project already chosen, and the login is filed under the client, so it can only offer —
 * and only ever bind — that client's accounts.
 */
/** How the «المشاريع» list can be ordered. */
export type EstateSort = 'name' | 'attention' | 'last_sync' | 'accounts'

/**
 * INTEG-UNIFIED-002 (contract §1.3) — the Project-First journey, in the order it is walked.
 *
 * Drawn on the section that starts it so the order is stated before the first press: the project
 * comes first, the provider second, and three of the eight stages stand between «the provider
 * showed us an account» and «we read it». Discovery is not a link and a link is not a sync.
 */
export const LINK_JOURNEY: Array<{ key: string; ar: string; en: string }> = [
  { key: 'project', ar: 'اختر المشروع', en: 'Choose the project' },
  { key: 'provider', ar: 'اختر المنصة', en: 'Choose the platform' },
  { key: 'authorise', ar: 'التفويض', en: 'Authorise' },
  { key: 'discover', ar: 'الاكتشاف', en: 'Discover' },
  { key: 'choose', ar: 'اختر المورد', en: 'Choose the resource' },
  { key: 'confirm', ar: 'تأكيد الربط', en: 'Confirm the link' },
  { key: 'verify', ar: 'التحقق', en: 'Verify' },
  { key: 'sync', ar: 'المزامنة', en: 'Sync' },
]

export function sortEstate(rows: EstateProject[], sort: EstateSort, ar: boolean): EstateProject[] {
  const out = [...rows]
  const byName = (a: EstateProject, b: EstateProject) => a.project_name.localeCompare(b.project_name, ar ? 'ar' : 'en')
  if (sort === 'attention') return out.sort((a, b) => b.needs_attention - a.needs_attention || byName(a, b))
  if (sort === 'accounts') return out.sort((a, b) => b.accounts - a.accounts || byName(a, b))
  // Never synced sorts last: «newest first» only compares projects that have a sync.
  if (sort === 'last_sync') return out.sort((a, b) => (b.last_synced_at ?? '').localeCompare(a.last_synced_at ?? '') || byName(a, b))
  return out.sort(byName)
}

export function ProjectsEstate({ connectable, onLink, onManage }: {
  connectable: ConnectableProvider[]
  onLink: (projectId: string, provider: ConnectableProvider) => void
  onManage?: (account: AccountRow) => void
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const estate = useQuery({ queryKey: ['integration-estate'], queryFn: fetchEstate })
  /*
   * EVERY project, not only the ones something already feeds.
   *
   * The estate endpoint answers «what feeds each project», so a project nothing feeds was absent —
   * and that is exactly the project a Project-First journey starts from. The project list is the
   * one every other surface reads; if it cannot be read the section still shows the linked ones.
   */
  const projects = useQuery({ queryKey: ['projects'], queryFn: () => listProjects(), retry: false })
  const [open, setOpen] = useState<string | null>(null)
  const [linking, setLinking] = useState<string | null>(null)
  const [search, setSearch] = useState('')
  const [sort, setSort] = useState<EstateSort>('name')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState<PageSize>(10)
  const providers = connectable.filter((p) => p.kind === 'advertising')

  const all = useMemo(() => {
    const linked = estate.data?.projects ?? []
    const known = new Set(linked.map((p) => p.project_id))
    const unlinked: EstateProject[] = (projects.data ?? [])
      .filter((p) => !known.has(p.id))
      .map((p) => ({ project_id: p.id, project_name: p.name, providers: [], accounts: 0, last_synced_at: null, needs_attention: 0 }))
    return [...linked, ...unlinked]
  }, [estate.data, projects.data])

  const matched = useMemo(() => {
    const needle = search.trim().toLowerCase()
    const rows = needle === '' ? all : all.filter((p) => `${p.project_name} ${p.providers.join(' ')}`.toLowerCase().includes(needle))
    return sortEstate(rows, sort, ar)
  }, [all, search, sort, ar])

  const lastPage = Math.max(1, Math.ceil(matched.length / perPage))
  const current = Math.min(page, lastPage)
  const rows = matched.slice((current - 1) * perPage, current * perPage)

  if (estate.isLoading) return <Skeleton className="h-24 w-full" />

  return (
    <section className="flex min-w-0 flex-col gap-3" data-testid="projects-estate" id="hub-projects" aria-labelledby="hub-projects-title">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div className="min-w-0">
          <h2 id="hub-projects-title" className="text-base font-bold text-text-primary">{ar ? 'المشاريع' : 'Projects'}</h2>
          <p className="max-w-3xl text-xs text-text-secondary">
            {ar
              ? 'ابدأ من المشروع: لكل عميل تسجيل دخول خاص به على كل منصة، يظهر له حساباته فقط ولا يمكن ربط حساب عميل آخر به. افتح المشروع لترى مصادره، أو أزل ما رُبط بالخطأ.'
              : 'Start from the project: each client signs in to a platform on their own, only their accounts appear, and another client’s account cannot be attached. Open a project to see its sources, or remove one linked by mistake.'}
          </p>
        </div>

        {all.length > 0 && (
          <div className="flex min-w-0 flex-wrap items-center gap-2">
            <label className="relative min-w-0">
              <span className="sr-only">{ar ? 'ابحث في المشاريع' : 'Search projects'}</span>
              <Search size={14} className="pointer-events-none absolute start-2.5 top-1/2 -translate-y-1/2 text-text-muted" aria-hidden />
              <input
                type="search"
                value={search}
                onChange={(e) => { setSearch(e.target.value); setPage(1) }}
                placeholder={ar ? 'ابحث باسم المشروع أو المنصة' : 'Search by project or platform'}
                data-testid="estate-search"
                className="w-full min-w-0 rounded-lg border border-border bg-background py-1.5 pe-3 ps-8 text-sm text-text-primary focus:border-brand-500 focus:outline-none sm:w-64"
              />
            </label>
            <label className="flex items-center gap-1.5 text-xs text-text-secondary">
              {ar ? 'الترتيب' : 'Sort'}
              <select
                value={sort}
                onChange={(e) => { setSort(e.target.value as EstateSort); setPage(1) }}
                data-testid="estate-sort"
                className="rounded-lg border border-border bg-background px-2 py-1.5 text-xs text-text-primary focus:border-brand-500 focus:outline-none"
              >
                <option value="name">{ar ? 'الاسم' : 'Name'}</option>
                <option value="attention">{ar ? 'ما يحتاج انتباهًا أولًا' : 'Needs attention first'}</option>
                <option value="last_sync">{ar ? 'آخر مزامنة (الأحدث)' : 'Last sync (newest)'}</option>
                <option value="accounts">{ar ? 'عدد الحسابات' : 'Accounts'}</option>
              </select>
            </label>
          </div>
        )}
      </div>

      {/*
        The journey, stated where it starts. An ordered list because it IS an order, and the sentence
        under it is the rule the whole centre is built on: what a sign-in can see is not linked and is
        not synced until somebody confirms it for a project.
      */}
      <div className="min-w-0 rounded-xl bg-surface-secondary p-3" data-testid="hub-flow-steps">
        <ol className="flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-1 text-[11px] font-semibold text-text-secondary">
          {LINK_JOURNEY.map((step, index) => (
            <li key={step.key} className="flex items-center gap-1.5" data-testid={`hub-flow-step-${step.key}`}>
              <span className="tnum flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-brand-600 text-[10px] text-white">{(index + 1).toLocaleString('en-US')}</span>
              <span>{ar ? step.ar : step.en}</span>
              {index < LINK_JOURNEY.length - 1 && <span className="text-text-muted" aria-hidden>{ar ? '←' : '→'}</span>}
            </li>
          ))}
        </ol>
        <p className="mt-1.5 text-[11px] text-text-secondary" data-testid="hub-discovery-rule">
          {ar
            ? 'ما تكتشفه المنصة لا يُربط بأي مشروع ولا يُزامَن تلقائيًا: لا يُقرأ إلا ما تؤكّد ربطه بمشروع.'
            : 'What a platform lets us discover is not linked to any project and is never synced automatically: only what you confirm for a project is read.'}
        </p>
      </div>

      {all.length === 0 ? (
        <p className="rounded-lg border border-dashed border-border p-4 text-center text-xs text-text-secondary" data-testid="estate-empty">
          {ar ? 'لا يوجد مشروع بعد. أنشئ مشروعًا ثم اربط به مصدرًا.' : 'There is no project yet. Create one, then link a source to it.'}
        </p>
      ) : rows.length === 0 ? (
        <p className="rounded-lg border border-dashed border-border p-4 text-center text-xs text-text-secondary" data-testid="estate-no-match">
          {ar ? 'لا مشروع يطابق البحث.' : 'No project matches the search.'}
        </p>
      ) : (
        <ul className="flex min-w-0 flex-col gap-2">
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
              onManage={onManage}
            />
          ))}
        </ul>
      )}

      {matched.length > 10 && (
        <div className="flex flex-wrap items-center justify-between gap-2" data-testid="estate-paging">
          <PageSizer value={perPage} onChange={(size) => { setPerPage(size); setPage(1) }} ar={ar} testId="estate-page-size" />
          <Pager page={current} lastPage={lastPage} onPage={setPage} ar={ar} testId="estate-pager" />
        </div>
      )}
    </section>
  )
}

function EstateRow({ project, ar, open, onToggle, menuOpen, onMenu, providers, onLink, onManage }: {
  project: EstateProject
  ar: boolean
  open: boolean
  onToggle: () => void
  menuOpen: boolean
  onMenu: () => void
  providers: ConnectableProvider[]
  onLink: (provider: ConnectableProvider) => void
  onManage?: (account: AccountRow) => void
}) {
  const Chevron = open ? ChevronDown : ChevronLeft

  return (
    <li className="min-w-0 rounded-2xl border border-border bg-surface" data-testid="estate-row" data-project={project.project_id}>
      <div className="flex flex-wrap items-center gap-3 p-3">
        <button type="button" onClick={onToggle} aria-expanded={open} data-testid={`estate-toggle-${project.project_id}`} className="flex min-w-0 flex-1 flex-wrap items-center gap-x-3 gap-y-1 text-start">
          <Chevron size={16} className="shrink-0 text-text-muted" aria-hidden />
          <span className="min-w-0 max-w-full truncate text-sm font-bold text-text-primary">{project.project_name}</span>
          <span className="flex items-center gap-1">
            {project.providers.map((p) => <ProviderMark key={p} provider={p} size={18} />)}
          </span>
          <span className="tnum text-xs text-text-secondary">
            {accounts(project.accounts, ar ? 'ar' : 'en')}
          </span>
          {project.accounts > 0 && <span className="text-[11px] text-text-muted">
            {project.last_synced_at === null ? (ar ? SYNC_COPY.NEVER_SYNCED.ar : SYNC_COPY.NEVER_SYNCED.en) : `${ar ? 'آخر مزامنة' : 'Last sync'} ${fmtDateTime(project.last_synced_at)}`}
          </span>}
          {project.accounts === 0 ? (
            /* Nothing feeds it yet. Not «working» — there is nothing to work — and not a fault either. */
            <span className="rounded-full bg-surface-secondary px-2 py-0.5 text-[11px] font-semibold text-text-secondary" data-testid="estate-unlinked">
              {ar ? 'لا مصدر مرتبط بعد' : 'No source linked yet'}
            </span>
          ) : project.needs_attention > 0 ? (
            <span className="rounded-full bg-warning/15 px-2 py-0.5 text-[11px] font-semibold text-warning" data-testid="estate-attention">
              {ar ? `${project.needs_attention.toLocaleString('en-US')} تحتاج انتباهًا` : `${project.needs_attention.toLocaleString('en-US')} need attention`}
            </span>
          ) : (
            <span className="rounded-full bg-success/15 px-2 py-0.5 text-[11px] font-semibold text-success">{accountHealthLabel('healthy', ar)}</span>
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
          <SelectedAccounts project={project.project_id} onManage={onManage} />
        </div>
      )}
    </li>
  )
}
