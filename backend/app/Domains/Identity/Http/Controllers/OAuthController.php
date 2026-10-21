<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Actions\OAuthOutcome;
use App\Domains\Identity\Actions\ResolveOAuthIdentity;
use App\Domains\Identity\Models\OAuthIdentity;
use App\Domains\Identity\Services\GoogleIdTokenVerifier;
use App\Domains\Identity\Services\OAuthFlow;
use App\Domains\Identity\Services\OAuthProviderRegistry;
use App\Domains\Identity\Support\AccountSuspension;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Services\PortalResolver;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\Frontend;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Social sign-in: Authorization Code + PKCE (LOGIN-004).
 *
 * Three endpoints and one honest answer:
 *
 *   - `providers` is PUBLIC and says which providers are usable. The sign-in page advertises only
 *     usable providers, so an installation without credentials has no dead control that redirects
 *     into an error nobody can explain.
 *   - `start` mints the PKCE verifier, `state` and `nonce`, and hands back the authorize URL.
 *   - `callback` is where the provider returns. It verifies `state`, exchanges the code with the
 *     verifier, checks the `nonce`, and then applies the portal rule that the password path applies:
 *     an account that does not hold the chosen portal is REFUSED, and no session is created.
 *
 * The callback redirects into the SPA with a short query flag rather than returning JSON, because
 * the browser arrives here by a top-level navigation from the provider, not by fetch.
 */
final class OAuthController extends Controller
{
    public function __construct(
        private readonly OAuthProviderRegistry $registry,
        private readonly OAuthFlow $flow,
        private readonly GoogleIdTokenVerifier $googleTokens,
        private readonly ResolveOAuthIdentity $identities,
        private readonly PortalResolver $portals,
    ) {}

    /** GET /auth/oauth/providers — public; no secrets, only whether each provider can be used. */
    public function providers(): JsonResponse
    {
        return ApiResponse::success(['providers' => $this->registry->forClient()], __('accounts.oauth_providers'));
    }

    /** POST /auth/oauth/{provider}/start */
    public function start(Request $request, string $provider): JsonResponse
    {
        if (! $this->registry->isConfigured($provider)) {
            // 503, not 400: the request is fine, the capability is not switched on. Saying
            // "bad request" would send an integrator looking for a mistake in their own call.
            return ApiResponse::error(
                __('accounts.oauth_awaiting_credentials'),
                null,
                ['provider' => $provider, 'status' => OAuthProviderRegistry::AWAITING_CREDENTIALS],
                503,
            );
        }

        $data = $request->validate([
            'portal' => ['sometimes', 'nullable', 'string', 'max:32'],
            'redirect' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ]);

        $begun = $this->flow->begin($request, $provider, $data['portal'] ?? null, $data['redirect'] ?? null);

        return ApiResponse::success(['authorize_url' => $begun['url']], 'Sign-in started.');
    }

    /** GET /auth/oauth/identities — the signed-in person's provider links. */
    public function identities(Request $request): JsonResponse
    {
        $linked = $request->user()->oauthIdentities()->get()->keyBy('provider');
        $providers = [];

        foreach ($this->registry->forClient() as $provider) {
            $providerName = $provider['provider'] ?? null;
            if (! is_string($providerName)) {
                continue;
            }

            $identity = $linked->get($providerName);
            if (! $identity instanceof OAuthIdentity) {
                $identity = null;
            }

            $providers[] = $provider + [
                'linked' => $identity !== null,
                'email' => $identity?->email,
                'linked_at' => $identity?->linked_at?->toIso8601String(),
            ];
        }

        return ApiResponse::success(['providers' => $providers]);
    }

    /** POST /auth/oauth/{provider}/link — begin a flow bound to this authenticated user. */
    public function linkStart(Request $request, string $provider): JsonResponse
    {
        if (! $this->registry->isConfigured($provider)) {
            return ApiResponse::error('This provider is awaiting credentials.', null, ['provider' => $provider], 503);
        }

        $begun = $this->flow->begin($request, $provider, null, '/account/security', 'link', $request->user()->getKey());

        return ApiResponse::success(['authorize_url' => $begun['url']], 'Account linking started.');
    }

    /** GET|POST /auth/oauth/{provider}/callback */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $pending = null;

        try {
            $pending = $this->flow->consume($request, $provider, (string) $request->input('state'));

            // Consume state even on refusal so it remains single-use and so linking returns to
            // account settings rather than dropping an authenticated person on the login page.
            if ($request->filled('error')) {
                return $pending['intent'] === 'link'
                    ? $this->linkBack($provider, 'cancelled')
                    : $this->back('cancelled');
            }

            $profile = $this->exchange($provider, (string) $request->input('code'), $pending['verifier'], $pending['nonce']);

            if ($pending['intent'] === 'link') {
                $user = Auth::guard('web')->user();
                if ($user === null || ! hash_equals((string) $pending['user_id'], (string) $user->getKey())) {
                    throw new RuntimeException('The linking session is no longer authenticated.');
                }

                $this->identities->link($user, $provider, $profile);

                return $this->linkBack($provider, 'connected');
            }

            ['user' => $user] = $this->identities->execute($provider, $profile);

            // The same two gates the password path applies, in the same order.
            if ($user->disabled_at !== null || (! $user->is_platform_admin && AccountSuspension::everyWorkspaceSuspendedFor($user))) {
                return $this->back('unavailable');
            }

            $requested = Portal::tryFrom((string) ($pending['portal'] ?? ''));
            if ($requested !== null && ! $this->portals->holds($user, $requested)) {
                // Refused BEFORE a session exists, exactly as in AuthController (LOGIN-003).
                return $this->back('portal_mismatch', [
                    'portal' => $requested->value,
                    'destination' => $this->portals->landingPathFor($user),
                ]);
            }

            Auth::guard('web')->login($user, true);
            $request->session()->regenerate();

            $target = is_string($pending['redirect']) && $this->isSafeFrontendPath($pending['redirect'])
                ? $pending['redirect']
                : $this->portals->landingPathFor($user, $requested);

            return redirect()->away(Frontend::url($target));
        } catch (OAuthOutcome $outcome) {
            return $this->back($outcome->reason, ['message' => $outcome->getMessage()]);
        } catch (Throwable) {
            // Nothing from the provider or the exchange is echoed back — an error string reflected
            // into the SPA is an injection surface, and the visitor cannot act on it anyway.
            return is_array($pending) && ($pending['intent'] ?? null) === 'link'
                ? $this->linkBack($provider, 'failed')
                : $this->back('failed');
        }
    }

    /**
     * Redeem the code and read the identity out of the ID token.
     *
     * @return array{sub: string, email: ?string, email_verified: bool, name: ?string, avatar: ?string}
     */
    private function exchange(string $provider, string $code, string $verifier, string $nonce): array
    {
        $response = Http::asForm()->post((string) $this->registry->definition($provider)['token'], [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->flow->redirectUri($provider),
            'client_id' => (string) config("services.{$provider}.client_id"),
            'client_secret' => (string) config("services.{$provider}.client_secret"),
            'code_verifier' => $verifier,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('The provider refused the sign-in.');
        }

        $idToken = (string) $response->json('id_token');
        if ($provider === 'google') {
            $claims = $this->googleTokens->verify($idToken, (string) config('services.google.client_id'), $nonce);
        } else {
            $claims = $this->flow->claimsFromIdToken($idToken);

            // Google verifies the nonce inside its provider-specific cryptographic verifier. Keep
            // the same replay protection on providers that still use the legacy exchange path.
            if (! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])) {
                throw new RuntimeException('The identity token did not match this sign-in.');
            }
        }

        return [
            'sub' => (string) ($claims['sub'] ?? ''),
            'email' => isset($claims['email']) ? (string) $claims['email'] : null,
            // Providers disagree on the type — Google sends a boolean, Apple a string.
            'email_verified' => filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'name' => isset($claims['name']) ? (string) $claims['name'] : null,
            'avatar' => isset($claims['picture']) ? (string) $claims['picture'] : null,
        ];
    }

    /** Back to the sign-in page with a flag it can turn into a sentence. */
    private function back(string $reason, array $extra = []): RedirectResponse
    {
        return redirect()->away(Frontend::url('/login?'.http_build_query(['oauth' => $reason] + $extra)));
    }

    private function linkBack(string $provider, string $outcome): RedirectResponse
    {
        return redirect()->away(Frontend::url('/account/security?'.http_build_query([
            'oauth_link' => $outcome,
            'provider' => $provider,
        ])));
    }

    private function isSafeFrontendPath(string $path): bool
    {
        return str_starts_with($path, '/')
            && ! str_starts_with($path, '//')
            && ! str_contains($path, '\\')
            && ! preg_match('/[\r\n]/', $path);
    }
}
