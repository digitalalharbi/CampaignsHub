import { Link, useLocation } from 'react-router-dom'
import { ArrowLeft, ArrowRight, Check, Info, Mail } from 'lucide-react'
import { useQuery } from '@tanstack/react-query'
import { HOME_COPY, type Locale } from './homeCopy'
import { getLegalMeta } from '@/features/admin/legalApi'
import { ContactForm, DataRequestForm, SupportForm } from './PublicFormPages'
import { CONTACT_EMAIL, LEGAL_DOCS, findLegalDoc } from './legalContent'
import { Button } from '@/components/ui/Button'
import { PublicPageShell } from './PublicPageShell'
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
  const { locale } = useUi()
  const c = HOME_COPY[locale as Locale]
  /*
   * The slug comes from the PATH, not from a route param (LEGAL-001).
   *
   * These pages are registered as static routes — `/privacy`, `/terms`, one per document — so there
   * is no `:slug` segment for `useParams()` to fill, and it returned undefined every time. The result
   * was that EVERY policy link in the footer rendered «الصفحة غير موجودة»: the pages existed, the
   * routes existed, and the one line joining them read the wrong source. It never showed up in a test
   * because the tests rendered the component with a slug prop-shaped fixture rather than through the
   * router, and never in review because nobody clicks a privacy link.
   *
   * Reading the pathname works for both shapes — a static route and a parameterised one — so adding
   * a document stays a content change.
   */
  const slug = useLocation().pathname.replace(/^\/+|\/+$/g, '')
  const doc = findLegalDoc(locale as Locale, slug)

  /*
   * LEGAL-001 — the version strip and the operator's identity come from the API, not from this file.
   *
   * A policy has to say which version is in force and since when, because that is what an acceptance
   * record points at. And the operator's legal identity is a business fact nobody here can know — so
   * where it is unset the page says the operator has not published it, rather than printing a blank
   * or, worse, a plausible invention.
   *
   * The page renders fully without this: the text is local and the strip is additive. A failed fetch
   * costs a version line, never the policy itself.
   */
  const legal = useQuery({ queryKey: ['legal'], queryFn: getLegalMeta, staleTime: 5 * 60_000, retry: 1 })
  const version = legal.data?.documents.find((d) => d.slug === slug)
  const operator = legal.data?.operator
  const Arrow = c.dir === 'rtl' ? ArrowLeft : ArrowRight

  const others = LEGAL_DOCS[locale as Locale].filter((d) => d.slug !== slug)

  return (
    <PublicPageShell title={doc?.title ?? (locale === 'ar' ? 'الصفحة غير موجودة' : 'Page not found')}>
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

            {/* Version and effective date — what an acceptance record points at. */}
            {version && (
              <p data-testid="policy-version" className="mt-3 inline-flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border border-border bg-surface-secondary px-3 py-1.5 text-[12.5px] text-text-secondary">
                <span>{locale === 'ar' ? 'النسخة' : 'Version'} <span className="font-semibold text-text-primary" dir="ltr">{version.version}</span></span>
                <span>{locale === 'ar' ? 'سارية من' : 'Effective'} <span className="font-semibold text-text-primary" dir="ltr">{version.effective}</span></span>
                {version.binding && (
                  <span className="rounded-full bg-surface px-2 py-0.5 font-semibold text-text-muted">
                    {locale === 'ar' ? 'ملزمة عند التسجيل والدفع' : 'Accepted at sign-up and payment'}
                  </span>
                )}
              </p>
            )}

            {/*
              Who is publishing this. Unset is stated rather than hidden: a policy that cannot name
              its controller is one a reader cannot act on, and inventing a company would be worse.
            */}
            {operator && (
              <p data-testid="policy-operator" className="mt-2 text-[12.5px] leading-relaxed text-text-muted">
                {operator.published ? (
                  <>
                    {locale === 'ar' ? 'الجهة المشغّلة: ' : 'Operated by: '}
                    <span className="font-semibold text-text-secondary">
                      {(locale === 'ar' ? operator.legal_name_ar : operator.legal_name_en) ?? operator.legal_name_en ?? operator.legal_name_ar}
                    </span>
                    {operator.registration_number && <> · {locale === 'ar' ? 'سجل تجاري' : 'Registration'} <span dir="ltr">{operator.registration_number}</span></>}
                    {operator.jurisdiction && <> · {operator.jurisdiction}</>}
                  </>
                ) : (
                  locale === 'ar'
                    ? 'لم يَنشر مشغّل المنصة بياناته القانونية بعد.'
                    : 'The platform operator has not published its legal details yet.'
                )}
              </p>
            )}

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

            {/*
              LEGAL-002 — the working form, on the page that explains it.
              A separate «forms» page would mean reading what a data request means and then losing
              the explanation to submit one.
            */}
            {slug === 'contact' && <div className="mt-8"><ContactForm /></div>}
            {slug === 'support' && <div className="mt-8"><SupportForm /></div>}
            {(slug === 'data-requests' || slug === 'account-deletion') && <div className="mt-8"><DataRequestForm /></div>}

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
                <Link key={d.slug} to={`/${d.slug}`} className="flex min-h-11 items-center rounded-lg border border-border px-3 py-1.5 text-[12.5px] font-medium text-text-secondary hover:border-brand-300 hover:text-brand-600 sm:min-h-0">
                  {d.title}
                </Link>
              ))}
            </nav>
          </>
        )}
    </PublicPageShell>
  )
}
