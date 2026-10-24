import { HOME_COPY } from './homeCopy'
import type { Locale } from '@/stores/ui'

/**
 * MKT-CONTACT-001 — how to reach a person, in ONE place.
 *
 * The public site has three footers: the homepage's four-column one, the policy shell's copyright
 * bar, and the services page's. Writing an address and a phone number into each would be three
 * copies of two facts, and the first one to drift is the one nobody looks at — which for a footer is
 * all of them.
 *
 * So the strings live in `HOME_COPY.footer` beside the rest of the footer's words, and this renders
 * them. `variant` changes the shape, never the content.
 *
 * ## Why the phone is two strings
 *
 * `phone` is what a person READS and `phoneHref` is what the device DIALS. The readable form is
 * spaced for a Saudi mobile; some dialers refuse a `tel:` containing spaces, so the href keeps the
 * unbroken E.164 number.
 *
 * ## Why `dir="ltr"` sits on the values and not the block
 *
 * An email address and a phone number read left to right in every locale. Inside an RTL paragraph an
 * unmarked «+966 53 211 5582» renders with its plus sign stranded at the wrong end, and an address
 * can break around the «@». Marking the VALUES fixes both without forcing the Arabic labels around
 * them into the wrong direction.
 */
const LINK =
  'rounded-sm font-semibold text-brand-600 underline-offset-2 hover:underline ' +
  'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500'

export function FooterContact({
  locale,
  variant = 'block',
}: {
  locale: Locale
  variant?: 'block' | 'inline'
}) {
  const c = HOME_COPY[locale].footer

  const email = (
    <a href={`mailto:${c.email}`} data-testid="footer-email" dir="ltr" className={LINK}>
      {c.email}
    </a>
  )

  const phone = (
    <a href={`tel:${c.phoneHref}`} data-testid="footer-phone" dir="ltr" className={LINK}>
      {c.phone}
    </a>
  )

  /*
   * The compact form, for footers that are a single line of small print. No heading: a «تواصل معنا»
   * title above one line of text would be more chrome than content on a policy page.
   */
  if (variant === 'inline') {
    return (
      <span data-testid="footer-contact" className="inline-flex flex-wrap items-center justify-center gap-x-2 gap-y-1">
        <span>{email}</span>
        <span aria-hidden="true" className="text-border-strong">·</span>
        <span>{phone}</span>
      </span>
    )
  }

  return (
    <div data-testid="footer-contact" className="mt-5">
      <h2 className="text-sm font-bold text-text-primary">{c.contactTitle}</h2>
      <dl className="mt-2 space-y-1.5 text-sm">
        <div className="flex flex-wrap items-baseline gap-x-1.5">
          <dt className="text-text-secondary">{c.emailLabel}:</dt>
          <dd>{email}</dd>
        </div>
        <div className="flex flex-wrap items-baseline gap-x-1.5">
          <dt className="text-text-secondary">{c.phoneLabel}:</dt>
          <dd>{phone}</dd>
        </div>
      </dl>
    </div>
  )
}
