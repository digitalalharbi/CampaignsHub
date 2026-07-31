<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Actions\OAuthOutcome;
use App\Domains\Identity\Actions\ResolveOAuthIdentity;
use App\Domains\Identity\Services\OAuthFlow;
use App\Domains\Identity\Services\OAuthProviderRegistry;
use App\Domains\Identity\Support\AccountSuspension;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Services\PortalResolver;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
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
 *   - `providers` is PUBLIC and says which providers are usable. The sign-in page renders every one
 *     of them either way, so a provider that has not been set up appears as unavailable with the
 *     reason on it rather than as a button that redirects into an error nobody can explain.
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
        private readonly ResolveOAuthIdentity $identities,
        private readonly PortalResolver $portals,
    ) {}

    /** GET /auth/oauth/providers — public; no secrets, only whether each provider can be used. */
    public function providers(): JsonResponse
    {
        return ApiResponse::success(['providers' => $this->registry->forClient()], 'Sign-in providers.');
    }

    /** POST /auth/oauth/{provider}/start */
    public function start(Request $request, string $provider): JsonResponse
    {
        if (! $this->registry->isConfigured($provider)) {
            // 503, not 400: the request is fine, the capability is not switched on. Saying
            // "bad request" would send an integrator looking for a mistake in their own call.
            return ApiResponse::error(
                'This sign-in method is awaiting provider credentials.',
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

    /** GET|POST /auth/oauth/{provider}/callback */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        try {
            // The provider's own refusal (the visitor pressed Cancel) arrives as `error`.
            if ($request->filled('error')) {
                return $this->back('cancelled');
            }

            $pending = $this->flow->consume($request, $provider, (string) $request->input('state'));
            $profile = $this->exchange($provider, (string) $request->input('code'), $pending['verifier'], $pending['nonce']);

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

            $target = is_string($pending['redirect']) && str_starts_with($pending['redirect'], '/')
                ? $pending['redirect']
                : $this->portals->landingPathFor($user, $requested);

            return redirect()->to($target);
        } catch (OAuthOutcome $outcome) {
            return $this->back($outcome->reason, ['message' => $outcome->getMessage()]);
        } catch (Throwable) {
            // Nothing from the provider or the exchange is echoed back — an error string reflected
            // into the SPA is an injection surface, and the visitor cannot act on it anyway.
            return $this->back('failed');
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
        $claims = $this->flow->claimsFromIdToken($idToken);

        // The nonce ties this token to the request THIS browser started. A token replayed from an
        // earlier flow carries the earlier nonce and fails here.
        if (! hash_equals((string) ($claims['nonce'] ?? ''), $nonce)) {
            throw new RuntimeException('The identity token did not match this sign-in.');
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
        return redirect()->to('/login?'.http_build_query(['oauth' => $reason] + $extra));
    }
}
