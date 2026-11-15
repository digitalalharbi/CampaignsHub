<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Push;

use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Notifications\Support\BellKind;

/**
 * NOTIF-PUSH-001 — which notifications may ring a phone, and under which of the five switches a person controls.
 *
 * Push is rationed on purpose. The bell holds everything; a phone is interrupted only for: a critical finding, a budget at 90%, a
 * budget at 100% or over, a sync failure, and an approval request. Everything else — performance warnings, reports, info — stays in
 * the bell. A budget or sync kind is judged by what it IS (a critical `budget_90` is still the «budget_90» switch), then severity.
 */
final class PushTypes
{
    public const CRITICAL = 'critical';

    public const BUDGET_90 = 'budget_90';

    public const BUDGET_100 = 'budget_100';

    public const SYNC_FAILED = 'sync_failed';

    public const APPROVAL = 'approval';

    /** Catalog event => the bell kind whose push switch governs it. */
    private const ENGINE_KINDS = [
        'budget.threshold_90' => 'budget_90',
        'budget.threshold_100' => 'budget_100',
        'budget.overrun' => 'budget_over',
        'spend_limit.reached' => 'budget_100',
        'sync.failed' => 'sync_failed',
        'connection.expired' => 'sync_failed',
        'connection.expiring' => 'sync_failed',
        'recommendation.awaiting_approval' => 'approval_request',
    ];

    /** @var list<string> */
    public const ALL = [self::CRITICAL, self::BUDGET_90, self::BUDGET_100, self::SYNC_FAILED, self::APPROVAL];

    /** The push type of a notification, or null when it must not be pushed. */
    public static function of(AppNotification $n): ?string
    {
        // NOTIF-V2-INTERNAL-001 — the catalog's event keys stand for the same facts as the bell's older kinds, under the same five switches.
        $kind = self::ENGINE_KINDS[(string) ($n->kind ?? '')] ?? (string) ($n->kind ?? '');
        $type = (string) $n->type;

        if ($type === 'approval_request' || $kind === 'approval_request' || str_contains($type, 'approval')) {
            return self::APPROVAL;
        }
        if ($kind === 'budget_90') {
            return self::BUDGET_90;
        }
        if ($kind === 'budget_100' || $kind === 'budget_over') {
            return self::BUDGET_100;
        }
        if ($kind === 'sync_failed' && $n->severity !== 'info') {
            return self::SYNC_FAILED;
        }
        if (in_array((string) $n->severity, ['critical', 'error'], true) && $kind !== 'recovered') {
            return self::CRITICAL;
        }

        return null;
    }

    /** The section a person must still hold for this push (a section switched off for them is refused), or null. */
    public static function section(AppNotification $n): ?string
    {
        return match (BellKind::group($n->kind)) {
            'budget' => 'budgets',
            'sync' => 'integrations',
            'performance' => 'campaigns',
            default => null,
        };
    }

    /** Budget figures are money: a member whose membership withholds money on the project is not pushed one. */
    public static function isMoney(AppNotification $n): bool
    {
        return BellKind::group($n->kind) === 'budget';
    }

    /**
     * What a person gets when they have chosen nothing, merged with what they chose.
     *
     * @param  array<string, mixed>|null  $stored
     * @return array<string, bool>
     */
    public static function chosen(?array $stored): array
    {
        $out = [];
        foreach (self::ALL as $type) {
            $out[$type] = ! is_array($stored) || ! array_key_exists($type, $stored) ? true : $stored[$type] === true;
        }

        return $out;
    }
}
