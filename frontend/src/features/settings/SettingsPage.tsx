import { useState } from 'react'
import { Building2, FileText, Palette, ShieldCheck, Users, Briefcase, FolderKanban, Bell } from 'lucide-react'
import { GeneralTab } from './tabs/GeneralTab'
import { DisclaimerTab } from './tabs/DisclaimerTab'
import { TeamTab } from './tabs/TeamTab'
import { NotificationsTab } from './tabs/NotificationsTab'
import { SecurityTab } from './tabs/SecurityTab'
import { BrandingTab } from './tabs/BrandingTab'
import { ClientsTab } from './tabs/ClientsTab'
import { ProjectsTab } from './tabs/ProjectsTab'

const TABS = [
  { id: 'general', label: 'عام', icon: Building2 },
  { id: 'clients', label: 'العملاء', icon: Briefcase },
  { id: 'projects', label: 'المشاريع', icon: FolderKanban },
  { id: 'team', label: 'الفريق والصلاحيات', icon: Users },
  { id: 'notifications', label: 'الإشعارات', icon: Bell },
  { id: 'security', label: 'الأمان', icon: ShieldCheck },
  { id: 'branding', label: 'الهوية', icon: Palette },
  { id: 'disclaimer', label: 'الملاحظات والمنهجية', icon: FileText },
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
  const shown = only ? TABS.filter((t) => only.includes(t.id)) : TABS
  const [tab, setTab] = useState<TabId>(shown[0]?.id ?? 'general')
  const single = shown.length === 1

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-3xl font-extrabold tracking-tight text-text-primary">{title ?? 'الإعدادات'}</h1>
        <p className="mt-1 text-sm text-text-secondary">
          {subtitle ?? 'إعدادات مساحة العمل — المؤسسة والعملاء والمشاريع والإشعارات والأمان'}
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
                  {t.label}
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
          {tab === 'notifications' && <NotificationsTab />}
          {tab === 'security' && <SecurityTab />}
          {tab === 'branding' && <BrandingTab />}
          {tab === 'projects' && <ProjectsTab />}
        </div>
      </div>
    </div>
  )
}
