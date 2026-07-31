<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Authorization Code + PKCE, with `state` and `nonce` (LOGIN-004).
 *
 * Three separate secrets, each answering a different question, and none of them substitutes for
 * another:
 *
 *   - **PKCE verifier** — proves that the party redeeming the code is the party that requested it.
 *     Without it an intercepted code can be exchanged by anyone holding the client id.
 *   - **state** — proves the callback belongs to a flow THIS browser started. This is the CSRF
 *     defence; without it an attacker can complete a login into their own account in your browser.
 *   - **nonce** — proves the ID token was minted for this particular request, not replayed from an
 *     older one.
 *
 * All three live in the session and are single-use: `consume()` deletes them, so a callback replayed
 * against the same session fails the second time rather than succeeding twice.
 */
final class OAuthFlow
{
    private const SESSION_KEY = 'oauth.flow';

    public function __construct(private readonly OAuthProviderRegistry $registry) {}

    /**
     * Begin a flow and return the URL to send the browser to.
     *
     * @return array{url: string, state: string}
     */
    public function begin(Request $request, string $provider, ?string $portal, ?string $redirect): array
    {
        $definition = $this->registry->definition($provider);

        if ($definition === null || ! $this->registry->isConfigured($provider)) {
            throw new RuntimeException("The {$provider} sign-in is not configured.");
        }

        // 43–128 chars of unreserved characters, per RFC 7636. `Str::random` gives us [A-Za-z0-9].
        $verifier = Str::random(96);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state = Str::random(40);
        $nonce = Str::random(40);

        $request->session()->put(self::SESSION_KEY, [
            'provider' => $provider,
            'verifier' => $verifier,
            'state' => $state,
            'nonce' => $nonce,
            // Carried through the round trip so the portal choice and the intended page survive it —
            // neither is trusted for authorisation, exactly as on the password path.
            'portal' => $portal,
            'redirect' => $redirect,
        ]);

        $query = [
            'client_id' => (string) config("services.{$provider}.client_id"),
            'redirect_uri' => $this->redirectUri($provider),
            'response_type' => 'code',
            'scope' => $definition['scopes'],
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];

        if (isset($definition['response_mode'])) {
            $query['response_mode'] = $definition['response_mode'];
        }

        return [
            'url' => $definition['authorize'].'?'.http_build_query($query),
            'state' => $state,
        ];
    }

    /**
     * Validate and consume the pending flow.
     *
     * Refuses a `state` that does not match the stored one, which is the case that matters: a
     * callback nobody in this browser asked for.
     *
     * @return array{provider: string, verifier: string, nonce: string, portal: ?string, redirect: ?string}
     */
    public function consume(Request $request, string $provider, string $state): array
    {
        $pending = $request->session()->pull(self::SESSION_KEY);

        if (! is_array($pending)) {
            throw new RuntimeException('No sign-in is in progress. Please start again.');
        }
        if (! hash_equals((string) ($pending['state'] ?? ''), $state)) {
            throw new RuntimeException('This sign-in could not be verified. Please start again.');
        }
        if (($pending['provider'] ?? null) !== $provider) {
            throw new RuntimeException('This sign-in could not be verified. Please start again.');
        }

        return [
            'provider' => $provider,
            'verifier' => (string) $pending['verifier'],
            'nonce' => (string) $pending['nonce'],
            'portal' => $pending['portal'] ?? null,
            'redirect' => $pending['redirect'] ?? null,
        ];
    }

    /** Where the provider sends the browser back. Configurable, because it must match the console. */
    public function redirectUri(string $provider): string
    {
        $configured = config("services.{$provider}.redirect");

        return is_string($configured) && $configured !== ''
            ? $configured
            : url("/api/v1/auth/oauth/{$provider}/callback");
    }

    /**
     * Read the claims out of an ID token WITHOUT trusting them yet.
     *
     * The signature is not checked here, and that is why this is private to the exchange step: the
     * token arrives over a direct, authenticated, TLS back-channel call to the provider's token
     * endpoint, which is what establishes its provenance. A token that arrived any other way must
     * not be read with this.
     *
     * @return array<string, mixed>
     */
    public function claimsFromIdToken(string $idToken): array
    {
        $parts = explode('.', $idToken);

        if (count($parts) !== 3) {
            throw new RuntimeException('The provider returned an unreadable identity token.');
        }

        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);

        if (! is_array($payload)) {
            throw new RuntimeException('The provider returned an unreadable identity token.');
        }

        return $payload;
    }
}
