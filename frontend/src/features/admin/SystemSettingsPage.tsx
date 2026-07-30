import { useState } from 'react'
import { Activity, FileText, Globe, Layers, MessageSquareQuote, Plug, ShieldCheck } from 'lucide-react'
import { PublicPagesSettingsPage } from '@/features/settings/PublicPagesSettingsPage'
import { SettingsPage } from '@/features/settings/SettingsPage'
import { TaxonomyManagerPage } from '@/features/taxonomy/TaxonomyManagerPage'
import { IntegrationsTab, PermissionsTab, StatusTab } from './PlatformOpsTabs'
import { useUi } from '@/stores/ui'

/**
 * `/admin/settings` — the platform's own settings (ADMIN-001).
 *
 * These four surfaces were reachable at `/app/settings/*`, inside the ADVERTISER's workspace, where
 * a tenant administrator could edit them. They are platform-level: the public marketing site, the
 * notes every client portal shows, and the shared taxonomy layer that every tenant inherits. They
 * belong to whoever owns the platform, so they live here now — the `/app` paths redirect rather than
 * disappearing, because they are in bookmarks.
 *
 * ONE page with tabs, not four routes. The structure rule is a maximum of two levels in the rail,
 * and a system-settings section that fans out into a page per option is how a console becomes a maze.
 * The engines behind each tab are the existing ones, mounted here — not reimplemented.
 */

type TabKey = 'public-site' | 'portals' | 'taxonomies' | 'services' | 'permissions' | 'integrations' | 'status'

const TABS: { key: TabKey; ar: string; en: string; icon: typeof Globe }[] = [
  { key: 'public-site', ar: 'الموقع العام', en: 'Public site', icon: Globe },
  { key: 'portals', ar: 'ملاحظات البوابات', en: 'Portal notes', icon: MessageSquareQuote },
  { key: 'taxonomies', ar: 'التصنيفات', en: 'Taxonomies', icon: Layers },
  { key: 'services', ar: 'الخدمات', en: 'Services', icon: FileText },
  // ADMIN-003 — three read surfaces. Tabs rather than rail entries: the structure rule is two levels,
  // and three read-only lists do not each warrant a place in the navigation.
  { key: 'permissions', ar: 'الصلاحيات العامة', en: 'Permissions', icon: ShieldCheck },
  { key: 'integrations', ar: 'التكاملات', en: 'Integrations', icon: Plug },
  { key: 'status', ar: 'الحالة التشغيلية', en: 'Operational status', icon: Activity },
]

export function SystemSettingsPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const [tab, setTab] = useState<TabKey>('public-site')

  return (
    <div className="w-full">
      <header className="mb-5">
        <h1 className="font-heading text-3xl font-extrabold tracking-tight text-text-primary">
          {ar ? 'إعدادات النظام' : 'System settings'}
        </h1>
        <p className="mt-1 text-sm text-text-secondary">
          {ar
            ? 'ما يخص المنصة نفسها — لا إعدادات مساحة عمل ولا إعدادات مستخدم.'
            : 'What belongs to the platform itself — no workspace settings, no personal settings.'}
        </p>
      </header>

      <div className="mb-5 flex flex-wrap gap-1 border-b border-border" role="tablist">
        {TABS.map((t) => (
          <button
            key={t.key}
            role="tab"
            aria-selected={tab === t.key}
            data-testid={`admin-settings-tab-${t.key}`}
            onClick={() => setTab(t.key)}
            className={`-mb-px flex items-center gap-1.5 border-b-2 px-3.5 py-2.5 text-sm font-semibold transition-colors ${
              tab === t.key
                ? 'border-brand-500 text-brand-700'
                : 'border-transparent text-text-secondary hover:text-text-primary'
            }`}
          >
            <t.icon size={15} aria-hidden />
            {ar ? t.ar : t.en}
          </button>
        ))}
      </div>

      <div role="tabpanel">
        {tab === 'public-site' && <PublicPagesSettingsPage />}
        {tab === 'portals' && (
          <SettingsPage
            only={['disclaimer']}
            title={ar ? 'ملاحظات البوابات' : 'Portal notes'}
            subtitle={ar
              ? 'الملاحظات والمنهجية التي يراها العملاء في البوابة والتقارير.'
              : 'The notes and methodology clients see in the portal and in reports.'}
          />
        )}
        {tab === 'taxonomies' && <TaxonomyManagerPage />}
        {tab === 'permissions' && <PermissionsTab />}
        {tab === 'integrations' && <IntegrationsTab />}
        {tab === 'status' && <StatusTab />}
        {tab === 'services' && (
          <div className="rounded-2xl border border-border bg-surface p-5">
            <p className="text-sm text-text-secondary">
              {ar
                ? 'كتالوج الخدمات يُدار من محرّك التصنيفات نفسه: افتح تبويب «التصنيفات» واختر التعريفات التي تبدأ بـ request. — الخدمة والفئة والنوع.'
                : 'The service catalogue is managed by the same taxonomy engine: open the Taxonomies tab and pick the definitions beginning request. — service, category and type.'}
            </p>
            <button
              type="button"
              onClick={() => setTab('taxonomies')}
              className="mt-3 text-sm font-semibold text-brand-600 hover:underline"
            >
              {ar ? 'افتح التصنيفات' : 'Open Taxonomies'}
            </button>
          </div>
        )}
      </div>
    </div>
  )
}
