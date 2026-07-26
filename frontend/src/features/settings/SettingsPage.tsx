import { useState } from 'react'
import { Building2, FileText, Palette, ShieldCheck, Users, Briefcase, FolderKanban, Bell } from 'lucide-react'
import { GeneralTab } from './tabs/GeneralTab'
import { DisclaimerTab } from './tabs/DisclaimerTab'
import { PlaceholderTab } from './tabs/PlaceholderTab'

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

export function SettingsPage() {
  const [tab, setTab] = useState<TabId>('general')

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-3xl font-extrabold tracking-tight text-text-primary">الإعدادات</h1>
        <p className="mt-1 text-sm text-text-secondary">إدارة المؤسسة والعملاء والمشاريع والفريق والأمان والهوية والملاحظات</p>
      </div>

      <div className="grid gap-6 lg:grid-cols-[220px_1fr]">
        <nav className="flex gap-1 overflow-x-auto lg:flex-col lg:overflow-visible">
          {TABS.map((t) => {
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

        <div className="min-w-0">
          {tab === 'general' && <GeneralTab />}
          {tab === 'disclaimer' && <DisclaimerTab />}
          {tab === 'clients' && <PlaceholderTab title="العملاء" hint="إدارة العملاء وأرشفتهم وأعضائهم وتقاريرهم." link="/clients" linkLabel="فتح إدارة العملاء" />}
          {tab === 'projects' && <PlaceholderTab title="المشاريع" hint="إنشاء المشاريع وربطها بالعملاء وتحديد مصادر الحقيقة والإسناد." link="/campaigns" linkLabel="فتح المشاريع" />}
          {tab === 'team' && <PlaceholderTab title="الفريق والصلاحيات" hint="تُدار عضوية كل مشروع وصلاحياته من صفحة فريق المشروع." />}
          {tab === 'notifications' && <PlaceholderTab title="الإشعارات" hint="تفضيلات الإشعارات لكل مستخدم ومشروع." />}
          {tab === 'security' && <PlaceholderTab title="الأمان" hint="كلمة المرور، الجلسات، الأجهزة، سجل الدخول، وMFA." />}
          {tab === 'branding' && <PlaceholderTab title="الهوية" hint="الشعار والألوان واسم البوابة وتذييل التقارير." />}
        </div>
      </div>
    </div>
  )
}
