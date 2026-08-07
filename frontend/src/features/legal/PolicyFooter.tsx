import { Link } from 'react-router-dom'
import { policyLinks, type PolicyContext } from './policyLinks'
import { useUi } from '@/stores/ui'

/**
 * The policy links for wherever this is rendered — POLICY-PLACEMENT-001.
 *
 * Two shapes, one source. `PortalFooter` is the quiet line under every signed-in shell;
 * `PolicyNote` is the inline row a settings page puts beside the thing the policy is about.
 *
 * Both link to the SAME public pages the marketing site links to. A signed-in copy of a legal text
 * would be a second thing to keep current, and the one nobody updates is the one a regulator reads.
 * The pages render outside the portal shell, so they open in a new tab rather than dropping an
 * operator out of the workspace they were working in.
 */

/**
 * Under every portal shell — three links, and never more.
 *
 * Privacy, terms and security apply wherever you are standing. Everything else is offered where its
 * question arises: retention beside the account, refunds beside the subscription, the OAuth
 * disclosure beside the platform being connected. A footer that listed all thirteen would be the
 * homepage footer again, one level down — which is the defect this replaces.
 */
export function PortalFooter() {
  const locale = useUi((s) => s.locale)
  const links = policyLinks('portal', locale)
  const year = new Date().getFullYear()

  return (
    <footer
      data-testid="portal-footer"
      className="mt-8 border-t border-border pt-4 text-xs text-text-muted"
    >
      <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <span dir="ltr">© {year} CampaignsHub</span>
        <nav className="flex flex-wrap items-center gap-x-4 gap-y-1" aria-label={locale === 'ar' ? 'السياسات' : 'Policies'}>
          {links.map((l) => (
            <Link
              key={l.key}
              to={l.to}
              data-testid={`policy-link-${l.key}`}
              // The policy pages live outside the portal shell; opening in place would drop an
              // operator out of the workspace they were in the middle of using.
              target="_blank"
              rel="noopener noreferrer"
              className="hover:text-text-primary hover:underline"
            >
              {l.label}
            </Link>
          ))}
        </nav>
      </div>
    </footer>
  )
}

/**
 * The policies that belong to THIS page, beside the thing they are about.
 *
 * «الاشتراكات والاسترداد» under the subscription, «الإفصاح عن OAuth» under the platform you are
 * connecting. Read where the question arises, which is the only place a policy actually gets read.
 */
export function PolicyNote({ context, className = '' }: { context: PolicyContext; className?: string }) {
  const locale = useUi((s) => s.locale)
  const links = policyLinks(context, locale)

  return (
    <div
      data-testid={`policy-note-${context}`}
      className={`flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl border border-border bg-surface-secondary px-3 py-2 text-xs text-text-secondary ${className}`}
    >
      <span className="font-semibold text-text-muted">{locale === 'ar' ? 'السياسات ذات الصلة:' : 'Related policies:'}</span>
      {links.map((l) => (
        <Link
          key={l.key}
          to={l.to}
          data-testid={`policy-link-${l.key}`}
          target="_blank"
          rel="noopener noreferrer"
          className="underline underline-offset-2 hover:text-text-primary"
        >
          {l.label}
        </Link>
      ))}
    </div>
  )
}
