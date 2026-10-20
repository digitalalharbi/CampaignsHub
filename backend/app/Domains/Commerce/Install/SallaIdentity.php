<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Install;

use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\Support\PlatformHttp;
use Throwable;

/**
 * SALLA-ONBOARD-001 — who authorised the app, as Salla says it.
 *
 * `GET {api}/oauth2/user/info` describes the user who authorised the installation: id, name, email, mobile and
 * a role (`team` for a store employee rather than the owner). Salla, not this product, vouches for the email —
 * the merchant proved it to Salla — which is what lets a store-initiated install create an account without a
 * verification round trip. Nothing here is trusted beyond that: an absent or malformed answer is «unknown», and
 * unknown never provisions anything.
 */
final class SallaIdentity
{
    /**
     * @return array{id: string, email: string, name: string, mobile: ?string, is_owner: bool}|null
     */
    public function fetch(OAuthTokens $tokens): ?array
    {
        try {
            $response = PlatformHttp::client('salla')
                ->withToken($tokens->accessToken)
                ->timeout(15)
                ->get(rtrim((string) config('commerce_platforms.platforms.salla.api_base'), '/').'/oauth2/user/info');
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $data = (array) ($response->json('data') ?? []);
        $email = is_string($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : '';

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return [
            'id' => (string) ($data['id'] ?? ''),
            'email' => $email,
            'name' => is_string($data['name'] ?? null) && trim($data['name']) !== '' ? trim($data['name']) : $email,
            'mobile' => is_string($data['mobile'] ?? null) && trim($data['mobile']) !== '' ? trim($data['mobile']) : null,
            // `team` is an employee of the store; anything else (owner, or no role stated) is the merchant.
            'is_owner' => strtolower((string) ($data['role'] ?? 'owner')) !== 'team',
        ];
    }
}
