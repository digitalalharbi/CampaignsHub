<?php

declare(strict_types=1);

namespace App\Domains\Integrations\MetaCandidate;

use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\Support\PlatformHttp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * META-MULTI-APP-001 — the three READ-ONLY Graph questions that prove which app a token belongs to and what it can see.
 *
 * Every method is a GET and writes nothing anywhere: no refresh, no extension, no cache, no database. They are shared by the audit command
 * (which must stay strictly read-only) and by the atomic reconnect (which asks them BEFORE it writes anything).
 *
 * A failure is reported as a short category word, never as the provider's text: a Graph URL for `debug_token` carries the app secret in
 * its query, and a transport error message names that URL.
 */
final class MetaGraphProbe
{
    public const FAIL_UNREACHABLE = 'meta_unreachable';

    public const FAIL_INVALID_TOKEN = 'token_invalid_or_expired';

    public const FAIL_REFUSED = 'meta_refused';

    private const MAX_PAGES = 50;

    /**
     * What Meta says about `$token`, asked with `$app`'s credentials. The `app_id` it returns is the proof of who issued the token.
     *
     * @return array{app_id: string|null, is_valid: bool}
     *
     * @throws RuntimeException message is one of the FAIL_* categories
     */
    public function debugToken(PlatformCredentials $app, string $token): array
    {
        $appId = $app->get('client_id');
        $secret = $app->get('client_secret');
        if ($appId === null || $secret === null) {
            throw new RuntimeException('no_credentials');
        }

        $response = $this->get($app, $app->apiBase().'/debug_token', [
            'input_token' => $token,
            'access_token' => $appId.'|'.$secret,
        ]);

        $data = $response->json('data');
        if (! is_array($data)) {
            throw new RuntimeException(self::FAIL_INVALID_TOKEN);
        }

        $id = $data['app_id'] ?? null;

        return ['app_id' => is_scalar($id) && (string) $id !== '' ? (string) $id : null, 'is_valid' => ($data['is_valid'] ?? false) === true];
    }

    /** @return list<string> permissions with status `granted` */
    public function grantedPermissions(PlatformCredentials $app, string $token): array
    {
        $response = $this->get($app, $app->apiBase().'/me/permissions', [], $token);
        $granted = [];
        foreach ((array) ($response->json('data') ?? []) as $row) {
            if (is_array($row) && ($row['status'] ?? '') === 'granted' && is_string($row['permission'] ?? null)) {
                $granted[] = $row['permission'];
            }
        }

        return array_values(array_unique($granted));
    }

    /**
     * Every ad account the token lists, read to the END of the paging (a page of fifty would call a larger estate «missing»).
     *
     * @return list<string> ids without the `act_` prefix
     */
    public function adAccountIds(PlatformCredentials $app, string $token): array
    {
        $ids = [];
        $url = $app->apiBase().'/me/adaccounts';
        $query = ['fields' => 'account_id', 'limit' => 200];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->get($app, $url, $page === 0 ? $query : [], $token);
            foreach ((array) ($response->json('data') ?? []) as $row) {
                $id = is_array($row) ? ($row['account_id'] ?? $row['id'] ?? null) : null;
                if (is_scalar($id) && (string) $id !== '') {
                    $ids[] = self::normalise((string) $id);
                }
            }
            $next = $response->json('paging.next');
            if (! is_string($next) || $next === '') {
                return array_values(array_unique($ids));
            }
            $url = $next;
        }

        // Not read to its end: refusing is safer than comparing against a partial list.
        throw new RuntimeException(self::FAIL_REFUSED);
    }

    public static function normalise(string $accountId): string
    {
        return str_starts_with($accountId, 'act_') ? substr($accountId, 4) : $accountId;
    }

    /** @param array<string,mixed> $query */
    private function get(PlatformCredentials $app, string $url, array $query, ?string $bearer = null): Response
    {
        try {
            $request = PlatformHttp::client('meta');
            $response = ($bearer === null ? $request : $request->withToken($bearer))->get($url, $query);
        } catch (ConnectionException) {
            throw new RuntimeException(self::FAIL_UNREACHABLE);
        }

        if ($response->failed()) {
            $code = (int) $response->json('error.code', 0);
            // 190 = the token is expired, revoked or malformed; 102 = its session is gone.
            $invalid = in_array($code, [190, 102], true) || $response->status() === 401;
            throw new RuntimeException($invalid ? self::FAIL_INVALID_TOKEN : ($response->serverError() ? self::FAIL_UNREACHABLE : self::FAIL_REFUSED));
        }

        return $response;
    }
}
