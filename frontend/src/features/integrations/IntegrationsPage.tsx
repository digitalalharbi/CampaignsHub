import { useEffect, useState, type ReactNode } from 'react'
import { ProjectsEstate } from './ProjectsEstate'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import {
  fetchConnectionHub, removeConnection,
  type AccountRow, type ConnectableProvider, type HubConnection,
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
import { platformColor } from '@/features/analytics/components'
import { platformLabel, sortByPlatform } from '@/lib/platforms'
import { useUi } from '@/stores/ui'
import { useProject } from '@/stores/project'

/**
 * INTEGRATION-DATASOURCE-WIZARD-001 §§17, 22 — the Connection Hub is the page.
 *
 * ## What this replaced
 *
 * A grid of six provider cards, each carrying a state, a sentence and up to four buttons, with
 * every discovered account listed underneath. It was a CATALOGUE — «here are the platforms» — and
 * the reader of this page is not shopping: they connected something already and want to know
 * whether it still works, which accounts it reads, and who authorised it.
 *
 * Those are a table's questions, so the surface is a table of AUTHORISATIONS, with everything else
 * one press away in a drawer. The grid survives only as the short list behind «ربط مصدر», which is
 * the one moment a reader is genuinely choosing a platform.
 *
 * Tenant-level, deliberately: an authorisation belongs to the tenant and is LENT to projects
 * through a binding, so this page is reachable before any project is chosen.
 */
/**
 * INTEG-UNIFIED-002 (contract §1.12) — one centre, three sections, in this order:
 *
 *  1. «المشاريع» — Project-First: every project, what feeds it, and the link journey started from it.
 *  2. «المصادر المرتبطة» — the actual resources: platform account, five independent statuses, controls.
 *  3. «التدقيق» — health and authorisation that need action, and the log of who did what.
 *
 * The working sections come first and the figures that summarise them come last (feature first).
 * `connection-hub` names the CENTRE — the whole three-section surface — because that is what the hub
 * is now; the sources list inside it is `hub-section-sources`.
 */
export const HUB_SECTIONS: Array<{ key: 'projects' | 'sources' | 'audit'; target: string; ar: string; en: string }> = [
  { key: 'projects', target: 'hub-projects', ar: 'المشاريع', en: 'Projects' },
  { key: 'sources', target: 'hub-sources', ar: 'المصادر المرتبطة', en: 'Linked sources' },
  { key: 'audit', target: 'hub-audit', ar: 'التدقيق', en: 'Audit' },
]

export function IntegrationsPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const queryClient = useQueryClient()
  const currentProjectId = useProject((s) => s.currentProjectId)

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

  const hub = useQuery({ queryKey: ['connection-hub'], queryFn: fetchConnectionHub, retry: false })

  /*
   * «Manage link» on a SELECTED account: its own connection, edited for the project it feeds.
   * Both come from the account row, so nothing here guesses a client.
   */
  const manageAccount = (account: AccountRow) => {
    const connection = hub.data?.connections.find((c) => c.id === account.connection_id)
    if (connection === undefined) return
    setFlow({ mode: 'manage', provider: null, connection, projectId: account.assigned_project_id })
  }

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
  const [picking, setPicking] = useState(false)
  const [drawer, setDrawer] = useState<{ id: string; tab: DrawerTab } | null>(null)
  const [menu, setMenu] = useState<HubConnection | null>(null)
  const [removing, setRemoving] = useState<HubConnection | null>(null)
  /*
   * INTEG-PROJECT-LINK-001 — linking from the centre IS the project's journey: the same flow the
   * project page opens, with the project fixed, ending on that project's page.
   */
  const [linking, setLinking] = useState<{ projectId: string; provider: ConnectableProvider } | null>(null)
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
        title={ar ? 'مركز التكاملات' : 'Integrations Centre'}
        purpose={ar
          ? 'ابدأ من المشروع، اربط به مصدرًا، وتابع حالاته وسجله من مكان واحد.'
          : 'Start from the project, link a source to it, and follow its statuses and its log in one place.'}
        actions={live.length === 0 ? undefined : (
          <Button onClick={() => setPicking(true)} data-testid="hub-connect">
            <Plus size={15} /> {ar ? 'ربط مصدر' : 'Connect a source'}
          </Button>
        )}
      />

      {/* The three sections, named once and reachable in one press — on a phone they are a long way apart. */}
      <nav aria-label={ar ? 'أقسام مركز التكاملات' : 'Centre sections'} data-testid="hub-sections">
        <ul className="flex min-w-0 gap-1 overflow-x-auto rounded-[13px] border border-border bg-surface-secondary p-1">
          {HUB_SECTIONS.map((section) => (
            <li key={section.key} className="shrink-0">
              <button
                type="button"
                data-testid={`hub-section-link-${section.key}`}
                onClick={() => document.getElementById(section.target)?.scrollIntoView?.({ behavior: 'smooth', block: 'start' })}
                className="whitespace-nowrap rounded-[10px] px-3.5 py-1.5 text-sm font-semibold text-text-secondary transition-colors hover:bg-surface hover:text-text-primary"
              >
                {ar ? section.ar : section.en}
              </button>
            </li>
          ))}
        </ul>
      </nav>

      {/* 1 — «المشاريع». INTEGRATION-ESTATE-001: client first, with linking and removing on the row. */}
      <div data-testid="hub-section-projects" className="min-w-0">
        <ProjectsEstate
          connectable={hub.data?.connectable ?? []}
          onLink={(projectId, provider) => setLinking({ projectId, provider })}
          onManage={manageAccount}
        />
      </div>

      {/* 2 — «المصادر المرتبطة». */}
      <ConnectionHub
        onConnect={() => setPicking(true)}
        onOpen={(connection, tab) => setDrawer({ id: connection.id, tab: tab ?? 'overview' })}
        onAction={(connection) => setMenu(connection)}
        onRemove={(connection) => setRemoving(connection)}
        onReauthorise={reauthorise}
      />

      {/*
        INTEG-STORES-001 — Salla and Zid keep their own journey (§7).

        They are commerce connectors with a store, an order stream and no ad accounts at all, so the
        «organisation → ad account» flow above asks them a question they have no answer to.
      */}
      <StoresPanel />

      {/*
        GA4-INTEGRATION-001 / constitution §23 — measurement has its own section, below paid media.

        Not a hub row, and not folded into the stores section either. The hub is the paid-media board
        and a property answers none of its questions; a store and a property are different sources
        with different journeys. The order is deliberate: paid media, then commerce, then the
        client's own site measurement — widest claim to narrowest.
      */}
      <PropertiesPanel />

      {/* 3 — «التدقيق». Reads the same hub rows the sources section draws, so the two cannot disagree. */}
      <IntegrationAudit
        connections={live}
        loading={hub.isLoading}
        onReauthorise={reauthorise}
        onManage={manage}
        onOpen={(connection) => setDrawer({ id: connection.id, tab: 'overview' })}
      />

      {/* Feature first: the figures that summarise the work come after it. */}
      <HubSummary connections={connections} />

      {picking && (
        <ProviderPicker
          providers={hub.data?.connectable ?? []}
          ar={ar}
          initialProjectId={currentProjectId}
          onPick={(provider, projectId) => {
            setPicking(false)
            setLinking({ projectId, provider })
          }}
          onClose={() => setPicking(false)}
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
 * INTEG-UNIFIED-002 (contract §1.3) — «ربط مصدر», Project-First: the project, THEN the platform.
 *
 * The picker used to ask only for a platform and leave the client to the flow's first step, which
 * made the platform the first decision and the client an afterthought. The order is now the
 * journey's: a platform cannot be pressed until a project is chosen, and the project rides into the
 * flow — and through the provider's consent screen — as the destination every later step is fixed to.
 *
 * A reader already standing in a project, or a workspace with exactly one, is not asked a question
 * with one answer: that project is preselected and stays changeable.
 *
 * Deliberately a short list and not the card grid this page used to be: only providers this install
 * can actually start appear — offering one it cannot produces a request the server refuses. Each row
 * says HOW it connects, because the two are different acts and the difference matters before the
 * press, not after: one hands you to the platform's own sign-in, the other asks for a key you must
 * already have.
 */
function ProviderPicker({ providers, ar, initialProjectId, onPick, onClose }: {
  providers: ConnectableProvider[]
  ar: boolean
  initialProjectId: string | null
  onPick: (provider: ConnectableProvider, projectId: string) => void
  onClose: () => void
}) {
  const projects = useQuery({ queryKey: ['projects'], queryFn: () => listProjects() })
  const [chosen, setChosen] = useState<string | null>(null)
  const list = projects.data ?? []
  const fallback = list.some((p) => p.id === initialProjectId) ? initialProjectId : (list.length === 1 ? list[0]!.id : null)
  const projectId = chosen ?? fallback

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true">
      <div className="w-full max-w-md rounded-2xl border border-border bg-surface p-5 shadow-2xl" data-testid="provider-picker">
        <div className="flex items-center justify-between gap-3">
          <h2 className="text-base font-bold text-text-primary">{ar ? 'ربط مصدر' : 'Connect a source'}</h2>
          <Button variant="ghost" onClick={onClose} data-testid="provider-picker-close">{ar ? 'إغلاق' : 'Close'}</Button>
        </div>

        <section className="mt-4 flex flex-col gap-1.5">
          <label htmlFor="picker-project" className="text-sm font-bold text-text-primary">
            <span className="tnum">1</span> · {ar ? 'اختر المشروع' : 'Choose the project'}
          </label>
          {projects.isLoading ? (
            <Skeleton className="h-10 w-full" />
          ) : list.length === 0 ? (
            <p className="rounded-xl border border-dashed border-border p-3 text-center text-xs text-text-secondary" data-testid="picker-no-project">
              {ar ? 'لا يوجد مشروع بعد. أنشئ مشروعًا أولًا ثم اربط به مصدرًا.' : 'There is no project yet. Create one first, then link a source to it.'}
            </p>
          ) : (
            <select
              id="picker-project"
              value={projectId ?? ''}
              onChange={(e) => setChosen(e.target.value === '' ? null : e.target.value)}
              data-testid="picker-project"
              className="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-text-primary focus:border-brand-500 focus:outline-none"
            >
              <option value="">{ar ? 'اختر المشروع' : 'Choose the project'}</option>
              {list.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
          )}
        </section>

        <h3 className="mt-4 text-sm font-bold text-text-primary">
          <span className="tnum">2</span> · {ar ? 'اختر المنصة' : 'Choose the platform'}
        </h3>
        {projectId === null && providers.length > 0 && (
          <p className="mt-1 text-xs text-text-secondary" data-testid="picker-project-first">
            {ar ? 'اختر المشروع أولًا لتُتاح المنصات.' : 'Choose the project first to enable the platforms.'}
          </p>
        )}

        {providers.length === 0 ? (
          <p className="mt-2 rounded-xl border border-dashed border-border p-4 text-center text-xs text-text-secondary" data-testid="provider-picker-empty">
            {/*
              Said as the operator's business and not as the customer's fault: no system credential
              for any platform is a setup that has not happened, and nothing on this page can do it.
            */}
            {ar
              ? 'لا توجد منصة مهيّأة للربط بعد. يتولّى مشغّل المنصة تجهيزها.'
              : 'No platform is set up for connecting yet. The platform operator is preparing them.'}
          </p>
        ) : (
          <ul className="mt-2 flex flex-col gap-1.5">
            {sortByPlatform(providers, (p) => p.key).map((provider) => (
              <li key={provider.key}>
                <button
                  type="button"
                  disabled={projectId === null}
                  onClick={() => { if (projectId !== null) onPick(provider, projectId) }}
                  data-testid={`provider-pick-${provider.key}`}
                  className="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-start transition-colors hover:bg-surface-hover disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-transparent"
                >
                  <span className="h-7 w-1 shrink-0 rounded-full" style={{ background: platformColor(provider.key) }} aria-hidden />
                  <span className="flex min-w-0 flex-col">
                    {/* The picker lists platforms. The interface's own name belongs on a hub row,
                        which describes one authorisation, not here. */}
                    <span className="truncate text-sm font-semibold text-text-primary">
                      {platformLabel(provider.key, ar)}
                    </span>
                    <span className="truncate text-xs text-text-secondary">
                      {provider.auth === 'api_key'
                        ? (ar ? 'بمفتاح واجهة تملكه' : 'With an API key you hold')
                        : (ar ? 'بتسجيل الدخول لدى المنصة' : 'By signing in at the platform')}
                    </span>
                  </span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>
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
            ? 'ستتوقف المزامنة وتُعطَّل ارتباطات الحسابات بكل المشاريع، ويُمسح رمز الوصول المخزَّن لدينا. تبقى الأرقام والتقارير المحفوظة كما هي. لإعادة الربط لاحقًا اضغط «ربط مصدر».'
            : 'Syncing stops, the accounts are unlinked from every project, and the access token we hold is cleared. Stored figures and reports stay exactly as they are. To connect again later, press «Connect a source».'}
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
