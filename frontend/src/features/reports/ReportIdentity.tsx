import { hideBrokenLogo, type HeaderIdentity } from './sharedBranding'

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
 * `object-contain` on every mark, with the box fixed in height and free in width: a wide wordmark,
 * a square badge and a tall crest all have to arrive uncropped, and `cover` would cut whichever of
 * them did not match the box.
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
          Height fixed, width free, `object-contain`. A wordmark three times wider than it is tall
          and a square badge both have to arrive whole; `cover` would crop whichever did not fit.
        */}
        {logoUrl !== null && (
          <img
            src={logoUrl}
            alt=""
            data-testid={`${testid}-logo`}
            onError={hideBrokenLogo}
            className={`w-auto shrink-0 object-contain ${size === 'lg' ? 'h-9 max-w-[180px]' : 'h-6 max-w-[140px]'}`}
          />
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
