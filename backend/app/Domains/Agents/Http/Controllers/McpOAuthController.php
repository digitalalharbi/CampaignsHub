<?php

declare(strict_types=1);

namespace App\Domains\Agents\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Tenancy\Context\MembershipContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * MCP-OAUTH-001 (Owner, 2026-11-17) — connect Claude or ChatGPT the way a store connects Salla: paste
 * `mcp.campaignshub.io` into «Add custom connector», sign in to CampaignsHub, press «سماح», done.
 *
 * OAuth 2.1 as the MCP authorization spec asks for it: protected-resource metadata (RFC 9728), authorization-server
 * metadata (RFC 8414), dynamic client registration (RFC 7591) for public clients, the authorization-code grant with
 * PKCE S256 only, and refresh tokens that rotate on every use. The person's consent happens in the CampaignsHub app
 * itself (`/oauth/mcp`), signed in as themselves; the token issued is the same kind «ربط MCP» lists, so it acts as that
 * person inside their own permissions and can be revoked there. Codes live ten minutes and are single-use; codes and
 * refresh tokens are stored only as SHA-256 hashes.
 */
final class McpOAuthController extends Controller
{
    private const CODE_TTL_MINUTES = 10;

    private const ACCESS_TTL_DAYS = 30;

    private const REFRESH_TTL_DAYS = 90;

    private const PREFIX = 'mcp:';

    public function __construct(private readonly AuditLogger $audit, private readonly MembershipContext $memberships) {}

    /** GET /.well-known/oauth-protected-resource (served at /api/v1/mcp/oauth/protected-resource) */
    public function protectedResource(): JsonResponse
    {
        return response()->json([
            'resource' => $this->base(),
            'authorization_servers' => [$this->base()],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => ['mcp'],
            'resource_name' => 'CampaignsHub',
        ]);
    }

    /** GET /.well-known/oauth-authorization-server (served at /api/v1/mcp/oauth/metadata) */
    public function metadata(): JsonResponse
    {
        $base = $this->base();

        return response()->json([
            'issuer' => $base,
            'authorization_endpoint' => $base.'/authorize',
            'token_endpoint' => $base.'/token',
            'registration_endpoint' => $base.'/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => ['mcp'],
        ]);
    }

    /** POST /register — RFC 7591 for a public client: it names itself and the addresses it may be sent back to. */
    public function register(Request $request): JsonResponse
    {
        $uris = $request->input('redirect_uris');
        if (! is_array($uris) || $uris === [] || count($uris) > 10) {
            return $this->oauthError('invalid_redirect_uri', 'redirect_uris is required.');
        }
        foreach ($uris as $uri) {
            if (! is_string($uri) || ! $this->acceptableRedirect($uri)) {
                return $this->oauthError('invalid_redirect_uri', 'Each redirect URI must be https, or http on this computer.');
            }
        }
        $name = trim((string) $request->input('client_name', ''));
        $name = $name === '' ? 'MCP client' : Str::limit($name, 100, '');

        $clientId = 'mcp_'.Str::random(32);
        DB::table('mcp_oauth_clients')->insert([
            'client_id' => $clientId,
            'name' => $name,
            'redirect_uris' => json_encode(array_values($uris)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'client_id' => $clientId,
            'client_id_issued_at' => now()->getTimestamp(),
            'client_name' => $name,
            'redirect_uris' => array_values($uris),
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], 201);
    }

    /**
     * GET /authorize — checks the client and where it wants the person sent back, then hands the person to the consent page
     * in the app, which knows who is signed in. A wrong client or address is answered here, never redirected to.
     */
    public function authorizeRequest(Request $request): JsonResponse|RedirectResponse
    {
        $client = $this->client((string) $request->query('client_id', ''));
        $redirect = (string) $request->query('redirect_uri', '');
        if ($client === null || ! in_array($redirect, $client['redirect_uris'], true)) {
            return $this->oauthError('invalid_request', 'Unknown client or redirect URI.');
        }
        if ($request->query('response_type') !== 'code' || ! is_string($request->query('code_challenge')) || $request->query('code_challenge_method') !== 'S256') {
            return redirect()->away($this->withQuery($redirect, ['error' => 'invalid_request', 'error_description' => 'code with PKCE S256 is required', 'state' => $request->query('state')]));
        }

        return redirect()->away(rtrim((string) config('brand.frontend_url'), '/').'/oauth/mcp?'.http_build_query($request->query()));
    }

    /** GET /api/v1/mcp/oauth/clients/{clientId} — the name the consent page shows. */
    public function show(string $clientId): JsonResponse
    {
        $client = $this->client($clientId);
        abort_if($client === null, 404);

        return response()->json(['client_id' => $clientId, 'name' => $client['name']]);
    }

    /** POST /api/v1/mcp/oauth/approve — the signed-in person pressed «سماح»: a single-use code goes back to the app. */
    public function approve(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        // MCP-SCOPE-001 — platform staff run across every tenant; a connector never gets that reach.
        abort_if($user->is_platform_admin, 403, 'platform_staff');
        $membership = $this->memberships->membership();
        abort_if($membership === null, 403, 'no_workspace');
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:64'],
            'redirect_uri' => ['required', 'string', 'max:2000'],
            'code_challenge' => ['required', 'string', 'min:43', 'max:128'],
            'code_challenge_method' => ['required', 'in:S256'],
            'state' => ['nullable', 'string', 'max:500'],
        ]);
        $client = $this->client($data['client_id']);
        abort_if($client === null || ! in_array($data['redirect_uri'], $client['redirect_uris'], true), 422, 'invalid_client');

        $code = Str::random(48);
        DB::table('mcp_oauth_codes')->insert([
            'code_hash' => hash('sha256', $code),
            'client_id' => $data['client_id'],
            'user_id' => $user->getKey(),
            'redirect_uri' => $data['redirect_uri'],
            'code_challenge' => $data['code_challenge'],
            'membership_id' => $membership->getKey(),
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit->log(action: 'mcp.oauth.approved', entityType: 'mcp_oauth_client', entityId: $data['client_id'], after: ['name' => $client['name']]);

        return response()->json(['redirect' => $this->withQuery($data['redirect_uri'], ['code' => $code, 'state' => $data['state'] ?? null])]);
    }

    /** POST /token — the code (with its PKCE verifier) or a refresh token, exchanged for an access token. */
    public function token(Request $request): JsonResponse
    {
        return match ((string) $request->input('grant_type')) {
            'authorization_code' => $this->exchangeCode($request),
            'refresh_token' => $this->refresh($request),
            default => $this->oauthError('unsupported_grant_type', 'authorization_code or refresh_token.'),
        };
    }

    private function exchangeCode(Request $request): JsonResponse
    {
        $row = DB::table('mcp_oauth_codes')->where('code_hash', hash('sha256', (string) $request->input('code')))->first();
        if ($row === null || $row->used_at !== null || now()->greaterThan((string) $row->expires_at)
            || (string) $row->client_id !== (string) $request->input('client_id') || (string) $row->redirect_uri !== (string) $request->input('redirect_uri')) {
            return $this->oauthError('invalid_grant', 'The code is unknown, used, expired, or not for this client.');
        }
        $verifier = (string) $request->input('code_verifier');
        $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        // Single use, spent before the check: a wrong verifier burns the code rather than inviting a second guess.
        DB::table('mcp_oauth_codes')->where('id', $row->id)->update(['used_at' => now(), 'updated_at' => now()]);
        if ($verifier === '' || ! hash_equals((string) $row->code_challenge, $computed)) {
            return $this->oauthError('invalid_grant', 'The PKCE verifier does not match.');
        }
        $user = User::query()->find($row->user_id);
        if (! $user instanceof User) {
            return $this->oauthError('invalid_grant', 'The person is gone.');
        }

        return $this->issue($user, (string) $row->client_id, $row->membership_id === null ? null : (string) $row->membership_id);
    }

    private function refresh(Request $request): JsonResponse
    {
        $row = DB::table('mcp_oauth_refresh_tokens')->where('token_hash', hash('sha256', (string) $request->input('refresh_token')))->first();
        if ($row === null || $row->revoked_at !== null || now()->greaterThan((string) $row->expires_at)
            || ($request->filled('client_id') && (string) $row->client_id !== (string) $request->input('client_id'))) {
            return $this->oauthError('invalid_grant', 'The refresh token is unknown, revoked or expired.');
        }
        DB::table('mcp_oauth_refresh_tokens')->where('id', $row->id)->update(['revoked_at' => now(), 'updated_at' => now()]);
        if ($row->access_token_id !== null) {
            PersonalAccessToken::query()->whereKey($row->access_token_id)->delete();
        }
        $user = User::query()->find($row->user_id);
        if (! $user instanceof User) {
            return $this->oauthError('invalid_grant', 'The person is gone.');
        }

        return $this->issue($user, (string) $row->client_id, $row->membership_id === null ? null : (string) $row->membership_id);
    }

    private function issue(User $user, string $clientId, ?string $membershipId): JsonResponse
    {
        $name = ($this->client($clientId) ?? ['name' => 'MCP client'])['name'];
        // Expired connections are cleared so they never crowd the person's list in «ربط MCP».
        $user->tokens()->where('name', 'like', self::PREFIX.'%')->whereNotNull('expires_at')->where('expires_at', '<', now())->delete();
        // Pinned to the approved workspace (MCP-SCOPE-001): ResolveMembership answers this token from it and from nothing else.
        $abilities = $membershipId === null ? ['mcp'] : ['mcp', 'membership:'.$membershipId];
        $access = $user->createToken(self::PREFIX.$name, $abilities, now()->addDays(self::ACCESS_TTL_DAYS));
        $refresh = Str::random(64);
        DB::table('mcp_oauth_refresh_tokens')->insert([
            'token_hash' => hash('sha256', $refresh),
            'client_id' => $clientId,
            'user_id' => $user->getKey(),
            'access_token_id' => $access->accessToken->getKey(),
            'membership_id' => $membershipId,
            'expires_at' => now()->addDays(self::REFRESH_TTL_DAYS),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'access_token' => $access->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL_DAYS * 86400,
            'refresh_token' => $refresh,
            'scope' => 'mcp',
        ])->header('Cache-Control', 'no-store');
    }

    /** @return array{name: string, redirect_uris: list<string>}|null */
    private function client(string $clientId): ?array
    {
        if ($clientId === '') {
            return null;
        }
        $row = DB::table('mcp_oauth_clients')->where('client_id', $clientId)->first();
        if ($row === null) {
            return null;
        }
        $uris = json_decode((string) $row->redirect_uris, true);

        return ['name' => (string) $row->name, 'redirect_uris' => is_array($uris) ? array_values(array_filter($uris, 'is_string')) : []];
    }

    private function acceptableRedirect(string $uri): bool
    {
        $parts = parse_url($uri);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['fragment'])) {
            return false;
        }

        return $parts['scheme'] === 'https' || ($parts['scheme'] === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true));
    }

    /** @param array<string, mixed> $params */
    private function withQuery(string $uri, array $params): string
    {
        $params = array_filter($params, static fn ($v): bool => $v !== null && $v !== '');

        return $uri.(str_contains($uri, '?') ? '&' : '?').http_build_query($params);
    }

    private function oauthError(string $error, string $description): JsonResponse
    {
        return response()->json(['error' => $error, 'error_description' => $description], 400);
    }

    /** The MCP server's own public address — what Claude or ChatGPT was given. */
    private function base(): string
    {
        return rtrim((string) config('services.mcp.url', 'https://mcp.campaignshub.io'), '/');
    }
}
