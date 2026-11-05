import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { Loader2, Lock, LogIn, RefreshCw, WifiOff } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

/**
 * WORKSPACE-SWITCH-001 — the four things that can stop the chooser, each said as what it is.
 *
 * One red box said «could not be loaded» for an expired session, a refusal and an outage alike, so nobody could tell
 * whether to retry, sign in or ask somebody. These panels are compact and neutral: none of these is the reader's
 * mistake, and an alarm colour for a server that needs a moment reads as if something of theirs were lost. Copy is
 * written here, by case, and never read from the response: a server sentence may be English on an Arabic page.
 */
function Panel({ testId, icon, title, body, children, status }: {
  testId: string
  icon: ReactNode
  title: string
  body: string
  children?: ReactNode
  /** The answer that led here, for anyone inspecting the page; a status number is no sentence for a reader. */
  status?: number
}) {
  return (
    <div
      role="alert"
      data-testid={testId}
      data-http-status={status}
      className="mt-6 flex items-start gap-3 rounded-2xl border border-border bg-surface p-4 sm:p-5"
    >
      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-surface-secondary text-text-secondary">{icon}</span>
      <div className="min-w-0 flex-1">
        <p className="text-[15px] font-bold text-text-primary">{title}</p>
        <p className="mt-1 text-sm leading-relaxed text-text-secondary">{body}</p>
        {children}
      </div>
    </div>
  )
}

export function SessionEndedPanel({ signInPath }: { signInPath: string }) {
  const ar = useUi((s) => s.locale) === 'ar'
  return (
    <Panel
      testId="workspace-session-ended"
      icon={<LogIn size={18} />}
      title={ar ? 'انتهت جلستك' : 'Your session has ended'}
      body={ar ? 'سجّل الدخول مرة أخرى لاختيار مساحة العمل. ننقلك الآن إلى صفحة الدخول.' : 'Sign in again to choose a workspace. Taking you to the sign-in page now.'}
    >
      <a href={signInPath} className="mt-2 inline-block text-sm font-semibold text-brand-600 hover:underline">
        {ar ? 'تسجيل الدخول' : 'Sign in'}
      </a>
    </Panel>
  )
}

export function NoPermissionPanel({ status }: { status?: number }) {
  const ar = useUi((s) => s.locale) === 'ar'
  return (
    <Panel
      testId="workspace-no-permission"
      status={status}
      icon={<Lock size={18} />}
      title={ar ? 'لا يملك هذا الحساب صلاحية الوصول الآن' : 'This account does not have access right now'}
      body={ar
        ? 'رفض النظام عرض مساحات العمل لهذا الحساب، وقد يكون الوصول قد انتهى أو أُوقف. إعادة المحاولة لن تغيّر ذلك: اطلب من مسؤول مساحتك مراجعة عضويتك، أو ادخل بحساب آخر.'
        : 'The system declined to show workspaces for this account; access may have ended or been suspended. Retrying will not change that: ask your workspace admin to review your membership, or sign in with another account.'}
    />
  )
}

/** Automatic attempts in progress: the reader is told that the page is already working on it. */
export function RetryingPanel({ attempt, total }: { attempt: number; total: number }) {
  const ar = useUi((s) => s.locale) === 'ar'
  return (
    <Panel
      testId="workspace-retrying"
      icon={<Loader2 size={18} className="animate-spin" />}
      title={ar ? 'نعيد المحاولة تلقائيًا' : 'Trying again automatically'}
      body={ar
        ? `لم يصلنا رد بعد. المحاولة ${attempt} من ${total}، ولا تحتاج إلى فعل أي شيء.`
        : `No answer yet. Attempt ${attempt} of ${total}; there is nothing you need to do.`}
    />
  )
}

export function UnavailablePanel({ error, onRetry, retrying }: { error: unknown; onRetry: () => void; retrying: boolean }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const failure = toApiError(error)
  const offline = failure.kind === 'offline'
  const requestId = typeof failure.meta?.request_id === 'string' ? failure.meta.request_id : null

  return (
    <Panel
      testId={offline ? 'workspace-offline' : 'workspace-unavailable'}
      status={failure.status}
      icon={offline ? <WifiOff size={18} /> : <RefreshCw size={18} />}
      title={offline
        ? (ar ? 'تعذّر الوصول إلى الخادم' : 'The server could not be reached')
        : (ar ? 'الخدمة لا تستجيب الآن' : 'The service is not responding right now')}
      body={offline
        ? (ar ? 'تحقّق من اتصالك بالإنترنت ثم حاول مرة أخرى. عضويتك وبياناتك لم تتغيّر.' : 'Check your connection and try again. Your memberships and data have not changed.')
        : (ar ? 'المشكلة من جهتنا وليست من جهتك. حاولنا عدة مرات دون نتيجة؛ حاول مجددًا بعد قليل. عضويتك وبياناتك لم تتغيّر.' : 'The problem is on our side, not yours. We tried several times without success; please try again shortly. Your memberships and data have not changed.')}
    >
      <Button variant="secondary" className="mt-3" loading={retrying} onClick={onRetry} data-testid="workspace-retry">
        {ar ? 'إعادة المحاولة' : 'Try again'}
      </Button>
      {requestId !== null && (
        <p className="mt-2 text-xs text-text-muted" data-testid="workspace-support-ref">
          {ar ? 'رمز المتابعة مع الدعم: ' : 'Reference for support: '}
          <span dir="ltr" className="font-mono">{requestId}</span>
        </p>
      )}
    </Panel>
  )
}

/** The boot probe got no answer: not a sign-out, and the sign-in page would say the wrong thing. */
export function SessionUnreachable() {
  const ar = useUi((s) => s.locale) === 'ar'
  return (
    <div className="min-h-screen bg-background px-5 py-10">
      <div className="mx-auto w-full max-w-2xl" data-testid="session-unreachable">
        <Panel
          testId="session-unreachable-panel"
          icon={<RefreshCw size={18} />}
          title={ar ? 'تعذّر التحقق من جلستك الآن' : 'Your session could not be checked right now'}
          body={ar ? 'لم يصلنا رد من الخادم، وهذا لا يعني أنك خرجت من حسابك. حاول مرة أخرى بعد قليل.' : 'The server did not answer. This does not mean you were signed out. Please try again shortly.'}
        >
          <div className="mt-3 flex flex-wrap items-center gap-4">
            <Button variant="secondary" onClick={() => window.location.reload()}>
              {ar ? 'إعادة المحاولة' : 'Try again'}
            </Button>
            <Link to="/" className="text-sm font-semibold text-text-secondary hover:text-text-primary">
              {ar ? 'الصفحة الرئيسية' : 'Home'}
            </Link>
          </div>
        </Panel>
      </div>
    </div>
  )
}
