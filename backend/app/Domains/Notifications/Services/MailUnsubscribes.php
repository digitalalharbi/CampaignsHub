<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Domains\Notifications\Support\DigestSchedule;
use App\Domains\Notifications\Support\MessageCatalogue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Leaving a kind of message without signing in — EMAIL-GAPS (§17.4.5).
 *
 * ## The link is signed here, not by `URL::signedRoute`
 *
 * A Laravel signature covers the whole URL including its host, and these links must keep working for
 * years through whatever the edge does to the host. The token is an HMAC over its payload alone, with a
 * key derived from `APP_KEY`, so it opens nothing else. It has no expiry: a way out that stops working is
 * how a digest becomes a spam report.
 *
 * ## No address in the URL
 *
 * The payload carries a hash of the recipient, and that hash is all `mail_unsubscribes` stores.
 *
 * ## Two records, kept honest with each other
 *
 * The row is what every sender asks at send time, and it is the only record for a recipient with no
 * account. For a person with an account the preference is switched off too, so the settings screen does
 * not show on a message that has stopped; switching it back on removes the row ({@see restore()}).
 */
final class MailUnsubscribes
{
    /** The alert bundle: several catalogue types in one message, switched by `digests.alerts`. */
    public const ALERTS = 'alerts';

    /** SPEND-SUMMARY-EXTERNAL-RECIPIENTS-001 — a recipient OUTSIDE the platform leaves the budget follow-up with one signed click. */
    public const EXTERNAL = 'external_budget';

    /** SPEND-SUMMARY-EXTERNAL-RECIPIENTS-001 — a workspace reader's weekly «ملخص الإنفاق». */
    public const SPEND = 'spend_summary';

    /**
     * DIGESTS-CADENCE-BUDGETS-001 — the report PER PROJECT, one scope per rhythm, each switching its own `digests` key.
     *
     * Its unsubscribe used to be the projects summary's (`daily_digest`…), so leaving one project report turned off the summary
     * and left the project reports coming.
     */
    public const PROJECT_SCOPES = ['project_daily_digest' => 'per_project', 'project_weekly_digest' => 'per_project_weekly', 'project_monthly_digest' => 'per_project_monthly'];

    /** The per-project scope of a rhythm. */
    public static function projectScope(string $kind): string
    {
        return (string) array_search(DigestSchedule::PER_PROJECT[$kind] ?? 'per_project', self::PROJECT_SCOPES, true);
    }

    public function __construct(private readonly NotificationChoices $choices) {}

    /** The scope a ledger kind unsubscribes from. Null means it cannot be left. */
    public static function scopeOf(string $kind): ?string
    {
        if ($kind === self::ALERTS) {
            return self::ALERTS;
        }

        $type = MessageCatalogue::typeOfKind($kind);

        return $type !== null && MessageCatalogue::isUnsubscribable($type) ? $type : null;
    }

    /** The one-click address for this recipient and scope, or null where none may be offered. */
    public function url(?string $tenantId, string $recipient, ?string $scope, ?int $userId = null): ?string
    {
        if ($tenantId === null || $scope === null || ! self::isOptional($scope)) {
            return null;
        }

        $payload = self::encode((string) json_encode([
            't' => $tenantId,
            'h' => self::hash($recipient),
            's' => $scope,
            'u' => $userId,
        ]));

        return rtrim((string) config('app.url'), '/').'/mail/unsubscribe/'.$payload.'.'.self::sign($payload);
    }

    /**
     * What a token says, if we signed it and it names something that may be left.
     *
     * @return array{t: string, h: string, s: string, u: int|null}|null
     */
    public function read(string $token): ?array
    {
        [$payload, $signature] = array_pad(explode('.', $token, 2), 2, '');

        if ($payload === '' || ! hash_equals(self::sign($payload), $signature)) {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);

        if (! is_array($data) || ! is_string($data['t'] ?? null) || ! is_string($data['h'] ?? null) || ! is_string($data['s'] ?? null)) {
            return null;
        }

        // Signed or not: a security message cannot be left, even by a token minted before that was decided.
        if (! self::isOptional($data['s'])) {
            return null;
        }

        return ['t' => $data['t'], 'h' => $data['h'], 's' => $data['s'], 'u' => is_int($data['u'] ?? null) ? $data['u'] : null];
    }

    /** @param  array{t: string, h: string, s: string, u: int|null}  $token */
    public function leave(array $token): void
    {
        DB::table('mail_unsubscribes')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'tenant_id' => $token['t'],
            'recipient_hash' => $token['h'],
            'scope' => $token['s'],
            'user_id' => $token['u'],
            'created_at' => Carbon::now(),
        ]);

        if ($token['u'] !== null) {
            $this->switchOff($token['u'], $token['t'], $token['s']);
        }
    }

    public function hasLeft(?string $tenantId, string $recipient, ?string $scope): bool
    {
        if ($tenantId === null || $scope === null) {
            return false;
        }

        return DB::table('mail_unsubscribes')
            ->where('tenant_id', $tenantId)
            ->where('recipient_hash', self::hash($recipient))
            ->where('scope', $scope)
            ->exists();
    }

    /** They turned it back on in their settings, which outranks an old click. */
    public function restore(int $userId, string $tenantId, string $scope): void
    {
        DB::table('mail_unsubscribes')->where('tenant_id', $tenantId)->where('user_id', $userId)->where('scope', $scope)->delete();
    }

    public static function hash(string $recipient): string
    {
        return hash('sha256', Str::lower(trim($recipient)));
    }

    private static function isOptional(string $scope): bool
    {
        return in_array($scope, [self::ALERTS, self::EXTERNAL, self::SPEND], true) || isset(self::PROJECT_SCOPES[$scope]) || MessageCatalogue::isUnsubscribable($scope);
    }

    private function switchOff(int $userId, string $tenantId, string $scope): void
    {
        $row = DB::table('notification_preferences')
            ->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->whereNull('client_workspace_id')->first();

        $digest = $scope === self::ALERTS ? 'alerts' : ($scope === self::SPEND ? 'spend_summary' : (self::PROJECT_SCOPES[$scope] ?? MessageCatalogue::DIGEST_SWITCH[$scope] ?? null));
        $column = $digest !== null ? 'digests' : 'types';
        $map = json_decode((string) ($row->{$column} ?? '[]'), true);
        $map = is_array($map) ? $map : [];

        if ($digest !== null) {
            $map[$digest] = false;
        } else {
            $map[$scope] = array_merge((array) ($map[$scope] ?? []), ['email' => false]);
        }

        if ($row === null) {
            DB::table('notification_preferences')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                // NOT NULL on the original table. The same first write the settings screen makes.
                'channels' => json_encode(['in_app' => true, 'email' => true]),
                'categories' => json_encode($this->defaultCategories()),
                $column => json_encode($map),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        } else {
            DB::table('notification_preferences')->where('id', $row->id)->update([$column => json_encode($map), 'updated_at' => Carbon::now()]);
        }

        $this->choices->forget();
    }

    /** @return array<string, array{in_app: bool, email: bool}> */
    private function defaultCategories(): array
    {
        $out = [];
        foreach (['budget', 'performance', 'sync', 'token', 'reports', 'security'] as $category) {
            $out[$category] = ['in_app' => true, 'email' => $category !== 'performance'];
        }

        return $out;
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function sign(string $payload): string
    {
        $key = hash_hmac('sha256', 'mail-unsubscribe', (string) config('app.key'));

        return substr(hash_hmac('sha256', $payload, $key), 0, 40);
    }
}
