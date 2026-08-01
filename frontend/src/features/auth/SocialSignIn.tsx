import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { fetchOAuthProviders, startOAuth } from './api'

/**
 * Google and Apple, shown honestly (LOGIN-004).
 *
 * Both are always rendered, so the page has the same shape in every environment. A provider with no
 * credentials configured is DISABLED and says why — an enabled button with nothing behind it sends
 * the visitor to an error page they cannot act on, and claims the platform supports something it
 * does not.
 */
export function SocialSignIn({ portalParam, redirect, ar }: {
  portalParam: string | null
  redirect: string | null
  ar: boolean
}) {
  const providers = useQuery({
    queryKey: ['oauth-providers'],
    queryFn: fetchOAuthProviders,
    staleTime: 5 * 60_000,
    retry: false,
  })
  const [failed, setFailed] = useState<string | null>(null)

  if (!providers.data?.length) return null

  const begin = async (provider: string) => {
    setFailed(null)
    try {
      const { authorize_url } = await startOAuth(provider, { portal: portalParam, redirect })
      // A full navigation, not a fetch: the provider's consent screen is a page, not an API.
      window.location.assign(authorize_url)
    } catch {
      setFailed(provider)
    }
  }

  return (
    <div data-testid="social-signin" className="mt-5">
      <div className="flex items-center gap-3 text-xs text-text-muted">
        <span className="h-px flex-1 bg-border" />
        <span>{ar ? 'أو تابع باستخدام' : 'or continue with'}</span>
        <span className="h-px flex-1 bg-border" />
      </div>

      <div className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
        {providers.data.map((p) => (
          <button
            key={p.provider}
            type="button"
            data-testid={`oauth-${p.provider}`}
            disabled={!p.available}
            onClick={() => begin(p.provider)}
            title={p.available ? undefined : (ar ? 'بانتظار بيانات الاعتماد' : 'Awaiting provider credentials')}
            className="flex h-11 w-full items-center justify-center gap-2 rounded-xl border border-border bg-surface px-3 text-sm font-semibold text-text-primary transition-colors hover:bg-surface-hover disabled:cursor-not-allowed disabled:opacity-55 disabled:hover:bg-surface"
          >
            <ProviderMark provider={p.provider} />
            <span className="truncate">{ar ? p.label.ar : p.label.en}</span>
          </button>
        ))}
      </div>

      {providers.data.some((p) => !p.available) && (
        <p data-testid="oauth-awaiting" className="mt-2 text-center text-[11.5px] text-text-muted">
          {ar
            ? 'بعض طرق الدخول بانتظار بيانات اعتماد المزود ولم تُفعّل بعد.'
            : 'Some sign-in methods are awaiting provider credentials and are not enabled yet.'}
        </p>
      )}

      {failed && (
        <p className="mt-2 text-center text-[11.5px] text-danger">
          {ar ? 'تعذّر بدء تسجيل الدخول عبر هذا المزود.' : 'This provider’s sign-in could not be started.'}
        </p>
      )}
    </div>
  )
}

/** Provider marks as inline SVG — no remote asset, so nothing here can fail to load. */
function ProviderMark({ provider }: { provider: string }) {
  if (provider === 'google') {
    return (
      <svg width="17" height="17" viewBox="0 0 24 24" aria-hidden="true">
        <path fill="#4285F4" d="M23 12.3c0-.8-.1-1.6-.2-2.3H12v4.5h6.2a5.3 5.3 0 0 1-2.3 3.5v2.9h3.7c2.2-2 3.4-5 3.4-8.6Z" />
        <path fill="#34A853" d="M12 24c3.1 0 5.7-1 7.6-2.8l-3.7-2.9c-1 .7-2.3 1.1-3.9 1.1-3 0-5.5-2-6.4-4.7H1.8v3C3.7 21.4 7.6 24 12 24Z" />
        <path fill="#FBBC05" d="M5.6 14.7a7.2 7.2 0 0 1 0-4.6v-3H1.8a12 12 0 0 0 0 10.6l3.8-3Z" />
        <path fill="#EA4335" d="M12 4.8c1.7 0 3.2.6 4.4 1.7l3.2-3.2C17.7 1.5 15.1.4 12 .4 7.6.4 3.7 3 1.8 6.8l3.8 3c.9-2.7 3.4-4.7 6.4-4.7Z" />
      </svg>
    )
  }
  return (
    <svg width="17" height="17" viewBox="0 0 24 24" aria-hidden="true" fill="currentColor">
      <path d="M17.05 12.6c0-2.2 1.8-3.3 1.9-3.3-1-1.5-2.6-1.7-3.2-1.7-1.4-.1-2.7.8-3.4.8-.7 0-1.8-.8-2.9-.8-1.5 0-2.9.9-3.6 2.2-1.6 2.7-.4 6.7 1.1 8.9.7 1.1 1.6 2.3 2.7 2.2 1.1 0 1.5-.7 2.8-.7s1.7.7 2.9.7c1.2 0 1.9-1.1 2.6-2.2.8-1.2 1.2-2.4 1.2-2.5 0 0-2.2-.9-2.1-3.6ZM14.9 5.3c.6-.7 1-1.7.9-2.7-.9 0-2 .6-2.6 1.3-.6.6-1.1 1.7-.9 2.6 1 .1 2-.5 2.6-1.2Z" />
    </svg>
  )
}
