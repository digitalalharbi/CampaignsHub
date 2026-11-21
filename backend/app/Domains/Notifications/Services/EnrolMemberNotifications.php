<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Domains\Audit\AuditLogger;
use App\Domains\Notifications\Support\MailAddress;
use App\Domains\Notifications\Support\MessageCatalogue;
use App\Domains\Tenancy\Enums\Portal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * NOTIFICATION-ENROLMENT-001 — a colleague is reachable from the day they exist.
 *
 * Owner, 2026-11-06: «الفكرة كل مستخدم يحتاج إشعارات وملخصات على الإيميل، هل هي فعّالة أو لا؟»
 *
 * ## The fact this answers
 *
 * Every sender in this product is driven from `notification_preferences`: `SendDailyDigests` and
 * `AlertEventMailer::sweep` iterate that table, never the users table, and say so on purpose — a
 * digest is opt-in, and defaulting every account in would mail the whole installation on the first
 * run. But nothing ever CREATED a row except somebody opening the settings screen and pressing save.
 * Production had ten verified users and one row. Nine people could not receive a single alert or
 * digest, ever, and none of them knew a setting existed.
 *
 * ## What this does, and what it refuses to do
 *
 * It creates the row, once, with the defaults documented on `MessageCatalogue`. It is the ONLY
 * writer of an enrolment row, so there is one place to read what «enrolled by default» means.
 *
 * - **Idempotent and concurrency-safe.** `INSERT … ON CONFLICT DO NOTHING` against the unique
 *   `(tenant_id, user_id)` index. Two requests, two workers and a backfill racing each other produce
 *   one row.
 * - **A row is the record of a choice.** If one exists it is never read, never merged, never
 *   overwritten. Somebody who switched everything off has a row that says so; enrolling them again
 *   would be the product overruling the person it is meant to serve.
 * - **Staff only.** The portals that are a colleague's own workspace (`agency`, `app`). Never the
 *   client portal — a client's mail follows the client-report rules, and a client user reaches the
 *   product through a membership that is confined to one client space; never a platform admin,
 *   who is not inside any tenant; never a person whose address was not proved, because a digest to
 *   an address nobody confirmed is a mail to a stranger.
 * - **No scope of its own.** Enrolling grants nothing to read. `DigestScope` still resolves what
 *   each person may be sent from their own membership, and the digest builder still withholds spend
 *   from a membership without `money.view`. A new member with no project access is enrolled and
 *   receives nothing, because there is nothing they may be told.
 */
final class EnrolMemberNotifications
{
    /** The portals that are a colleague's own workspace. Influencer/creator and client portals are NOT staff. */
    public const STAFF_PORTALS = ['agency', 'app'];

    /** Digests a new colleague starts with — see the decision on {@see MessageCatalogue}. */
    public const DEFAULT_DIGESTS = ['daily' => true, 'weekly' => true, 'monthly' => false, 'alerts' => true, 'recommendations' => true];

    public const DEFAULT_TIMEZONE = 'Asia/Riyadh';

    public const DEFAULT_HOUR = 8;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * May this person be enrolled in this workspace, and if not, the one reason.
     *
     * @return array{eligible: bool, reason: ?string, role: ?string, portal: ?string}
     */
    public function verdict(User $user, string $tenantId): array
    {
        $membership = DB::table('memberships')
            ->where('user_id', $user->getKey())->where('tenant_id', $tenantId)
            ->where('status', 'active')->whereIn('portal', self::STAFF_PORTALS)
            ->orderBy('created_at')->first();

        $refusal = match (true) {
            $user->is_platform_admin === true => 'platform_admin',
            $user->email === null || trim((string) $user->email) === '' => 'no_email',
            $user->email_verified_at === null => 'email_not_verified',
            $user->disabled_at !== null => 'disabled',
            $membership === null => 'not_staff_member',
            default => null,
        };

        return ['eligible' => $refusal === null, 'reason' => $refusal, 'role' => $membership->role ?? null, 'portal' => $membership->portal ?? null];
    }

    /**
     * The row a new colleague gets. Pure: reads the user, writes nothing.
     *
     * `types` is left null on purpose, so every message follows the catalogue's own defaults —
     * including each type's importance threshold — instead of a copy of them frozen into the row.
     * `categories` is an empty map for the same reason: a stored per-category choice outranks the
     * catalogue default, and nobody has made one.
     *
     * @return array<string, mixed>
     */
    public function defaultsFor(User $user): array
    {
        $timezone = (string) ($user->timezone ?? '');

        return [
            'channels' => ['in_app' => true, 'email' => true],
            'categories' => [],
            'types' => null,
            'quiet_hours' => ['enabled' => false, 'start' => '22:00', 'end' => '08:00'],
            'frequency' => 'realtime',
            'project_ids' => null,
            'digests' => self::DEFAULT_DIGESTS,
            'locale' => in_array($user->locale, ['ar', 'en'], true) ? (string) $user->locale : 'ar',
            'timezone' => in_array($timezone, timezone_identifiers_list(), true) ? $timezone : self::DEFAULT_TIMEZONE,
            'digest_hour' => self::DEFAULT_HOUR,
        ];
    }

    /** Does this person already have a row here? A row — of any shape — is their recorded choice. */
    public function hasRow(int $userId, string $tenantId): bool
    {
        return DB::table('notification_preferences')->where('tenant_id', $tenantId)->where('user_id', $userId)->exists();
    }

    /**
     * Enrol one person in one workspace.
     *
     * @param  string  $via  where the person came to exist or be activated; recorded in the audit row
     * @return string `enrolled`, `already_enrolled`, or the refusal reason from {@see verdict()}
     */
    public function enrol(User $user, string $tenantId, string $via): string
    {
        // The model in hand may predate the proof of the address or the disabling; the row is the fact.
        $user = $user->fresh() ?? $user;
        $verdict = $this->verdict($user, $tenantId);

        if (! $verdict['eligible']) {
            return (string) $verdict['reason'];
        }

        if ($this->hasRow((int) $user->getKey(), $tenantId)) {
            return 'already_enrolled';
        }

        $d = $this->defaultsFor($user);
        $now = now();

        $inserted = DB::table('notification_preferences')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'user_id' => $user->getKey(),
            'channels' => json_encode($d['channels']),
            'categories' => json_encode((object) $d['categories']),
            'types' => null,
            'quiet_hours' => json_encode($d['quiet_hours']),
            'frequency' => $d['frequency'],
            'project_ids' => null,
            'digests' => json_encode($d['digests']),
            'locale' => $d['locale'],
            'timezone' => $d['timezone'],
            'digest_hour' => $d['digest_hour'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Lost the race to another writer: their row stands, and this call changed nothing.
        if ($inserted === 0) {
            return 'already_enrolled';
        }

        $this->audit->log(
            action: 'notifications.member_enrolled',
            entityType: User::class,
            entityId: (string) $user->getKey(),
            after: [
                'via' => $via, 'role' => $verdict['role'], 'portal' => $verdict['portal'],
                'email' => MailAddress::mask((string) $user->email),
                'digests' => $d['digests'], 'locale' => $d['locale'], 'timezone' => $d['timezone'],
            ],
            reason: 'default_enrolment',
            userId: null,
            tenantId: $tenantId,
        );

        return 'enrolled';
    }

    /**
     * The same, from a hook that must never break the thing it hangs off.
     *
     * A grant of access, a verified address or a reactivation has already happened; failing to open a
     * notification row is a smaller problem than undoing that. The failure is reported, and the
     * backfill command is the safety net that finds anyone this missed.
     */
    public function enrolQuietly(User $user, string $tenantId, string $via): void
    {
        try {
            $this->enrol($user, $tenantId, $via);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Every workspace this person is a staff member of — used when an address is proved after the grant. */
    public function enrolEverywhere(User $user, string $via): void
    {
        $tenants = DB::table('memberships')->where('user_id', $user->getKey())->where('status', 'active')
            ->whereIn('portal', self::STAFF_PORTALS)->distinct()->pluck('tenant_id');

        foreach ($tenants as $tenantId) {
            $this->enrolQuietly($user, (string) $tenantId, $via);
        }
    }

    /** The portal values a hook may ask about, for callers that hold a {@see Portal}. */
    public static function isStaffPortal(Portal $portal): bool
    {
        return in_array($portal->value, self::STAFF_PORTALS, true);
    }
}
