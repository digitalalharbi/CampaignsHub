import { BarChart3, Layers, Megaphone, Moon, ShieldCheck, Sun } from 'lucide-react'
import { useT } from '@/lib/i18n'
import { useUi } from '@/stores/ui'

// Four value props, each with a one-line description — sells the product, not a wall of look-alike chips.
const FEATURES = [
  { key: 'auth_feat_unified', desc: 'auth_feat_unified_desc', icon: Layers },
  { key: 'auth_feat_realtime', desc: 'auth_feat_realtime_desc', icon: BarChart3 },
  { key: 'auth_feat_reports', desc: 'auth_feat_reports_desc', icon: Megaphone },
  { key: 'auth_feat_automation', desc: 'auth_feat_automation_desc', icon: ShieldCheck },
] as const

/** Premium split auth layout shared by login / register / forgot-password (marketing panel + form). */
export function AuthShell({ children }: { children: React.ReactNode }) {
  const t = useT()
  const { theme, locale, toggleTheme, toggleLocale } = useUi()

  return (
    <div className="grid min-h-screen grid-cols-1 bg-background lg:grid-cols-[1.3fr_1fr]">
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

        <div className="relative max-w-xl">
          <p className="text-[13px] font-semibold text-brand-300">{t('auth_hero_eyebrow')}</p>
          <h1 className="mt-3 font-[var(--font-heading)] text-4xl font-extrabold leading-[1.15] xl:text-5xl">{t('auth_hero_title')}</h1>
          <p className="mt-4 text-lg leading-relaxed text-white/75">{t('auth_hero_subtitle')}</p>
          <ul className="mt-9 space-y-3.5">
            {FEATURES.map(({ key, desc, icon: Icon }) => (
              <li key={key} className="flex items-start gap-3.5 rounded-xl border border-white/10 bg-white/5 px-4 py-3.5 backdrop-blur-sm">
                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-500/15 text-brand-300"><Icon size={18} /></span>
                <span className="min-w-0">
                  <span className="block text-[15px] font-bold">{t(key)}</span>
                  <span className="mt-0.5 block text-[13px] leading-snug text-white/60">{t(desc)}</span>
                </span>
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
