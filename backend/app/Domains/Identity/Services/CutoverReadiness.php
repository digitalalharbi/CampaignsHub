<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Domains\Identity\Models\PortalIdentityConflict;
use App\Domains\Requests\Models\ClientPortalToken;
use App\Domains\Requests\Services\ClientPortalContacts;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Is it safe to retire the legacy portal engine yet? (PORTAL-AUTH-001 step 5)
 *
 * This answers with EVIDENCE rather than judgement, because the alternative is somebody deciding the
 * migration "looks done". Three conditions, all of which must be zero:
 *
 *   1. No open identity conflict. Each one is a person the backfill refused to resolve; retiring
 *      while any is open locks them out with nobody having been told.
 *   2. No live session still served by the token. Those holders have no password — signing them out
 *      is not recoverable by them.
 *   3. No parity disagreement. If the two engines answer differently for anyone, the cutover changes
 *      what that person can see, invisibly.
 *
 * The check is READ-ONLY and always has been. There is deliberately no method here that performs the
 * cutover: retiring the engine is a code change with a review, not a button that could be pressed by
 * someone reading a green light.
 */
final class CutoverReadiness
{
    private const LAST_RUN_KEY = 'portal.cutover.last_checked_at';

    public function __construct(private readonly ClientPortalContacts $contacts) {}

    /**
     * @return array{
     *   ready: bool,
     *   blockers: list<string>,
     *   open_conflicts: int,
     *   legacy_sessions: int,
     *   legacy_holders: list<array{contact: string, expires_at: ?string, last_used_at: ?string, has_membership: bool}>,
     *   parity: array{checked: int, mismatched: int, mismatches: list<array{contact: string, membership: list<string>, token: list<string>}>},
     *   last_checked_at: ?string
     * }
     */
    public function check(): array
    {
        $openConflicts = PortalIdentityConflict::query()->whereNull('resolution')->count();

        // "Live" means a token that would still authenticate: not revoked, not expired. An expired
        // one cannot sign anybody in, so it does not block anything.
        $live = ClientPortalToken::query()
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('last_used_at')
            ->get();

        $holders = [];
        $checked = 0;
        $mismatches = [];

        foreach ($live as $token) {
            $email = $token->contact_email === null ? null : Str::lower($token->contact_email);
            $user = $email === null ? null : User::query()->where('email', $email)->first();

            $membership = $user === null ? null : Membership::query()
                ->where('user_id', $user->getKey())
                ->where('tenant_id', $token->tenant_id)
                ->where('portal', Portal::ClientPortal->value)
                ->active()->with('scopes')->first();

            $holders[] = [
                'contact' => $email ?? $token->contact_phone ?? '—',
                'expires_at' => $token->expires_at?->toIso8601String(),
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                // A holder WITH a membership will be upgraded on their next sign-in. One without is
                // the harder case: they need a conflict resolved first.
                'has_membership' => $membership !== null,
            ];

            if ($membership === null) {
                continue;
            }

            $checked++;

            $fromMembership = $membership->clientScopeIds();
            sort($fromMembership);
            $fromToken = $this->contacts->ownedWorkspaceIds($token);
            sort($fromToken);

            if ($fromMembership !== $fromToken) {
                // Named, not counted. "3 mismatches" tells nobody whose portal is about to change.
                $mismatches[] = [
                    'contact' => $email ?? '—',
                    'membership' => $fromMembership,
                    'token' => $fromToken,
                ];
            }
        }

        $blockers = [];
        if ($openConflicts > 0) {
            $blockers[] = "{$openConflicts} identity conflict(s) still open";
        }
        if ($live->isNotEmpty()) {
            $blockers[] = $live->count().' session(s) still depend on the legacy token';
        }
        if ($mismatches !== []) {
            $blockers[] = count($mismatches).' contact(s) where the two engines disagree';
        }

        Cache::forever(self::LAST_RUN_KEY, now()->toIso8601String());

        return [
            'ready' => $blockers === [],
            'blockers' => $blockers,
            'open_conflicts' => $openConflicts,
            'legacy_sessions' => $live->count(),
            'legacy_holders' => array_slice($holders, 0, 100),
            'parity' => [
                'checked' => $checked,
                'mismatched' => count($mismatches),
                'mismatches' => $mismatches,
            ],
            'last_checked_at' => Cache::get(self::LAST_RUN_KEY),
        ];
    }

    /** When the check last ran, without running it. */
    public function lastCheckedAt(): ?string
    {
        return Cache::get(self::LAST_RUN_KEY);
    }
}
