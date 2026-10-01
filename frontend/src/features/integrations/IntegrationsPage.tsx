import { useEffect, useState, type ReactNode } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import {
  fetchConnectionHub,
  type ConnectableProvider, type HubConnection,
} from './api'
import { ConnectionHub } from './ConnectionHub'
import { SectionHeader } from '@/components/patterns/Status'
import { ConnectionDrawer, type DrawerTab } from './ConnectionDrawer'
import { ConnectionFlow } from './ConnectionFlow'
import { AccountsPanel } from './AccountsPanel'
import { StoresPanel } from '@/features/commerce/StoresPanel'
import { Button } from '@/components/ui/Button'
import { platformColor } from '@/features/analytics/components'
import { sortByPlatform } from '@/lib/platforms'
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
  const returningProject = params.get('project')

  const [flow, setFlow] = useState<
    { mode: 'connect' | 'manage'; provider: ConnectableProvider | null; connection: HubConnection | null; projectId: string | null } | null
  >(null)
  const [picking, setPicking] = useState(false)
  const [drawer, setDrawer] = useState<{ id: string; tab: DrawerTab } | null>(null)
  const [menu, setMenu] = useState<HubConnection | null>(null)
  const [inventoryOpen, setInventoryOpen] = useState(false)

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

    setFlow({ mode: 'connect', provider: null, connection, projectId: returningProject })
    setParams((current) => {
      const next = new URLSearchParams(current)
      next.delete('connection')
      next.delete('project')
      return next
    }, { replace: true })
  }, [returning, returningProject, connections, setParams])

  const close = () => {
    setFlow(null)
    void queryClient.invalidateQueries({ queryKey: ['connection-hub'] })
    void queryClient.invalidateQueries({ queryKey: ['connectors'] })
  }

  return (
    <div className="flex flex-col gap-6">
      <ConnectionHub
        onConnect={() => setPicking(true)}
        onOpen={(connection, tab) => setDrawer({ id: connection.id, tab: tab ?? 'overview' })}
        onAction={(connection) => setMenu(connection)}
      />

      {/*
        INTEGRATION-DATASOURCE-WIZARD-001 §11 §28 — «everything we can SEE» is a real question, and
        it is not this page's.

        The inventory used to sit open beneath the cards: on the live Snapchat estate, three hundred
        rows under the six things somebody came for. It is not permanently rendered any more and it
        is not gone either — a reader asks for it, and the control says what it will show. The
        drawer's Accounts tab answers the narrower and far commoner question of «which of THIS
        authorisation's accounts are ours».
      */}
      <section className="flex flex-col gap-3">
        {/*
          A labelled section, not a button floating in the margin.

          The Arabic dark review of `/app/integrations` on a workspace with nothing connected showed
          this control alone in the middle of the page: a bordered button, no heading above it and no
          content under it, reading as something half-rendered. It is not gated on the hub having
          rows, because the inventory is tenant-wide — the sandbox and anything else non-advertising
          is discovered without ever appearing as a hub authorisation — so what it needed was a name.
        */}
        <SectionHeader
          title={ar ? 'جميع الحسابات المكتشفة' : 'Every discovered account'}
          question={ar
            ? 'ما الذي نراه في هذه المساحة، سواء اخترته أم لا؟'
            : 'What can this workspace see, whether or not it was selected?'}
          action={
            /*
             * «عرض القائمة», not «عرض جميع الحسابات المكتشفة».
             *
             * The heading beside it now says which accounts, so a button repeating the heading word
             * for word reads as the same sentence printed twice. The control says only what pressing
             * it does.
             */
            <button
              type="button"
              data-testid="toggle-account-inventory"
              aria-expanded={inventoryOpen}
              onClick={() => setInventoryOpen((open) => !open)}
              className="inline-flex w-fit items-center gap-1.5 rounded-xl border border-border bg-surface px-3 py-2 text-sm font-semibold text-text-secondary transition-colors hover:bg-surface-hover hover:text-text-primary"
            >
              {inventoryOpen
                ? (ar ? 'إخفاء القائمة' : 'Hide the list')
                : (ar ? 'عرض القائمة' : 'Show the list')}
            </button>
          }
        />

        {inventoryOpen && <AccountsPanel />}
      </section>

      {/*
        INTEG-STORES-001 — Salla and Zid keep their own journey (§7).

        They are commerce connectors with a store, an order stream and no ad accounts at all, so the
        «organisation → ad account» flow above asks them a question they have no answer to.
      */}
      <StoresPanel />

      {picking && (
        <ProviderPicker
          providers={hub.data?.connectable ?? []}
          ar={ar}
          onPick={(provider) => {
            setPicking(false)
            setFlow({ mode: 'connect', provider, connection: null, projectId: null })
          }}
          onClose={() => setPicking(false)}
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
          onManageAccounts={(connection) => {
            setDrawer(null)
            setFlow({
              mode: 'manage',
              provider: null,
              connection,
              projectId: manageDestination(connection),
            })
          }}
        />
      )}

      {menu !== null && (
        <ActionMenu
          ar={ar}
          onClose={() => setMenu(null)}
          onOpen={(tab) => { setDrawer({ id: menu.id, tab }); setMenu(null) }}
          onManageAccounts={() => {
            setFlow({ mode: 'manage', provider: null, connection: menu, projectId: manageDestination(menu) })
            setMenu(null)
          }}
        />
      )}
    </div>
  )
}

/**
 * Which provider a new authorisation is for.
 *
 * Deliberately a short list and not the card grid this page used to be: the grid was a catalogue,
 * and a reader who has pressed «Connect a source» has already decided to shop. Only providers this
 * install can actually start appear — offering one it cannot produces a request the server refuses.
 *
 * Each row says HOW it connects, because the two are different acts and the difference matters
 * before the press, not after: one hands you to the platform's own sign-in, the other asks for a key
 * you must already have. A reader who expected a consent screen and met a key box goes looking for
 * the key with the dialog open; one told beforehand brings it.
 */
function ProviderPicker({ providers, ar, onPick, onClose }: {
  providers: ConnectableProvider[]
  ar: boolean
  onPick: (provider: ConnectableProvider) => void
  onClose: () => void
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true">
      <div className="w-full max-w-md rounded-2xl border border-border bg-surface p-5 shadow-2xl" data-testid="provider-picker">
        <div className="flex items-center justify-between gap-3">
          <h2 className="text-base font-bold text-text-primary">{ar ? 'اختر المنصة' : 'Choose a platform'}</h2>
          <Button variant="ghost" onClick={onClose} data-testid="provider-picker-close">{ar ? 'إغلاق' : 'Close'}</Button>
        </div>

        {providers.length === 0 ? (
          <p className="mt-4 rounded-xl border border-dashed border-border p-4 text-center text-xs text-text-secondary" data-testid="provider-picker-empty">
            {/*
              Said as the operator's business and not as the customer's fault: no system credential
              for any platform is a setup that has not happened, and nothing on this page can do it.
            */}
            {ar
              ? 'لا توجد منصة مهيّأة للربط بعد. يتولّى مشغّل المنصة تجهيزها.'
              : 'No platform is set up for connecting yet. The platform operator is preparing them.'}
          </p>
        ) : (
          <ul className="mt-4 flex flex-col gap-1.5">
            {sortByPlatform(providers, (p) => p.key).map((provider) => (
              <li key={provider.key}>
                <button
                  type="button"
                  onClick={() => onPick(provider)}
                  data-testid={`provider-pick-${provider.key}`}
                  className="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-start transition-colors hover:bg-surface-hover"
                >
                  <span className="h-7 w-1 shrink-0 rounded-full" style={{ background: platformColor(provider.key) }} aria-hidden />
                  <span className="flex min-w-0 flex-col">
                    <span className="truncate text-sm font-semibold text-text-primary">
                      {ar ? provider.label_ar : provider.label}
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
function ActionMenu({ ar, onClose, onOpen, onManageAccounts }: {
  ar: boolean
  onClose: () => void
  onOpen: (tab: DrawerTab) => void
  onManageAccounts: () => void
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-4 sm:items-center" role="dialog" aria-modal="true">
      <div className="w-full max-w-sm rounded-2xl border border-border bg-surface p-2 shadow-2xl" data-testid="hub-action-menu">
        <MenuItem testId="action-details" onClick={() => onOpen('overview')}>
          {ar ? 'عرض التفاصيل' : 'View details'}
        </MenuItem>
        <MenuItem testId="action-manage" onClick={onManageAccounts}>
          {ar ? 'إدارة الحسابات' : 'Manage accounts'}
        </MenuItem>
        <MenuItem testId="action-history" onClick={() => onOpen('history')}>
          {ar ? 'سجل المزامنة' : 'Sync history'}
        </MenuItem>
        <MenuItem testId="action-settings" onClick={() => onOpen('settings')}>
          {ar ? 'المزامنة وإعدادات الاتصال' : 'Sync and connection settings'}
        </MenuItem>
        <div className="my-1 border-t border-border" />
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
