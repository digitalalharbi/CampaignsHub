<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Enums;

use App\Domains\Accounts\Enums\AccountType;

/**
 * The five portals of CampaignsHub (ADR 0002).
 *
 * A portal is NOT a menu that changes by account type — it is its own route tree, its own layout, its
 * own permission surface and its own data scope, over one shared domain core. This enum is the single
 * vocabulary for that, shared by the membership model, the resolver and the router.
 */
enum Portal: string
{
    /**
     * The platform owner's console. NOT a tenant: it administers tenants, plans, payments, the
     * shared taxonomy layer, the public site and the audit trail.
     *
     * Held by `is_platform_admin`, not by a membership row, because the owner belongs to no tenant —
     * granting them a membership would put them inside one of the workspaces they administer.
     */
    case Admin = 'admin';

    /** Advertisers and brands running their own campaigns. */
    case App = 'app';

    /** A full multi-client agency system — not a role inside the advertiser portal. */
    case Agency = 'agency';

    /** Influencer and UGC campaigns; brand / agency / influencer / creator each see a different surface. */
    case Influencers = 'influencers';

    /** Clients who order services without needing a full campaign workspace. */
    case ClientPortal = 'portal';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $p) => $p->value, self::cases());
    }

    /**
     * The portals a MEMBERSHIP can name — everything except the owner's console.
     *
     * `Admin` is a case of this enum because it is a portal in every other sense: its own route tree,
     * layout and permission surface. But it is never a membership: the owner belongs to no tenant, and
     * a membership necessarily names one. Granting one would place them inside a workspace they
     * administer, and make "which tenant is this request for?" answerable when it must not be.
     *
     * Iterating `cases()` where a membership is meant is therefore a bug, and a quiet one — it would
     * mint an admin membership in whatever tenant happened to be at hand.
     *
     * @return list<self>
     */
    public static function membershipPortals(): array
    {
        return array_values(array_filter(self::cases(), fn (self $p) => $p !== self::Admin));
    }

    /** True when this portal is entered through a membership rather than through `is_platform_admin`. */
    public function isMembershipPortal(): bool
    {
        return $this !== self::Admin;
    }

    /** The route prefix this portal owns. The frontend router mirrors these exactly. */
    public function path(): string
    {
        return '/'.$this->value;
    }

    /**
     * Where a visitor with this membership lands after authenticating. Kept beside `path()` so the
     * landing page can differ from the prefix later without the two drifting apart.
     */
    public function landingPath(): string
    {
        return match ($this) {
            self::Admin => '/admin',
            self::App => '/app/dashboard',
            self::Agency => '/agency',
            self::Influencers => '/influencers',
            self::ClientPortal => '/portal',
        };
    }

    /**
     * The portal a workspace's account type implies. Used to seed the first membership at
     * registration; it is a starting point, never a permanent property of the user — a user may
     * hold memberships in several portals at once.
     */
    public static function forAccountType(?string $accountType): self
    {
        return match ($accountType) {
            AccountType::Agency->value => self::Agency,
            default => self::App,
        };
    }

    public static function tryFromPath(?string $raw): ?self
    {
        return $raw === null ? null : self::tryFrom(ltrim($raw, '/'));
    }
}
