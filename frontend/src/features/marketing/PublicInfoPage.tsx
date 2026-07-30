import { useEffect } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft, ArrowRight, Check, Info, Mail, Megaphone } from 'lucide-react'
import { HOME_COPY, type Locale } from './homeCopy'
import { CONTACT_EMAIL, LEGAL_DOCS, findLegalDoc } from './legalContent'
import { Button } from '@/components/ui/Button'
import { useUi } from '@/stores/ui'

/**
 * The public policy and company pages behind the footer links — privacy, terms, data processing,
 * cookies, security, about, contact, support and FAQ.
 *
 * One component renders them all from `legalContent`, so every page shares the same header, footer,
 * language and theme behaviour as the homepage, and adding a page is a content change rather than a
 * new screen. An unknown slug renders a clear not-found state with a way back instead of a blank page.
 */
export function PublicInfoPage() {
  const { locale, theme, toggleLocale, toggleTheme } = useUi()
  const c = HOME_COPY[locale as Locale]
  const { slug = '' } = useParams()
  const doc = findLegalDoc(locale as Locale, slug)
  const Arrow = c.dir === 'rtl' ? ArrowLeft : ArrowRight

  // These pages own their direction, exactly like the marketing homepage.
  useEffect(() => {
    document.documentElement.setAttribute('dir', c.dir)
    document.documentElement.setAttribute('lang', locale)
  }, [c.dir, locale])

  useEffect(() => {
    if (doc) document.title = `${doc.title} — CampaignsHub`
  }, [doc])

  const others = LEGAL_DOCS[locale as Locale].filter((d) => d.slug !== slug)

  return (
    <div className="min-h-screen bg-background text-text-primary" dir={c.dir}>
      <header className="sticky top-0 z-40 border-b border-border bg-surface/85 backdrop-blur-md">
        <div className="mx-auto flex h-16 max-w-4xl items-center gap-4 px-4 sm:px-6">
          <Link to="/" className="flex shrink-0 items-center gap-2.5">
            <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 text-white"><Megaphone size={18} /></span>
            <span className="font-heading text-lg font-extrabold tracking-tight">CampaignsHub</span>
          </Link>
          <div className="ms-auto flex items-center gap-1.5">
            <button onClick={toggleLocale} aria-label="Toggle language" className="flex h-9 min-w-9 items-center justify-center rounded-lg px-2 text-sm font-semibold text-text-secondary hover:bg-surface-hover">{locale === 'ar' ? 'EN' : 'ع'}</button>
            <button onClick={toggleTheme} aria-label="Toggle theme" className="flex h-9 w-9 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover">{theme === 'light' ? '🌙' : '☀️'}</button>
            <Link to="/"><Button variant="secondary" size="sm">{locale === 'ar' ? 'العودة للرئيسية' : 'Back to home'}</Button></Link>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-4xl px-4 py-10 sm:px-6">
        {!doc ? (
          <div className="rounded-2xl border border-border bg-surface p-8 text-center">
            <h1 className="font-heading text-2xl font-extrabold">{locale === 'ar' ? 'الصفحة غير موجودة' : 'Page not found'}</h1>
            <p className="mt-2 text-text-secondary">
              {locale === 'ar' ? 'الرابط الذي فتحته غير صحيح أو لم يعد متاحًا.' : 'That link is not valid or is no longer available.'}
            </p>
            <Link to="/" className="mt-5 inline-block"><Button>{locale === 'ar' ? 'العودة للرئيسية' : 'Back to home'} <Arrow size={15} /></Button></Link>
          </div>
        ) : (
          <>
            <p className="text-[12px] font-semibold text-brand-600">{doc.updated}</p>
            <h1 className="mt-1.5 font-heading text-[28px] font-extrabold leading-tight sm:text-[34px]">{doc.title}</h1>
            <p className="mt-3 text-[15px] leading-relaxed text-text-secondary">{doc.intro}</p>

            <div className="mt-8 space-y-6">
              {doc.sections.map((section) => (
                <section key={section.heading} className="rounded-2xl border border-border bg-surface p-5">
                  <h2 className="font-heading text-[17px] font-extrabold text-text-primary">{section.heading}</h2>
                  {section.body?.map((para) => (
                    <p key={para} className="mt-2.5 text-[14px] leading-relaxed text-text-secondary">{para}</p>
                  ))}
                  {section.bullets && (
                    <ul className="mt-2.5 space-y-1.5">
                      {section.bullets.map((b) => (
                        <li key={b} className="flex items-start gap-2 text-[14px] leading-relaxed text-text-secondary">
                          <Check size={14} className="mt-1 shrink-0 text-brand-500" /> {b}
                        </li>
                      ))}
                    </ul>
                  )}
                </section>
              ))}
            </div>

            {doc.disclaimer && (
              <p className="mt-6 flex items-start gap-2 rounded-xl border border-border bg-surface-secondary p-3.5 text-[12.5px] leading-relaxed text-text-muted">
                <Info size={14} className="mt-0.5 shrink-0" /> {doc.disclaimer}
              </p>
            )}

            <div className="mt-6 flex flex-wrap items-center gap-3 rounded-2xl border border-border bg-surface p-5">
              <Mail size={16} className="text-brand-600" />
              <span className="text-[14px] text-text-secondary">
                {locale === 'ar' ? 'لأي استفسار:' : 'Any questions:'}{' '}
                <a href={`mailto:${CONTACT_EMAIL}`} className="font-semibold text-brand-600 hover:underline" dir="ltr">{CONTACT_EMAIL}</a>
              </span>
              <Link to="/register" className="ms-auto"><Button size="sm">{c.nav.start} <Arrow size={14} /></Button></Link>
            </div>

            {/* Sibling pages, so a reader never has to go back to the footer to find the next one. */}
            <nav aria-label={doc.title} className="mt-8 flex flex-wrap gap-2 border-t border-border pt-6">
              {others.map((d) => (
                <Link key={d.slug} to={`/${d.slug}`} className="rounded-lg border border-border px-3 py-1.5 text-[12.5px] font-medium text-text-secondary hover:border-brand-300 hover:text-brand-600">
                  {d.title}
                </Link>
              ))}
            </nav>
          </>
        )}
      </main>

      <footer className="border-t border-border bg-surface py-6 text-center text-xs text-text-muted">
        © {new Date().getFullYear()} CampaignsHub — {c.footer.rights}
      </footer>
    </div>
  )
}
