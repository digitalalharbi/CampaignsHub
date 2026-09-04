import { Globe, Moon, Sun } from 'lucide-react'
import { useUi } from '@/stores/ui'

/**
 * Personal language & appearance — a USER setting (account menu only, never in system settings).
 * Both values live in the persisted UI store, so a change applies immediately and survives reload.
 */
const COPY = {
  ar: {
    title: 'اللغة والمظهر', subtitle: 'تفضيلاتك الشخصية للعرض — تُطبَّق على حسابك فقط.',
    language: 'اللغة', arabic: 'العربية', english: 'English',
    theme: 'المظهر', light: 'فاتح', dark: 'داكن',
    note: 'هذه تفضيلات شخصية ولا تؤثر على بقية أعضاء مساحة العمل.',
  },
  en: {
    title: 'Language & appearance', subtitle: 'Your personal display preferences — applied to your account only.',
    language: 'Language', arabic: 'العربية', english: 'English',
    theme: 'Theme', light: 'Light', dark: 'Dark',
    note: 'These are personal preferences and do not affect other workspace members.',
  },
}

export function PreferencesPage() {
  const { locale, theme, toggleLocale, toggleTheme } = useUi()
  const c = COPY[locale]

  return (
    <div className="flex flex-col gap-5">
      <header className="flex flex-col gap-1">
        <h1 className="text-2xl font-extrabold tracking-tight text-text-primary">{c.title}</h1>
        <p className="text-sm text-text-secondary">{c.subtitle}</p>
      </header>

      <section className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-5">
        <h2 className="flex items-center gap-2 text-sm font-bold text-text-primary"><Globe size={15} /> {c.language}</h2>
        <div className="flex gap-2">
          {([['ar', c.arabic], ['en', c.english]] as const).map(([code, label]) => (
            <button
              key={code}
              onClick={() => { if (locale !== code) toggleLocale() }}
              aria-pressed={locale === code}
              className={`rounded-lg px-4 py-2 text-sm font-semibold transition-colors ${
                locale === code ? 'bg-brand-600 text-white' : 'bg-surface-hover text-text-secondary hover:text-text-primary'
              }`}
            >
              {label}
            </button>
          ))}
        </div>
      </section>

      <section className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-5">
        <h2 className="flex items-center gap-2 text-sm font-bold text-text-primary">
          {theme === 'dark' ? <Moon size={15} /> : <Sun size={15} />} {c.theme}
        </h2>
        <div className="flex gap-2">
          {([['light', c.light, Sun], ['dark', c.dark, Moon]] as const).map(([mode, label, Icon]) => (
            <button
              key={mode}
              onClick={() => { if (theme !== mode) toggleTheme() }}
              aria-pressed={theme === mode}
              className={`flex items-center gap-1.5 rounded-lg px-4 py-2 text-sm font-semibold transition-colors ${
                theme === mode ? 'bg-brand-600 text-white' : 'bg-surface-hover text-text-secondary hover:text-text-primary'
              }`}
            >
              <Icon size={14} /> {label}
            </button>
          ))}
        </div>
      </section>

      <p className="text-xs text-text-muted">{c.note}</p>
    </div>
  )
}
