import { BrandMark } from '@/components/brand/BrandMark'
import { type HeaderIdentity } from './sharedBranding'

/**
 * REPORT-IDENTITY-001 — who prepared this report, and who it is for.
 *
 * ## The fact that was missing
 *
 * Both marks were already resolved and already drawn: the client's leads a shared report, the
 * preparer's sits beside it. What no surface said is WHICH IS WHICH. Two logos side by side with a
 * name under each is a layout, not an answer — and on a report that goes to a client, «who made this»
 * and «who is it about» are the two facts the page exists to establish before any figure.
 *
 * So each mark is labelled with its role: «من إعداد» for the preparer, «مقدم إلى» for the subject.
 * The words are generic by rule — this component never names a company or a client itself, it only
 * draws the identities it is handed.
 *
 * ## A missing logo is a name, never a gap
 *
 * Every block falls back to its name. A report whose preparer has uploaded no mark still says who
 * prepared it, and a client with no mark is still named — which is why the name is always rendered
 * and the mark only when there is one.
 *
 * ## Every mark gets the same plate
 *
 * The marks used to be sized by HEIGHT with the width left to the artwork, which drew a 120×600 crest
 * six pixels wide beside a wordmark seventy-two wide — see `BrandMark`. They are drawn on one plate
 * now, so the two blocks are parallel and their names begin at the same place whatever was uploaded.
 */
export function ReportIdentity({ identity, ar, layout = 'rows', testid = 'report-identity' }: {
  identity: HeaderIdentity
  ar: boolean
  /** `rows` for a form or a panel; `cover` for the top of the report itself. */
  layout?: 'rows' | 'cover'
  testid?: string
}) {
  /*
   * `by` is the preparer and `name` is the subject — but only when the two differ.
   *
   * `headerIdentity` already refuses «Nakheel, by Nakheel»: when the report is the agency's own, it
   * returns no `by`, and the single identity IS the preparer. Drawing a «مقدم إلى» block for it
   * would invent a client that does not exist.
   */
  const preparer = identity.by === null
    ? { name: identity.name, logoUrl: identity.logoUrl }
    : { name: identity.by, logoUrl: identity.byLogoUrl }

  const subject = identity.by === null ? null : { name: identity.name, logoUrl: identity.logoUrl }

  return (
    <div
      data-testid={testid}
      className={layout === 'cover' ? 'flex flex-wrap items-end justify-between gap-4' : 'grid gap-3 sm:grid-cols-2'}
    >
      {/* The subject leads: a client reads their own name first, and the report is about them. */}
      {subject && (
        <IdentityBlock
          testid={`${testid}-subject`}
          role={ar ? 'مقدم إلى' : 'Prepared for'}
          name={subject.name}
          logoUrl={subject.logoUrl}
          size={layout === 'cover' ? 'lg' : 'md'}
        />
      )}

      <IdentityBlock
        testid={`${testid}-preparer`}
        role={ar ? 'من إعداد' : 'Prepared by'}
        name={preparer.name}
        logoUrl={preparer.logoUrl}
        size="md"
      />
    </div>
  )
}

function IdentityBlock({ role, name, logoUrl, size, testid }: {
  role: string
  name: string
  logoUrl: string | null
  size: 'md' | 'lg'
  testid: string
}) {
  return (
    <div className="flex min-w-0 flex-col gap-1" data-testid={testid}>
      <span className="text-[11px] font-bold uppercase tracking-wide opacity-70">{role}</span>
      <span className="flex min-w-0 items-center gap-2">
        {/*
          One plate per mark — fixed on BOTH axes, contained and centred inside. A wordmark, a square
          badge and a tall crest then occupy the same rectangle, so the subject block and the preparer
          block line up instead of each following its own artwork.
        */}
        {logoUrl !== null && (
          <BrandMark src={logoUrl} testid={`${testid}-logo`} size={size === 'lg' ? 'lg' : 'md'} />
        )}
        {/*
          The name is always here, mark or no mark. It is the fallback the whole rule rests on: a
          report must say who prepared it and who it is for even when nobody has uploaded anything.
        */}
        <span className={`truncate font-bold ${size === 'lg' ? 'text-lg' : 'text-sm'}`} data-testid={`${testid}-name`}>
          {name}
        </span>
      </span>
    </div>
  )
}
