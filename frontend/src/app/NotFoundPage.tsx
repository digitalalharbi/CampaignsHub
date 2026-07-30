import { Link, useLocation, useRouteError } from 'react-router-dom'
import { Compass } from 'lucide-react'
import { useUi } from '@/stores/ui'

/**
 * What an unmatched URL renders.
 *
 * Found by driving the app live: this router had no catch-all and no error element, so a mistyped
 * address, a stale bookmark or a link to a route that has since moved rendered React Router's
 * default boundary — a completely blank white page with the error only in the console. Nothing on
 * screen said the address was wrong, so it reads as the product being broken.
 *
 * Doubles as the router's `errorElement` for the same reason: a render error deeper in the tree
 * currently produces the same blank page, and a page that admits something went wrong and offers a
 * way back is strictly better than one that says nothing at all.
 *
 * It does NOT guess where the visitor meant to go. Sending someone to a portal they may not hold
 * would turn a wrong address into a 403, which is a worse answer than this one.
 */
export function NotFoundPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const location = useLocation()
  // Present when React Router renders this as an errorElement rather than as a route match.
  const error = useRouteError()

  const isError = error !== undefined && error !== null

  return (
    <div className="flex min-h-screen items-center justify-center bg-background px-5">
      <div data-testid="not-found" className="w-full max-w-md rounded-2xl border border-border bg-surface p-8 text-center">
        <Compass className="mx-auto text-text-muted" size={30} aria-hidden />
        <h1 className="mt-4 font-heading text-xl font-extrabold text-text-primary">
          {isError
            ? ar ? 'حدث خطأ في عرض هذه الصفحة' : 'Something went wrong on this page'
            : ar ? 'الصفحة غير موجودة' : 'That page does not exist'}
        </h1>
        <p className="mt-2 text-sm text-text-secondary">
          {isError
            ? ar
              ? 'جرّب تحديث الصفحة. إن تكرّر الأمر فأبلغ فريق الدعم بالعنوان أدناه.'
              : 'Try reloading. If it keeps happening, report the address below to support.'
            : ar
              ? 'ربما تغيّر العنوان أو كُتب خطأً.'
              : 'The address may have changed, or been typed incorrectly.'}
        </p>
        {/* The address itself, isolated LTR so the path reads in the order it was typed. */}
        <p dir="ltr" className="mt-3 truncate rounded-lg bg-surface-secondary px-3 py-2 font-mono text-[12.5px] text-text-muted">
          {location.pathname}
        </p>
        <Link
          to="/"
          className="mt-5 inline-block rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-700"
        >
          {ar ? 'العودة إلى الصفحة الرئيسية' : 'Back to the home page'}
        </Link>
      </div>
    </div>
  )
}
