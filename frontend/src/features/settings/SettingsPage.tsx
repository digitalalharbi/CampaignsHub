import { useState } from 'react'
import { Building2, FileText, Palette, ShieldCheck, Users, Briefcase, FolderKanban, Bell } from 'lucide-react'
import { GeneralTab } from './tabs/GeneralTab'
import { DisclaimerTab } from './tabs/DisclaimerTab'
import { TeamTab } from './tabs/TeamTab'
import { NotificationsTab } from './tabs/NotificationsTab'
import { NotificationRecipients } from './tabs/NotificationRecipients'
import { SecurityTab } from './tabs/SecurityTab'
import { BrandingTab } from './tabs/BrandingTab'
import { ClientsTab } from './tabs/ClientsTab'
import { ProjectsTab } from './tabs/ProjectsTab'
import { useUi } from '@/stores/ui'

const TABS = [
  { id: 'general', ar: 'عام', en: 'General', icon: Building2 },
  { id: 'clients', ar: 'العملاء', en: 'Clients', icon: Briefcase },
  { id: 'projects', ar: 'المشاريع', en: 'Projects', icon: FolderKanban },
  { id: 'team', ar: 'الفريق والصلاحيات', en: 'Team & permissions', icon: Users },
  { id: 'notifications', ar: 'الإشعارات', en: 'Notifications', icon: Bell },
  { id: 'security', ar: 'الأمان', en: 'Security', icon: ShieldCheck },
  { id: 'branding', ar: 'الهوية', en: 'Branding', icon: Palette },
  { id: 'disclaimer', ar: 'الملاحظات والمنهجية', en: 'Notes & methodology', icon: FileText },
] as const

type TabId = (typeof TABS)[number]['id']

interface Props {
  /** Restrict to a subset of tabs. A single tab renders without the inner nav (the settings shell already
   *  provides one) — this is how /settings/permissions and /settings/portals reuse this page cleanly. */
  only?: readonly TabId[]
  title?: string
  subtitle?: string
}

export function SettingsPage({ only, title, subtitle }: Props = {}) {
  const ar = useUi((u) => u.locale) === 'ar'
  const shown = only ? TABS.filter((t) => only.includes(t.id)) : TABS
  const [tab, setTab] = useState<TabId>(shown[0]?.id ?? 'general')
  const single = shown.length === 1

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-3xl font-extrabold tracking-tight text-text-primary">{title ?? (ar ? 'الإعدادات' : 'Settings')}</h1>
        <p className="mt-1 text-sm text-text-secondary">
          {subtitle ?? (ar ? 'إعدادات مساحة العمل — المؤسسة والعملاء والمشاريع والإشعارات والأمان' : 'Workspace settings — the organisation, clients, projects, notifications and security')}
        </p>
      </div>

      <div className={single ? '' : 'grid gap-6 lg:grid-cols-[220px_1fr]'}>
        {!single && (
          <nav className="flex gap-1 overflow-x-auto lg:flex-col lg:overflow-visible">
            {shown.map((t) => {
              const Icon = t.icon
              const active = tab === t.id
              return (
                <button
                  key={t.id}
                  onClick={() => setTab(t.id)}
                  className={`flex shrink-0 items-center gap-2.5 rounded-xl px-3.5 py-2.5 text-sm font-semibold transition-colors ${
                    active ? 'bg-brand-600 text-white shadow-[var(--shadow-small)]' : 'text-text-secondary hover:bg-surface-hover'
                  }`}
                >
                  <Icon size={16} />
                  {ar ? t.ar : t.en}
                </button>
              )
            })}
          </nav>
        )}

        <div className="min-w-0">
          {tab === 'general' && <GeneralTab />}
          {tab === 'disclaimer' && <DisclaimerTab />}
          {tab === 'clients' && <ClientsTab />}
          {tab === 'team' && <TeamTab />}
          {/*
            Two things, in this order — MAIL-010.

            «My own inbox» first, because everyone who opens this tab has one; «who else is told»
            below it, because only an administrator can act on it and it is the longer read. Both on
            one tab rather than two: they are the same question asked about different people, and a
            manager who arranges a colleague usually wants to check their own settings in the same
            visit.
          */}
          {tab === 'notifications' && (
            <div className="space-y-6">
              <NotificationsTab />
              <NotificationRecipients />
            </div>
          )}
          {tab === 'security' && <SecurityTab />}
          {tab === 'branding' && <BrandingTab />}
          {tab === 'projects' && <ProjectsTab />}
        </div>
      </div>
    </div>
  )
}
