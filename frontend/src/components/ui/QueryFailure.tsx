import { AlertTriangle, Lock, LogIn, RefreshCw, SearchX, Wifi } from 'lucide-react'
import { Link } from 'react-router-dom'
import { toApiError } from '@/lib/api/client'

/**
 * AGENCY-PERMS — a failed load says WHICH failure it was.
 *
 * The defect this replaces: `TasksPage`, `ThreadsPage` and `TabMessages` each rendered one sentence
 * — «تعذّر تحميل المهام» — for a refusal, an expired session, a missing record and a dead server
 * alike. Three of those four are not failures at all, and the one that is is the only one a Retry
 * button helps. Reported as a bug three times over by an operator who was simply signed in as the
 * platform admin, who holds no tenant: the system was behaving correctly and describing itself as
 * broken.
 *
 * `toApiError` already carries the status and the server's own (translated) sentence. All that was
 * missing was a component that acts on them, so every surface answers the same four questions the
 * same way.
 */

/**
 * What kind of failure this was — chosen so each arm implies a DIFFERENT action.
 *
 * `permission` and `session` deliberately do not offer Retry: repeating a request that was refused
 * produces the same refusal, and a button that cannot work is worse than no button.
 */
export type FailureKind = 'permission' | 'session' | 'not_found' | 'retryable'

export function failureKind(error: unknown): FailureKind {
  const status = toApiError(error).status
  if (status === 403) return 'permission'
  if (status === 401 || status === 419) return 'session'
  if (status === 404) return 'not_found'
  return 'retryable'
}

const COPY = {
  ar: {
    permission: 'لا تملك صلاحية الاطلاع على هذا القسم',
    permission_hint: 'الوصول محدود بصلاحيات عضويتك. اطلب توسيعها ممن يدير الصلاحيات في مساحة العمل.',
    session: 'انتهت جلستك',
    session_hint: 'سجّل الدخول مرة أخرى للمتابعة.',
    sign_in: 'تسجيل الدخول',
    not_found: 'العنصر المطلوب غير موجود',
    not_found_hint: 'ربما حُذف أو أنه خارج مساحة العمل الحالية.',
    retry: 'إعادة المحاولة',
    no_context_hint: 'اختر عميلًا أو مشروعًا لعرض بياناته.',
  },
  en: {
    permission: 'You do not have access to this section',
    permission_hint: 'Access follows your membership’s permissions. Ask whoever manages permissions in this workspace to widen them.',
    session: 'Your session has ended',
    session_hint: 'Sign in again to continue.',
    sign_in: 'Sign in',
    not_found: 'That could not be found',
    not_found_hint: 'It may have been removed, or it belongs to a different workspace.',
    retry: 'Try again',
    no_context_hint: 'Pick a client or a project to see its data.',
  },
} as const

function Panel({
  icon, title, body, tone, action, testId,
}: {
  icon: React.ReactNode
  title: string
  body: string
  tone: 'neutral' | 'danger'
  action?: React.ReactNode
  testId: string
}) {
  return (
    <div
      data-testid={testId}
      className={`flex flex-col items-center justify-center gap-2 rounded-2xl border p-8 text-center ${
        tone === 'danger' ? 'border-danger/30 bg-danger/5' : 'border-dashed border-border bg-surface'
      }`}
    >
      <div className={tone === 'danger' ? 'text-danger' : 'text-text-muted'}>{icon}</div>
      <p className={`text-sm font-bold ${tone === 'danger' ? 'text-danger' : 'text-text-primary'}`}>{title}</p>
      <p className="max-w-sm text-xs text-text-secondary">{body}</p>
      {action ? <div className="mt-1">{action}</div> : null}
    </div>
  )
}

/**
 * Render a load failure honestly.
 *
 * `fallbackTitle` is the surface's own sentence («تعذّر تحميل المهام») and is used ONLY on the
 * retryable arm — the one case where the surface really did fail to load. The refusal arms use the
 * server's own message when it sent one, because it knows which permission and which portal.
 */
export function QueryFailure({
  error, ar, onRetry, fallbackTitle, testId = 'query-failure',
}: {
  error: unknown
  ar: boolean
  onRetry?: () => void
  fallbackTitle: string
  testId?: string
}) {
  const c = ar ? COPY.ar : COPY.en
  const api = toApiError(error)
  const kind = failureKind(error)

  if (kind === 'permission') {
    return (
      <Panel
        testId={`${testId}-permission`}
        tone="neutral"
        icon={<Lock size={26} />}
        title={c.permission}
        // The server's sentence names the actual boundary (a portal that is off, a client outside
        // scope). Our own line is the fallback for a bare `abort(403)`.
        body={api.message || c.permission_hint}
      />
    )
  }

  if (kind === 'session') {
    return (
      <Panel
        testId={`${testId}-session`}
        tone="neutral"
        icon={<LogIn size={26} />}
        title={c.session}
        body={c.session_hint}
        action={
          <Link to="/login" className="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-brand-700">
            {c.sign_in}
          </Link>
        }
      />
    )
  }

  if (kind === 'not_found') {
    return (
      <Panel
        testId={`${testId}-not-found`}
        tone="neutral"
        icon={<SearchX size={26} />}
        title={c.not_found}
        body={c.not_found_hint}
      />
    )
  }

  return (
    <Panel
      testId={`${testId}-retryable`}
      tone="danger"
      icon={api.kind === 'offline' ? <Wifi size={26} /> : <AlertTriangle size={26} />}
      title={fallbackTitle}
      body={api.message}
      action={
        onRetry ? (
          <button
            onClick={onRetry}
            className="inline-flex items-center gap-1.5 rounded-lg border border-border bg-surface px-3 py-1.5 text-xs font-bold text-text-primary hover:border-brand-400"
          >
            <RefreshCw size={13} /> {c.retry}
          </button>
        ) : undefined
      }
    />
  )
}

/**
 * Nothing is selected yet — guidance, not an error (AGENCY-PERMS acceptance case 7).
 *
 * A page that needs a client or a project and has neither has not failed; it has not been told what
 * to show. Rendering it as a red error box sends somebody to support over a dropdown they have not
 * touched.
 */
export function NoContextGuidance({ ar, title, testId = 'no-context' }: { ar: boolean; title: string; testId?: string }) {
  const c = ar ? COPY.ar : COPY.en
  return (
    <Panel
      testId={testId}
      tone="neutral"
      icon={<SearchX size={26} />}
      title={title}
      body={c.no_context_hint}
    />
  )
}
