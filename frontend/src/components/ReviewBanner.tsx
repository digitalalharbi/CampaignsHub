import { ShieldAlert } from 'lucide-react'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'

/**
 * SALLA-REVIEW-001 — «Salla Review», on every page of the review workspace.
 *
 * Shown only when the account payload says the workspace is the app-store review one, so no ordinary
 * customer ever sees it. It states what the reviewer is in (a restricted demo workspace) and when it ends,
 * in the reader's language, and it is a plain status line: not a modal, not an overlay, nothing that
 * covers content or blocks input.
 */
export function ReviewBanner() {
  const review = useAuth((s) => s.user?.account?.review)
  const ar = useUi((s) => s.locale) === 'ar'
  if (!review) return null

  const until = review.expires_at ? new Date(review.expires_at).toISOString().slice(0, 10) : null

  return (
    <div
      role="status"
      data-testid="review-banner"
      className="flex items-center justify-center gap-2 border-b border-warning/30 bg-warning/10 px-4 py-1.5 text-xs font-semibold text-text-primary"
    >
      <ShieldAlert size={14} aria-hidden className="shrink-0 text-warning" />
      <span>{review.label}</span>
      <span className="font-normal text-text-secondary">
        {ar ? '· مساحة عرض مقيّدة ببيانات تجريبية' : '· restricted demo workspace'}
        {until && (ar ? ` · تنتهي ${until}` : ` · ends ${until}`)}
      </span>
    </div>
  )
}
