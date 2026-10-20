<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Support;

use App\Domains\Audit\AuditLogger;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * SALLA-REVIEW-001 — the restricted workspace an app-store reviewer is given instead of a real merchant account.
 *
 * A review workspace is an ordinary tenant whose `settings.review` says so: `{active, expires_at}`. Everything
 * here keys off that one fact, so the restrictions cannot be forgotten on a new surface, and revoking or
 * extending is a change to two fields.
 *
 * **While active**, a reviewer can sign in, see the demo dashboards and reports, and connect a Salla store. They
 * cannot export or download anything, create API tokens, invite anybody, touch webhook settings, reach the admin
 * panel, or see billing; they are held to 60 requests a minute; and every session leaves an audit trail (the
 * request, the address and the browser, once a minute per route). **After** `expires_at`, or once revoked,
 * sign-in is refused with a message that says why, and a session that is still open is ended on its next request.
 *
 * Enforced where the tenant is resolved (`ResolveMembership`), the one place every authenticated request passes,
 * rather than as a middleware to be remembered on each route group.
 */
final class ReviewAccess
{
    public const LABEL = 'Salla Review';

    public const RATE_PER_MINUTE = 60;

    /**
     * URIs (after `api/v1/`) a reviewer is refused. A null verb list means every verb.
     *
     * @var array<string, list<string>|null>
     */
    private const DENIED = [
        '#(^|/)(exports?|download)(/|$)#' => null,   // exporting or downloading any file
        '#^auth/tokens#' => null,                     // minting API tokens
        '#invitations#' => null,                      // inviting anybody
        '#^(app|agency)/team#' => ['POST', 'PUT', 'PATCH', 'DELETE'],
        '#^settings/team#' => ['POST', 'PUT', 'PATCH', 'DELETE'],
        '#webhook#' => null,                          // webhook settings and sinks
        '#^admin(/|$)#' => null,                      // the platform admin panel
        '#(^|/)(billing|subscriptions|payments)(/|$)#' => null, // money
        '#^data-(requests|deletion)#' => null,
        // A shared link is a way to hand a file out (the link's own download is a public export).
        '#(^|/)(shares|report-links)(/|$)#' => ['POST', 'PUT', 'PATCH', 'DELETE'],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{active: bool, expires_at: ?string}|null null when the tenant is not a review workspace */
    public static function settingsOf(?Tenant $tenant): ?array
    {
        $review = $tenant === null ? null : (($tenant->settings ?? [])['review'] ?? null);

        return is_array($review) ? ['active' => (bool) ($review['active'] ?? false), 'expires_at' => isset($review['expires_at']) ? (string) $review['expires_at'] : null] : null;
    }

    /** Whether this review workspace may still be used: active, and not past its expiry. */
    public static function isLive(?Tenant $tenant): bool
    {
        $review = self::settingsOf($tenant);

        return $review !== null && $review['active'] && ($review['expires_at'] === null || Carbon::parse($review['expires_at'])->isFuture());
    }

    /** Whether this person belongs to any review workspace at all (live or ended): such a person is never issued an API token. */
    public static function isReviewer(User $user): bool
    {
        return Membership::query()->forUser($user->getKey())->active()->get()
            ->contains(fn (Membership $m) => $m->tenant instanceof Tenant && self::settingsOf($m->tenant) !== null);
    }

    /** True when the person can reach no workspace except review workspaces whose access has ended. */
    public static function endedFor(User $user): bool
    {
        $tenants = Membership::query()->forUser($user->getKey())->active()->get()->map(fn (Membership $m) => $m->tenant)->filter(fn ($t) => $t instanceof Tenant);

        return $tenants->isNotEmpty() && $tenants->every(fn ($t) => $t instanceof Tenant && self::settingsOf($t) !== null && ! self::isLive($t));
    }

    public const ENDED_MESSAGE = 'Your Salla review access has ended. Ask CampaignsHub to extend it if you still need it.';

    /** A response refusing the request, or null to let it through. */
    public function deny(Request $request, User $user, ?Tenant $tenant): ?JsonResponse
    {
        if ($tenant === null || self::settingsOf($tenant) === null || $user->is_platform_admin) {
            return null;
        }

        if (! self::isLive($tenant)) {
            return response()->json(['success' => false, 'message' => self::ENDED_MESSAGE, 'code' => 'review_ended'], 403);
        }

        $path = preg_replace('#^api/v1/#', '', ltrim($request->path(), '/')) ?? $request->path();
        foreach (self::DENIED as $pattern => $verbs) {
            if (preg_match($pattern, $path) === 1 && ($verbs === null || in_array($request->method(), $verbs, true))) {
                $this->record($request, $user, $tenant, 'review.refused', $path);

                return response()->json(['success' => false, 'message' => 'This is not available in the Salla review account.', 'code' => 'review_restricted'], 403);
            }
        }

        $key = 'review-rate:'.$user->getKey();
        if (RateLimiter::tooManyAttempts($key, self::RATE_PER_MINUTE)) {
            return response()->json(['success' => false, 'message' => 'Too many requests. Please slow down.', 'code' => 'review_rate_limited'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 60);

        // One audit entry per route per minute: the session's pages, not a log of every poll.
        if (Cache::add('review-audit:'.$user->getKey().':'.$request->method().':'.$path, 1, 60)) {
            $this->record($request, $user, $tenant, 'review.request', $path);
        }

        return null;
    }

    private function record(Request $request, User $user, Tenant $tenant, string $action, string $path): void
    {
        $this->audit->log(
            action: $action,
            entityType: Tenant::class,
            entityId: (string) $tenant->getKey(),
            after: ['method' => $request->method(), 'path' => $path, 'ip' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 200)],
            userId: (int) $user->getKey(),
        );
    }
}
