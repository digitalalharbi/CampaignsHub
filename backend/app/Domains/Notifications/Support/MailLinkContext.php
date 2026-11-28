<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Models\User;
use Closure;
use Throwable;

/**
 * MAIL-LINK-RECIPIENT-001 — Owner 2026-11-28: «افتح التقرير» and «افتح لوحة المتابعة» in a Smart Code digest opened `/app/…`
 * (another workspace, or the marketing page), and a reader with no access to reports was still offered the report.
 *
 * A link in an email is written FOR ITS RECIPIENT, in the workspace the email is about:
 *  - the portal is the recipient's own membership portal in THAT tenant (`/agency/…` for an agency member, `/app/…` for an
 *    advertiser, `/portal/…` for a client) — never a hard-coded `/app`;
 *  - for a person who belongs to several workspaces the tenant travels as `ws=<tenant id>`, so they are offered the switch to the
 *    one the email is about (`usePortalEntry`) instead of opening the same path in whichever workspace their session is in;
 *  - a link to a section the recipient may not open is not drawn at all ({@see self::allows()}) — the same
 *    `ProjectAbilities` the pages ask, so the mail never promises a page that refuses.
 *
 * Set once per recipient by the sender (digest, budget notice, engine mail) for the duration of one build and cleared after.
 * With no context (a preview, the alert sweep's own fixtures) every link keeps its historical `/app/…` form and every check
 * passes, so nothing that is not addressed to a person changes.
 */
final class MailLinkContext
{
    private static ?string $portal = null;

    private static ?string $tenantId = null;

    private static bool $external = false;

    /** Whether the addressed person belongs to more than one workspace — only then does a link need to name its workspace. */
    private static bool $several = false;

    /** @var (Closure(string, string): bool)|null */
    private static ?Closure $can = null;

    /**
     * Address the next links to `$user` in `$tenantId`. Returns the portal key chosen.
     *
     * @param  (Closure(string, string): bool)|null  $can  project id, capability → allowed; defaults to `ProjectAbilities`
     */
    public static function enter(User $user, string $tenantId, ?Closure $can = null): string
    {
        self::$tenantId = $tenantId;
        self::$portal = self::portalFor((int) $user->getKey(), $tenantId);
        self::$several = self::inSeveral((int) $user->getKey());
        self::$external = false;
        self::$can = $can ?? static function (string $projectId, string $capability) use ($user): bool {
            try {
                return app(ProjectAbilities::class)->allows($user, $projectId, $capability);
            } catch (Throwable) {
                return false;
            }
        };

        return self::$portal;
    }

    /** A recipient outside the platform: no link into the product at all (rules §8). */
    public static function enterExternal(string $tenantId): void
    {
        self::$tenantId = $tenantId;
        self::$portal = null;
        self::$external = true;
        self::$can = static fn (): bool => false;
    }

    public static function forget(): void
    {
        self::$portal = null;
        self::$tenantId = null;
        self::$external = false;
        self::$several = false;
        self::$can = null;
    }

    public static function active(): bool
    {
        return self::$tenantId !== null;
    }

    public static function external(): bool
    {
        return self::$external;
    }

    /** `/agency`, `/app` or `/portal` — the recipient's portal; `/app` when nobody is addressed. */
    public static function prefix(): string
    {
        return '/'.(self::$portal ?? Portal::App->value);
    }

    /**
     * A portal-relative path (`/reports?project=…`) as an absolute path inside the recipient's portal, with the workspace.
     * A path that already names a portal (`/app/…`, `/agency/…`, `/portal/…`) is re-homed to the recipient's portal.
     */
    public static function path(string $relative): string
    {
        $relative = '/'.ltrim($relative, '/');
        foreach (['/app', '/agency', '/portal'] as $known) {
            if ($relative === $known || str_starts_with($relative, $known.'/') || str_starts_with($relative, $known.'?')) {
                $relative = substr($relative, strlen($known)) ?: '/dashboard';
                break;
            }
        }
        $relative = '/'.ltrim($relative, '/');

        return self::withWorkspace(self::prefix().$relative);
    }

    /**
     * The same re-homing for one recipient without entering a context — for a mail queued now and rendered later by a worker
     * (the bell's operational mail), whose link must be fixed at the moment it is addressed.
     */
    public static function pathFor(int $userId, string $tenantId, string $relative): string
    {
        $saved = [self::$portal, self::$tenantId, self::$external, self::$can, self::$several];
        try {
            self::$portal = self::portalFor($userId, $tenantId);
            self::$tenantId = $tenantId;
            self::$external = false;
            self::$several = self::inSeveral($userId);

            return self::path($relative);
        } finally {
            [self::$portal, self::$tenantId, self::$external, self::$can, self::$several] = $saved;
        }
    }

    /**
     * Appends `ws=<tenant>` (last, so `?project=…` stays the first parameter) when the addressed person belongs to more than one
     * workspace — the only case where the same path could open on another workspace's data. A one-workspace reader's links stay clean.
     */
    public static function withWorkspace(string $path): string
    {
        if (self::$tenantId === null || ! self::$several || str_contains($path, 'ws=')) {
            return $path;
        }
        [$base, $hash] = array_pad(explode('#', $path, 2), 2, null);

        return $base.(str_contains($base, '?') ? '&' : '?').'ws='.rawurlencode(self::$tenantId).($hash !== null ? '#'.$hash : '');
    }

    /**
     * May the addressed recipient open `$capability` on `$projectId`? True when nobody is addressed (previews keep their
     * buttons); false for an external recipient (no link into the product, ever).
     */
    public static function allows(?string $projectId, string $capability): bool
    {
        if (self::$external) {
            return false;
        }
        // Nobody addressed (a preview), or a page that is not a project's: nothing to refuse here; the page decides.
        if (self::$can === null || $projectId === null || $projectId === '') {
            return true;
        }

        return (self::$can)($projectId, $capability);
    }

    /** Active memberships in more than one workspace. */
    private static function inSeveral(int $userId): bool
    {
        try {
            return Membership::query()->withoutGlobalScopes()->where('user_id', $userId)->where('status', 'active')
                ->distinct()->count('tenant_id') > 1;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The recipient's portal in the tenant: agency first (the staff portal of a multi-client workspace), then the advertiser
     * portal, then the client portal. A person with no active membership there gets `app`, the historical default.
     */
    public static function portalFor(int $userId, string $tenantId): string
    {
        try {
            $portals = Membership::query()->withoutGlobalScopes()->where('user_id', $userId)->where('tenant_id', $tenantId)
                ->where('status', 'active')->pluck('portal')
                ->map(static fn ($p): string => $p instanceof Portal ? $p->value : (string) $p)->all();
        } catch (Throwable) {
            return Portal::App->value;
        }
        foreach ([Portal::Agency->value, Portal::App->value, Portal::ClientPortal->value] as $portal) {
            if (in_array($portal, $portals, true)) {
                return $portal;
            }
        }

        return Portal::App->value;
    }
}
