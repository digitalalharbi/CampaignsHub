import { BarChart3, Layers, Megaphone, Moon, ShieldCheck, Sun, Users, Zap } from 'lucide-react'
import { useT } from '@/lib/i18n'
import { useUi } from '@/stores/ui'

const FEATURES = [
  { key: 'auth_feat_unified', icon: Layers },
  { key: 'auth_feat_realtime', icon: BarChart3 },
  { key: 'auth_feat_reports', icon: Megaphone },
  { key: 'auth_feat_automation', icon: Zap },
  { key: 'auth_feat_isolation', icon: ShieldCheck },
  { key: 'auth_feat_team', icon: Users },
] as const

/** Premium split auth layout shared by login / register / forgot-password (marketing panel + form). */
export function AuthShell({ children }: { children: React.ReactNode }) {
  const t = useT()
  const { theme, locale, toggleTheme, toggleLocale } = useUi()

  return (
    <div className="grid min-h-screen grid-cols-1 bg-background lg:grid-cols-[1.05fr_1fr]">
      <aside className="relative hidden overflow-hidden bg-gradient-to-br from-[#0b1020] via-[#141a33] to-[#0b1020] p-10 text-white lg:flex lg:flex-col lg:justify-between xl:p-14">
        <div className="pointer-events-none absolute -end-24 -top-24 h-72 w-72 rounded-full bg-brand-500/20 blur-3xl" />
        <div className="pointer-events-none absolute -bottom-24 -start-16 h-72 w-72 rounded-full bg-[var(--purple)]/20 blur-3xl" />

        <div className="relative flex items-center gap-2.5">
          <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 shadow-lg">
            <Megaphone size={20} />
          </div>
          <span className="text-xl font-extrabold tracking-tight">{t('app_name')}</span>
        </div>

        <div className="relative max-w-lg">
          <p className="text-sm font-semibold text-brand-300">{t('auth_hero_eyebrow')}</p>
          <h1 className="mt-3 font-[var(--font-heading)] text-3xl font-extrabold leading-tight xl:text-4xl">{t('auth_hero_title')}</h1>
          <p className="mt-4 text-sm leading-relaxed text-white/70">{t('auth_hero_subtitle')}</p>
          <div className="mt-8 grid grid-cols-2 gap-3">
            {FEATURES.map(({ key, icon: Icon }) => (
              <div key={key} className="flex items-center gap-2.5 rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 backdrop-blur-sm">
                <Icon size={16} className="shrink-0 text-brand-300" />
                <span className="text-xs font-semibold">{t(key)}</span>
              </div>
            ))}
          </div>
        </div>

        <div className="relative text-xs text-white/40">© {new Date().getFullYear()} {t('app_name')}</div>
      </aside>

      <main className="flex flex-col px-5 py-6 sm:px-8">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2 lg:invisible">
            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-brand-500 to-brand-700 text-white"><Megaphone size={16} /></div>
            <span className="font-extrabold text-text-primary">{t('app_name')}</span>
          </div>
          <div className="flex items-center gap-1.5">
            <button onClick={toggleLocale} aria-label="Toggle language" className="flex h-9 min-w-9 items-center justify-center rounded-lg px-2 text-sm font-semibold text-text-secondary hover:bg-surface-hover">{locale === 'ar' ? 'EN' : 'ع'}</button>
            <button onClick={toggleTheme} aria-label="Toggle theme" className="flex h-9 w-9 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover">{theme === 'light' ? <Moon size={18} /> : <Sun size={18} />}</button>
          </div>
        </div>

        <div className="mx-auto flex w-full max-w-[400px] flex-1 flex-col justify-center py-8">{children}</div>
      </main>
    </div>
  )
}

export function AuthField({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
  return (
    <label className="block">
      <span className="mb-1.5 block text-sm font-semibold text-text-secondary">{label}</span>
      {children}
      {error && <span className="mt-1 block text-xs text-danger">{error}</span>}
    </label>
  )
}

export const authInputClass =
  'w-full rounded-xl border border-border bg-surface-secondary px-3.5 py-3 text-base outline-none focus:border-brand-500 focus:bg-surface focus:ring-[3px] focus:ring-brand-500/15'
