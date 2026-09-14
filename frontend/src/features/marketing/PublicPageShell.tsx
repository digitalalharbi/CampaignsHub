import { useEffect, type ReactNode } from 'react'
import { FooterContact } from './FooterContact'
import { HOME_COPY, type Locale } from './homeCopy'
import { PublicHeader } from './PublicHeader'
import { useUi } from '@/stores/ui'
import { brand, productName } from '@/lib/brand'

/**
 * The frame every public page outside the marketing homepage wears.
 *
 * It was inlined in `PublicInfoPage` and nowhere else, so `/data-deletion` — a page written later,
 * against the same design — shipped with no header, no footer, no logo and no way to change language.
 * A platform reviewer opening that URL cold saw an unbranded form and nothing identifying whose site
 * it was, which is a poor answer to «is this really CampaignsHub's deletion page?».
 *
 * Extracted rather than copied. A second hand-built copy of a header is a second header to keep in
 * step, and the first thing that drifts is the thing nobody looks at — which on these pages is
 * everything, because policy pages are read by strangers and reviewed by nobody.
 *
 * ## What it owns
 *
 * Direction and language on the document element, the page title, and the chrome. These pages set
 * `dir` themselves because they are reachable without ever passing through the app shell — somebody
 * lands on `/privacy` from a Google result, or on `/data-deletion` from a Meta review form.
 */
export function PublicPageShell({
  /** Page title, already localised. Rendered into `document.title`, never on screen. */
  title,
  children,
}: {
  title: string
  children: ReactNode
}) {
  const { locale } = useUi()
  const c = HOME_COPY[locale as Locale]

  useEffect(() => {
    document.documentElement.setAttribute('dir', c.dir)
    document.documentElement.setAttribute('lang', locale)
  }, [c.dir, locale])

  /*
   * BRAND-MARK-001 — the tab follows the language the page is in.
   *
   * The dependency list was `[title]`, so this ran once and never again: a reader switching to
   * English got an English page under an Arabic tab, and back. `productName(locale)` was already
   * being called — with a `locale` the effect had been told to ignore, which is the quiet kind of
   * wrong, because the code reads as though it handles the case.
   */
  useEffect(() => {
    document.title = `${title} — ${productName(locale)}`
  }, [title, locale])

  return (
    <div className="min-h-screen bg-background text-text-primary" dir={c.dir}>
      <PublicHeader
        width="max-w-4xl"
        primaryAction={{ to: '/', label: locale === 'ar' ? 'العودة للرئيسية' : 'Back to home', variant: 'secondary' }}
      />

      <main className="mx-auto max-w-4xl px-4 py-10 sm:px-6">{children}</main>

      {/*
        * MKT-CONTACT-001 — the same two facts, from the same source.
        *
        * A visitor who reached a policy page from a search result, or the services page from an ad,
        * is as entitled to a way of contacting somebody as one who read the homepage to the end.
        * `variant="inline"` keeps this a line of small print rather than growing a second full
        * footer beside the one that already exists.
        */}
      <footer className="border-t border-border bg-surface py-6 text-center text-xs text-text-muted">
        <div className="flex flex-col items-center gap-2 px-4">
          <FooterContact locale={locale as Locale} variant="inline" />
          <span>© {new Date().getFullYear()} {locale === 'ar' ? brand.lockup.nameAr : brand.lockup.nameEn} — {c.footer.rights}</span>
        </div>
      </footer>
    </div>
  )
}
