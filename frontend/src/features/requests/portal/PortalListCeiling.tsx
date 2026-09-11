import { formatNumber } from '@/lib/numerals'

/**
 * What the server's list ceiling kept back, said on the page rather than left to be guessed.
 *
 * Every client-facing list is capped at two hundred rows, newest first. The direction is the safe one
 * — the rows a client most likely wants are the ones they get — but the cap used to be invisible, and
 * an invisible ceiling is indistinguishable from the whole truth. A client with two hundred and forty
 * invoices saw two hundred and had every reason to believe that was all of them.
 *
 * Renders NOTHING when `withheld` is zero or unknown. Zero is a real answer and must not put «older
 * ones are not shown» on every short list; `undefined` is a response cached from before the server
 * sent the counts, and a page that cannot tell should say nothing rather than something false.
 */
export function PortalListCeiling({ withheld, total, ar }: { withheld?: number; total?: number; ar: boolean }) {
  if (!withheld || withheld <= 0 || total === undefined) {
    return null
  }

  const shown = total - withheld

  return (
    <p
      data-testid="portal-list-ceiling"
      className="mt-3 rounded-xl border border-border bg-surface-secondary px-3 py-2 text-center text-xs text-text-secondary"
    >
      {ar
        ? `هذه أحدث ${formatNumber(shown)} من ${formatNumber(total)}. للوصول إلى الأقدم تواصل مع فريقك.`
        : `These are the latest ${formatNumber(shown)} of ${formatNumber(total)}. For older ones, ask your team.`}
    </p>
  )
}
