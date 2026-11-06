import { useEffect, useState, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useLocation, useSearchParams } from 'react-router-dom'
import {
  fetchConnectionHub, removeConnection,
  type ConnectableProvider, type HubConnection,
} from './api'
import { ConnectionHub, HubSummary } from './ConnectionHub'
import { ConnectionDrawer, type DrawerTab } from './ConnectionDrawer'
import { ConnectionFlow } from './ConnectionFlow'
import { IntegrationAudit } from './IntegrationAudit'
import { ProjectLinkFlow } from './ProjectLinkFlow'
import { useNavigate } from 'react-router-dom'
import { usePortalPath } from '@/app/portalPath'
import { listProjects } from '@/features/projects/api'
import { StoresPanel } from '@/features/commerce/StoresPanel'
import { PropertiesPanel } from '@/features/measurement/PropertiesPanel'
import { Button } from '@/components/ui/Button'
import { PageIntro } from '@/components/ui/PageIntro'
import { Skeleton } from '@/components/ui/States'
import { Plus } from 'lucide-react'
import { ProviderMark } from '@/components/ProviderMark'
import { PLATFORM_ORDER, canonicalPlatform, platformLabel, sortByPlatform } from '@/lib/platforms'
import { useUi } from '@/stores/ui'
import { useProject } from '@/stores/project'
import { useAgencyClient } from '@/stores/agencyClient'
import { accounts as countedAccounts, campaigns as countedCampaigns } from '@/lib/counted'

/**
 * INTEG-PROJECT-SPACE-001 — the project's space has three tabs, in this order:
 * «المصادر» (its linked sources and their controls), «المنصات» (what can be linked, one press each) and
 * «التدقيق» (what needs an action, and this project's own log). `connection-hub` names the whole space.
 */
export const HUB_SECTIONS: Array<{ key: 'sources' | 'platforms' | 'audit'; ar: string; en: string }> = [
  { key: 'sources', ar: 'المصادر', en: 'Sources' },
  { key: 'platforms', ar: 'المنصات', en: 'Platforms' },
  { key: 'audit', ar: 'التدقيق', en: 'Audit' },
]

/**
 * INTEG-PROJECT-SPACE-001 (Owner 2026-11-06) — «شاشة التكاملات هي مساحة المشروع المحدد».
 *
 * The centre is the SELECTED project's own space. Switching the project in the sidebar remounts it
 * (`key`), and every query it makes carries the project id — so nothing of the previous project is
 * rendered, or even held in a cache entry this project reads. With no project selected there is a simple
 * project chooser and nothing else: never a mixed list of everybody's accounts.
 */
export function IntegrationsPage() {
  const currentProjectId = useProject((s) => s.currentProjectId)

  return currentProjectId === null ? <ProjectChooser /> : <ProjectSpace key={currentProjectId} projectId={currentProjectId} />
}

const isAdPlatform = (key: string): boolean => (PLATFORM_ORDER as readonly string[]).includes(canonicalPlatform(key))

function ProjectChooser() {
  const ar = useUi((s) => s.locale) === 'ar'
  const choose = useProject((s) => s.setCurrentProjectId)
  const setClient = useAgencyClient((s) => s.setCurrentClientId)
  const inAgency = useLocation().pathname.startsWith('/agency')
  const projects = useQuery({ queryKey: ['projects'], queryFn: () => listProjects(), retry: false })
  const [search, setSearch] = useState('')
  // The agency's switcher may already have narrowed to one client: the chooser offers that client's projects.
  const client = useAgencyClient((s) => s.currentClientId)
  const shown = (projects.data ?? [])
    .filter((p) => !inAgency || client === null || p.client_workspace_id === client)
    .filter((p) => p.name.toLowerCase().includes(search.trim().toLowerCase()))

  return (
    <div className="flex min-w-0 flex-col gap-4" data-testid="hub-project-chooser" dir={ar ? 'rtl' : 'ltr'}>
      <PageIntro testid="integrations-intro" title={ar ? 'التكاملات' : 'Integrations'} purpose={ar ? 'اختر مشروعًا' : 'Choose a project'} />
      <input
        type="search"
        value={search}
        onChange={(e) => setSearch(e.target.value)}
        placeholder={ar ? 'بحث' : 'Search'}
        aria-label={ar ? 'بحث' : 'Search'}
        className="w-full max-w-sm rounded-xl border border-border bg-surface px-3 py-2 text-sm text-text-primary outline-none"
        data-testid="hub-project-search"
      />
      {projects.isLoading ? <Skeleton className="h-32 w-full" /> : (
        <ul className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
          {shown.map((p) => (
            <li key={p.id} className="min-w-0">
              <button
                type="button"
                onClick={() => {
                  // The agency's sidebar switcher is client → project: it is told the client too, so it shows this choice.
                  if (inAgency) setClient(p.client_workspace_id)
                  choose(p.id)
                }}
                className="flex w-full min-w-0 items-center justify-between gap-3 rounded-2xl border border-border bg-surface px-4 py-3 text-start transition-colors hover:bg-surface-hover"
                data-testid={`hub-choose-${p.id}`}
              >
                <span className="flex min-w-0 flex-col gap-1">
                  <span className="truncate text-sm font-bold text-text-primary">{p.name}</span>
                  <span className="flex flex-wrap items-center gap-1.5 text-[11px] font-semibold text-text-secondary">
                    <span className="tnum rounded-full bg-surface-secondary px-2 py-0.5" data-testid={`hub-card-accounts-${p.id}`}>
                      {countedAccounts(p.summary?.accounts ?? 0, ar ? 'ar' : 'en')}
                    </span>
                    <span className="tnum rounded-full bg-surface-secondary px-2 py-0.5" data-testid={`hub-card-campaigns-${p.id}`}>
                      {countedCampaigns(p.summary?.campaigns ?? 0, ar ? 'ar' : 'en')}
                    </span>
                  </span>
                </span>
                <span className="flex shrink-0 items-center gap-1">
                  {sortByPlatform((p.summary?.providers ?? []).filter(isAdPlatform), (key) => key).map((key) => <ProviderMark key={key} provider={key} size={18} />)}
                </span>
              </button>
            </li>
          ))}
        </ul>
      )}
      {!projects.isLoading && shown.length === 0 && (
        <p className="rounded-2xl border border-dashed border-border px-6 py-8 text-center text-xs text-text-secondary" data-testid="hub-project-none">
          {ar ? 'لا مشروع' : 'No project'}
        </p>
      )}
    </div>
  )
}

function ProjectSpace({ projectId: currentProjectId }: { projectId: string }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const queryClient = useQueryClient()
  const [tab, setTab] = useState<'sources' | 'platforms' | 'audit'>('sources')
  const project = useQuery({ queryKey: ['projects'], queryFn: () => listProjects(), retry: false }).data?.find((p) => p.id === currentProjectId) ?? null
  const projectName = project?.name ?? null

  /*
   * WHICH project «إدارة الحسابات» edits, and why the reader's own comes first.
   *
   * A selection belongs to a PROJECT, so managing one needs a project in hand — and the first
   * version of this took the connection's own first bound project, which is how a reader managing
   * the agency's Google authorisation was silently pointed at another client's project and answered
   * 403 by the server. An authorisation is LENT to many clients; the one being edited is the one the
   * reader is standing in, never whichever happens to sort first.
   *
   * With no project in hand a single-client authorisation is unambiguous and is used. Anything else
   * is a real question, and the flow asks it rather than guessing.
   */
  const manageDestination = (connection: HubConnection): string | null =>
    currentProjectId ?? (connection.projects.length === 1 ? connection.projects[0]!.id : null)

  const hub = useQuery({ queryKey: ['connection-hub', currentProjectId, 'live'], queryFn: () => fetchConnectionHub(currentProjectId), retry: false })

  /*
   * «Manage link» on a SELECTED account: its own connection, edited for the project it feeds.
   * Both come from the account row, so nothing here guesses a client.
   */
  /*
   * The return from a consent screen lands HERE, carrying what it left with.
   *
   * `?connection=` and `?project=` are written by the OAuth callback (§17). Without them the return
   * was «something is connected» on a list, and the reader had to find their own way back to the
   * account picker — the maze this flow replaced. With them the browser reopens the same flow, on
   * the same authorisation, with the client already decided, and the reader continues where they
   * left off as though the provider had never been in the way.
   */
  const [params, setParams] = useSearchParams()
  const returning = params.get('connection')

  const [flow, setFlow] = useState<
    { mode: 'connect' | 'manage' | 'reauthorise'; provider: ConnectableProvider | null; connection: HubConnection | null; projectId: string | null } | null
  >(null)
  const [drawer, setDrawer] = useState<{ id: string; tab: DrawerTab } | null>(null)
  const [menu, setMenu] = useState<HubConnection | null>(null)
  const [removing, setRemoving] = useState<HubConnection | null>(null)
  /*
   * INTEG-PROJECT-LINK-001 — linking from the centre IS the project's journey: the same flow the
   * project page opens, with the project fixed, ending on that project's page.
   */
  const [linking, setLinking] = useState<{ projectId: string; provider: ConnectableProvider | null } | null>(null)
  // One way in: this project's own journey. There is no project to pick — the space IS the project.
  const startLink = (provider: ConnectableProvider | null = null) => setLinking({ projectId: currentProjectId, provider })
  const navigate = useNavigate()
  const to = usePortalPath()

  const connections = hub.data?.connections ?? []
  const open = drawer === null ? null : connections.find((c) => c.id === drawer.id) ?? null

  /*
   * Resume once, then clean the address.
   *
   * Left in place, a reload would reopen the flow for ever on a connection the reader has already
   * finished with — and the OAuth outcome banner below would announce the same success twice.
   */
  useEffect(() => {
    if (returning === null) return

    const connection = connections.find((c) => c.id === returning)
    if (connection === undefined) return

    // A project in the address is never believed (INTEG-PROJECT-LINK-001): project journeys return to the project page.
    setFlow({ mode: 'connect', provider: null, connection, projectId: null })
    setParams((current) => {
      const next = new URLSearchParams(current)
      next.delete('connection')
      next.delete('project')
      return next
    }, { replace: true })
  }, [returning, connections, setParams])

  const close = () => {
    setFlow(null)
    void queryClient.invalidateQueries({ queryKey: ['connection-hub'] })
    void queryClient.invalidateQueries({ queryKey: ['connectors'] })
  }

  /*
   * Re-authorise — ONE way in, whichever control was pressed (the row, the menu, the drawer, the
   * audit finding). It renews the grant through the centre's own flow and leaves the selection alone.
   */
  const reauthorise = (connection: HubConnection) => {
    setDrawer(null)
    setMenu(null)
    setFlow({ mode: 'reauthorise', provider: null, connection, projectId: manageDestination(connection) })
  }

  const manage = (connection: HubConnection) => {
    setMenu(null)
    setFlow({ mode: 'manage', provider: null, connection, projectId: manageDestination(connection) })
  }

  const live = connections.filter((c) => (c.removed_at ?? null) === null && c.connection_state !== 'REVOKED')

  return (
    <div className="flex min-w-0 flex-col gap-6" data-testid="connection-hub" dir={ar ? 'rtl' : 'ltr'}>
      {/*
        UX-PAGE-HERO-001 — the page's one shared head. It carries the ONE connect entry (with nothing
        connected the sources section's empty state carries it instead, so the button is never drawn
        twice), and no figures: those are the summary under the work.
      */}
      <PageIntro
        testid="integrations-intro"
        title={projectName ?? (ar ? 'التكاملات' : 'Integrations')}
        purpose={ar ? 'التكاملات' : 'Integrations'}
        // One primary action: with nothing linked the sources' empty state carries it instead.
        actions={live.length === 0 ? undefined : (
          <Button onClick={() => startLink()} data-testid="hub-connect">
            <Plus size={15} /> {ar ? 'ربط' : 'Link'}
          </Button>
        )}
      />

      <HubSummary connections={connections} campaigns={project?.summary?.campaigns ?? null} />

      <div role="tablist" aria-label={ar ? 'التكاملات' : 'Integrations'} data-testid="hub-sections" className="flex min-w-0 gap-1 overflow-x-auto rounded-[13px] border border-border bg-surface-secondary p-1">
        {HUB_SECTIONS.map((section) => (
          <button
            key={section.key}
            type="button"
            role="tab"
            aria-selected={tab === section.key}
            data-testid={`hub-section-link-${section.key}`}
            onClick={() => setTab(section.key)}
            className={`shrink-0 whitespace-nowrap rounded-[10px] px-3.5 py-1.5 text-sm font-semibold transition-colors ${tab === section.key ? 'bg-surface text-text-primary shadow-sm' : 'text-text-secondary hover:text-text-primary'}`}
          >
            {ar ? section.ar : section.en}
          </button>
        ))}
      </div>

      {tab === 'sources' && (
        <ConnectionHub
          projectId={currentProjectId}
          onConnect={() => startLink()}
          onOpen={(connection, t) => setDrawer({ id: connection.id, tab: t ?? 'overview' })}
          onAction={(connection) => setMenu(connection)}
          onRemove={(connection) => setRemoving(connection)}
          onReauthorise={reauthorise}
          // A legacy (workspace-wide) row re-links through this project's own journey, platform already known.
          onRelink={(connection) => startLink((hub.data?.connectable ?? []).find((p) => p.key === connection.provider) ?? null)}
        />
      )}

      {tab === 'platforms' && (
        <div className="flex min-w-0 flex-col gap-6">
        <ul className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3" data-testid="hub-platforms">
          {hub.isLoading && <li><Skeleton className="h-16 w-full" /></li>}
          {sortByPlatform((hub.data?.connectable ?? []).filter((p) => p.kind === 'advertising'), (p) => p.key).map((provider) => {
            const linked = live.filter((c) => c.provider === provider.key).reduce((n, c) => n + c.selected_accounts, 0)
            return (
              <li key={provider.key} className="flex min-w-0 items-center gap-3 rounded-2xl border border-border bg-surface px-4 py-3" data-testid={`hub-platform-${provider.key}`}>
                <ProviderMark provider={provider.key} size={26} />
                <span className="min-w-0 flex-1 truncate text-sm font-bold text-text-primary">{platformLabel(provider.key, ar)}</span>
                {linked > 0 && <span className="tnum rounded-full bg-success/15 px-2 py-0.5 text-[11px] font-bold text-success" data-testid={`hub-platform-linked-${provider.key}`}>{linked.toLocaleString('en-US')}</span>}
                {/* «إعادة الربط» and «ربط» are the same journey: the platform's own sign-in, for this project. */}
                <Button size="sm" variant={linked > 0 ? 'ghost' : 'primary'} onClick={() => startLink(provider)} data-testid={`hub-platform-link-${provider.key}`}>
                  {linked > 0 ? (ar ? 'إعادة الربط' : 'Re-link') : (ar ? 'ربط' : 'Link')}
                </Button>
              </li>
            )
          })}
        </ul>
        {/* Stores and GA4 are this project's too: asked for it alone, and chosen only for it. */}
        <StoresPanel projectId={currentProjectId} />
        <PropertiesPanel projectId={currentProjectId} />
        </div>
      )}

      {tab === 'audit' && (
        <IntegrationAudit
          connections={live}
          projectId={currentProjectId}
          loading={hub.isLoading}
          onReauthorise={reauthorise}
          onManage={manage}
          onOpen={(connection) => setDrawer({ id: connection.id, tab: 'overview' })}
        />
      )}

      {linking !== null && (
        <ProjectLinkFlow
          projectId={linking.projectId}
          provider={linking.provider}
          onClose={() => { setLinking(null); close() }}
          onReturn={() => {
            const { projectId } = linking
            setLinking(null)
            close()
            navigate(to(`/projects/${projectId}/integrations`))
          }}
        />
      )}

      {flow !== null && (
        <ConnectionFlow
          mode={flow.mode}
          provider={flow.provider}
          connection={flow.connection}
          projectId={flow.projectId}
          onClose={close}
          onDone={close}
        />
      )}

      {open !== null && drawer !== null && (
        <ConnectionDrawer
          connection={open}
          projectId={currentProjectId}
          tab={drawer.tab}
          onTab={(tab) => setDrawer({ id: open.id, tab })}
          onClose={() => setDrawer(null)}
          onManageAccounts={(connection, projectId) => {
            setDrawer(null)
            setFlow({
              mode: 'manage',
              provider: null,
              connection,
              projectId: projectId ?? manageDestination(connection),
            })
          }}
          onReauthorise={reauthorise}
        />
      )}

      {menu !== null && (
        <ActionMenu
          ar={ar}
          onClose={() => setMenu(null)}
          onOpen={(tab) => { setDrawer({ id: menu.id, tab }); setMenu(null) }}
          onManageAccounts={() => manage(menu)}
          onReauthorise={menu.status_profile?.auth === 'api_key' ? undefined : () => reauthorise(menu)}
          onRemove={() => { setRemoving(menu); setMenu(null) }}
        />
      )}

      {removing !== null && (
        <RemoveSourceDialog
          connection={removing}
          ar={ar}
          onClose={() => setRemoving(null)}
          onRemoved={() => {
            setRemoving(null)
            void queryClient.invalidateQueries({ queryKey: ['connection-hub'] })
            void queryClient.invalidateQueries({ queryKey: ['connectors'] })
            void queryClient.invalidateQueries({ queryKey: ['accounts'] })
          }}
        />
      )}
    </div>
  )
}


/**
 * The row's «…» — every act a connected source admits, in one list, each said as what it does.
 *
 * They are gathered rather than spread across the row because most of them are rare, and a row that
 * shows seven buttons is a row nobody can scan. The two that are easy to confuse — «تحديث الحسابات
 * المتاحة» and «إعادة المصادقة» — sit next to each other with the difference stated, which is the
 * confusion support transcripts are full of: an account made last week is missing, so somebody
 * re-authorises instead of re-reading the catalogue.
 */
function ActionMenu({ ar, onClose, onOpen, onManageAccounts, onReauthorise, onRemove }: {
  ar: boolean
  onClose: () => void
  onOpen: (tab: DrawerTab) => void
  onManageAccounts: () => void
  /** Absent for an API-key source: it has no consent screen to return to. */
  onReauthorise?: () => void
  onRemove: () => void
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-4 sm:items-center" role="dialog" aria-modal="true">
      <div className="w-full max-w-sm rounded-2xl border border-border bg-surface p-2 shadow-2xl" data-testid="hub-action-menu">
        <MenuItem testId="action-details" onClick={() => onOpen('overview')}>
          {ar ? 'عرض التفاصيل' : 'View details'}
        </MenuItem>
        <MenuItem testId="action-manage" onClick={onManageAccounts}>
          {ar ? 'تعديل الاختيار' : 'Edit the selection'}
        </MenuItem>
        {onReauthorise !== undefined && (
          <MenuItem testId="action-reauthorise" onClick={onReauthorise}>
            {ar ? 'إعادة المصادقة' : 'Re-authorise'}
          </MenuItem>
        )}
        <MenuItem testId="action-history" onClick={() => onOpen('history')}>
          {ar ? 'سجل المزامنة' : 'Sync history'}
        </MenuItem>
        <MenuItem testId="action-settings" onClick={() => onOpen('settings')}>
          {ar ? 'المزامنة وإعدادات الاتصال' : 'Sync and connection settings'}
        </MenuItem>
        <div className="my-1 border-t border-border" />
        {/* INTEG-SOURCE-REMOVAL-001 — takes the source off this page for good; its stored figures stay. */}
        <MenuItem testId="action-remove" onClick={onRemove}>
          {ar ? 'إزالة المصدر' : 'Remove source'}
        </MenuItem>
        <MenuItem testId="action-close" onClick={onClose}>
          {ar ? 'إغلاق' : 'Close'}
        </MenuItem>
      </div>
    </div>
  )
}

function MenuItem({ onClick, testId, children }: { onClick: () => void; testId: string; children: ReactNode }) {
  return (
    <button
      type="button"
      onClick={onClick}
      data-testid={testId}
      className="w-full rounded-xl px-3 py-2.5 text-start text-sm font-semibold text-text-primary transition-colors hover:bg-surface-hover"
    >
      {children}
    </button>
  )
}

/**
 * INTEG-SOURCE-REMOVAL-001 — the confirmation, said as what it costs AND what it does not.
 *
 * Removing a source ends its data flow for every client it feeds, so the sentence names that. It also
 * says the stored figures and reports stay, because «إزالة» sounds like deletion and the fear that
 * stops people pressing it is losing a client's history.
 */
function RemoveSourceDialog({ connection, ar, onClose, onRemoved }: {
  connection: HubConnection
  ar: boolean
  onClose: () => void
  onRemoved: () => void
}) {
  const remove = useMutation({ mutationFn: () => removeConnection(connection.id), onSuccess: onRemoved })
  const name = ar ? connection.label_ar : connection.label

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-4 sm:items-center" role="dialog" aria-modal="true">
      <div className="w-full max-w-sm rounded-2xl border border-border bg-surface p-5 shadow-2xl" data-testid="remove-source-dialog">
        <h2 className="text-base font-bold text-text-primary">{ar ? `إزالة ${name}؟` : `Remove ${name}?`}</h2>
        <p className="mt-2 text-xs text-text-secondary">
          {ar
            ? 'ستتوقف المزامنة وتُعطَّل ارتباطات الحسابات بكل المشاريع، ويُمسح رمز الوصول المخزَّن لدينا. تبقى الأرقام والتقارير المحفوظة كما هي. لإعادة الربط لاحقًا اضغط «ربط».'
            : 'Syncing stops, the accounts are unlinked from every project, and the access token we hold is cleared. Stored figures and reports stay exactly as they are. To link again later, press «Link».'}
        </p>
        {remove.isError && (
          <p className="mt-2 text-xs text-danger" data-testid="remove-source-error">
            {ar ? 'تعذّرت الإزالة، حاول مرة أخرى.' : 'Could not remove it. Try again.'}
          </p>
        )}
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose} data-testid="remove-source-keep">{ar ? 'إبقاء' : 'Keep'}</Button>
          <Button loading={remove.isPending} onClick={() => remove.mutate()} data-testid="remove-source-confirm">
            {ar ? 'إزالة المصدر' : 'Remove source'}
          </Button>
        </div>
      </div>
    </div>
  )
}
