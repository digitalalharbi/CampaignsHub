import { ensureCsrfCookie, getData, postData } from '@/lib/api/client'
import type { AuthUser } from '@/lib/api/types'

export interface LoginInput {
  email: string
  password: string
  remember?: boolean
  /**
   * The portal chosen on the sign-in page (LOGIN-003).
   *
   * A preference the SERVER checks, never a grant. Naming a portal here cannot open one; the only
   * thing it can do is get the sign-in refused — before any session exists — when the account does
   * not hold it, which is what makes a wrong-portal choice behave like a wrong password instead of
   * like a page you reach and then cannot use.
   */
  portal?: string | null
}

export interface RegisterInput {
  tenant_name: string
  name: string
  email: string
  password: string
  password_confirmation: string
  /** The path chosen on the public site — stored on the tenant so onboarding never re-asks. */
  account_type?: string
  service?: 'paid_media' | 'influencer_marketing' | 'combined'
}

interface UserEnvelope {
  user: AuthUser
}

export async function login(input: LoginInput): Promise<AuthUser> {
  await ensureCsrfCookie()
  const { user } = await postData<UserEnvelope>('/auth/login', input)
  return user
}

export async function register(input: RegisterInput): Promise<AuthUser> {
  await ensureCsrfCookie()
  const { user } = await postData<UserEnvelope>('/auth/register', input)
  return user
}

export async function logout(): Promise<void> {
  await postData<null>('/auth/logout')
}

/**
 * Probe the session on app load. Returns null ONLY when the server says the visitor is a guest.
 *
 * This used to swallow every error into null, which made "the request failed" indistinguishable from
 * "you are not signed in". A slow backend, a dropped connection or a 5xx on reload therefore logged
 * the user out of the interface while their cookie was still perfectly valid — and, because the
 * bounce to `/login` replaces the current history entry, pressing Back afterwards landed on a login
 * page instead of the page they had been on. Reproduced on Firefox and WebKit; Chromium happened to
 * win the race and hid it.
 *
 * A 419 (expired CSRF) is not a logout either: it means the token needs re-priming, and the retry
 * below does exactly that.
 */
export async function fetchCurrentUser(): Promise<AuthUser | null> {
  try {
    const { user } = await getData<UserEnvelope>('/auth/me')
    return user
  } catch (error) {
    if (isDefinitelyGuest(error)) {
      return null
    }

    // One retry, then give up. Re-priming CSRF first covers the 419 case at no cost to the others.
    // A second failure still ends in `null` — there is no "we could not tell" state for the router
    // to render — but one retry removes the single-hiccup case that made reloads unreliable.
    try {
      await ensureCsrfCookie()
      const { user } = await getData<UserEnvelope>('/auth/me')
      return user
    } catch {
      return null
    }
  }
}

/** Only the server's own "not authenticated" counts. 401 and 403 are answers; everything else is noise. */
function isDefinitelyGuest(error: unknown): boolean {
  const status = (error as { status?: number } | null)?.status

  return status === 401 || status === 403
}

/** Request a password-reset link. Always resolves generically (no account enumeration). */
export async function requestPasswordReset(input: { email: string }): Promise<void> {
  await ensureCsrfCookie()
  await postData('/auth/forgot-password', input)
}

/** A social sign-in provider as the sign-in page needs to render it (LOGIN-004). */
export interface OAuthProvider {
  provider: string
  label: { en: string; ar: string }
  /** `live` or `awaiting_credentials` — reported, never guessed at in the browser. */
  status: string
  /** False when the provider has no credentials configured: render it, but inert. */
  available: boolean
}

export async function fetchOAuthProviders(): Promise<OAuthProvider[]> {
  const { providers } = await getData<{ providers: OAuthProvider[] }>('/auth/oauth/providers')
  return providers
}

/**
 * Begin an Authorization Code + PKCE flow. The server mints the verifier, `state` and `nonce` and
 * keeps them in the session — none of the three passes through here, which is what makes them
 * worth having.
 */
export async function startOAuth(
  provider: string,
  context: { portal?: string | null; redirect?: string | null },
): Promise<{ authorize_url: string }> {
  await ensureCsrfCookie()
  return postData<{ authorize_url: string }>(`/auth/oauth/${provider}/start`, context)
}
