import { BarChart3, Layers, Megaphone, Moon, ShieldCheck, Sun } from 'lucide-react'
import { useT } from '@/lib/i18n'
import { useUi } from '@/stores/ui'

// Three strong value props — a clean vertical list, not a wall of look-alike cards.
const FEATURES = [
  { key: 'auth_feat_unified', icon: Layers },
  { key: 'auth_feat_realtime', icon: BarChart3 },
  { key: 'auth_feat_isolation', icon: ShieldCheck },
] as const

/** Premium split auth layout shared by login / register / forgot-password (marketing panel + form). */
export function AuthShell({ children }: { children: React.ReactNode }) {
  const t = useT()
  const { theme, locale, toggleTheme, toggleLocale } = useUi()

  return (
    <div className="grid min-h-screen grid-cols-1 bg-background lg:grid-cols-[1.05fr_1fr]">
      <aside className="relative hidden overflow-hidden bg-gradient-to-br from-[var(--auth-panel-from)] via-[var(--auth-panel-via)] to-[var(--auth-panel-to)] p-10 text-white lg:flex lg:flex-col lg:justify-between xl:p-14">
        {/* Two soft glows, both in the emerald/teal family — no competing accent hue. */}
        <div className="pointer-events-none absolute -end-24 -top-24 h-72 w-72 rounded-full bg-brand-500/15 blur-3xl" />
        <div className="pointer-events-none absolute -bottom-28 -start-20 h-80 w-80 rounded-full bg-[var(--teal)]/10 blur-3xl" />

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
          <ul className="mt-8 space-y-3">
            {FEATURES.map(({ key, icon: Icon }) => (
              <li key={key} className="flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 px-4 py-3 backdrop-blur-sm">
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-500/15 text-brand-300"><Icon size={17} /></span>
                <span className="text-sm font-semibold">{t(key)}</span>
              </li>
            ))}
          </ul>
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

        <div className="mx-auto flex w-full max-w-[440px] flex-1 flex-col justify-center py-8">{children}</div>
      </main>
    </div>
  )
}
