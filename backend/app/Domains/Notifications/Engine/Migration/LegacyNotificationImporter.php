<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Migration;

use App\Domains\Notifications\Engine\Contracts\SubscriptionLog;
use App\Domains\Notifications\Engine\Enums\SubscriptionLogAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Moves the existing external recipients into the shared model (spec §9 migration rule) — NO deletion, NO email.
 *
 * - every `external_digest_recipients` row → one `notification_external_recipients` row in `pending` (sending paused)
 *   and one subscription carrying its cadence and projects; an inactive legacy row becomes a `paused` subscription;
 * - archived / deleted projects are excluded (counted);
 * - the legacy rows are left exactly as they are; the old sender skips a migrated recipient from then on, so nobody
 *   receives anything until the workspace owner presses «أرسل طلبات التأكيد» and the recipient confirms;
 * - idempotent: `(legacy_source, legacy_id)` is unique, a second run creates nothing;
 * - each move is written to the immutable subscription log (`migrated`).
 *
 * Personal preferences (`notification_preferences`, `notification_alert_settings`) and alert rules (`alert_rules`) are not
 * copied: the engine reads them in place (personal layer and event source). They are counted in the report only.
 */
final class LegacyNotificationImporter
{
    public const SOURCE = 'external_digest_recipients';

    private const PERIOD_FOR_CADENCE = ['daily' => 'yesterday', 'weekly' => 'last_7', 'monthly' => 'last_month'];

    public function __construct(private readonly SubscriptionLog $log) {}

    /** @return array{recipients: int, subscriptions: int, already: int, excluded_archived_projects: int, preferences_in_place: int, alert_rules_in_place: int} */
    public function run(bool $dryRun = false): array
    {
        $out = ['recipients' => 0, 'subscriptions' => 0, 'already' => 0, 'excluded_archived_projects' => 0,
            'preferences_in_place' => DB::table('notification_preferences')->count(),
            'alert_rules_in_place' => DB::table('alert_rules')->whereNull('deleted_at')->count()];

        foreach (DB::table('external_digest_recipients')->orderBy('created_at')->cursor() as $legacy) {
            if (DB::table('notification_external_recipients')->where('legacy_source', self::SOURCE)->where('legacy_id', $legacy->id)->exists()) {
                $out['already']++;

                continue;
            }

            $projects = DB::table('external_digest_recipient_projects as p')->join('projects', 'projects.id', '=', 'p.project_id')
                ->where('p.recipient_id', $legacy->id)->where('p.tenant_id', $legacy->tenant_id)->where('projects.tenant_id', $legacy->tenant_id)
                ->get(['projects.id', 'projects.status', 'projects.deleted_at']);
            $live = $projects->filter(static fn ($p): bool => $p->status !== 'archived' && $p->deleted_at === null)->pluck('id')->map(static fn ($id): string => (string) $id)->values()->all();
            $excluded = $projects->count() - count($live);
            $out['excluded_archived_projects'] += $excluded;

            if ($dryRun) {
                $out['recipients']++;
                $out['subscriptions']++;

                continue;
            }

            DB::transaction(function () use ($legacy, $live, $excluded, &$out): void {
                $now = Carbon::now();
                $existing = DB::table('notification_external_recipients')->where('tenant_id', $legacy->tenant_id)
                    ->whereRaw('lower(email) = ?', [strtolower((string) $legacy->email)])->value('id');
                $recipientId = $existing !== null ? (string) $existing : (string) Str::uuid();

                if ($existing === null) {
                    DB::table('notification_external_recipients')->insert([
                        'id' => $recipientId, 'tenant_id' => $legacy->tenant_id, 'email' => strtolower((string) $legacy->email),
                        'name' => $legacy->name, 'role_key' => 'other', 'language' => 'ar', 'status' => 'pending', 'consent' => 'none',
                        'legacy_source' => self::SOURCE, 'legacy_id' => $legacy->id, 'created_by' => $legacy->created_by,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $out['recipients']++;
                }

                $cadence = in_array($legacy->cadence, ['daily', 'weekly', 'monthly'], true) ? (string) $legacy->cadence : 'weekly';
                $subscriptionId = (string) Str::uuid();
                DB::table('notification_subscriptions')->insert([
                    'id' => $subscriptionId, 'tenant_id' => $legacy->tenant_id, 'recipient_id' => $recipientId,
                    'status' => (bool) $legacy->active ? 'pending' : 'paused', 'template_key' => 'budget_followup',
                    'digest_types' => json_encode(['spend', 'budgets']), 'event_keys' => json_encode([]),
                    'period_key' => self::PERIOD_FOR_CADENCE[$cadence], 'frequency' => $cadence,
                    'weekdays' => $cadence === 'weekly' ? json_encode([0]) : null, 'month_day' => $cadence === 'monthly' ? 1 : null,
                    'send_time' => '08:00', 'legacy_source' => self::SOURCE, 'legacy_id' => $legacy->id, 'created_by' => $legacy->created_by,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                foreach ($live as $projectId) {
                    DB::table('notification_subscription_projects')->insert(['subscription_id' => $subscriptionId, 'project_id' => $projectId, 'tenant_id' => $legacy->tenant_id]);
                }
                $out['subscriptions']++;

                $this->log->record((string) $legacy->tenant_id, SubscriptionLogAction::Migrated, $recipientId, $subscriptionId, [
                    'legacy_source' => self::SOURCE, 'legacy_id' => (string) $legacy->id, 'projects' => count($live),
                    'excluded_archived_projects' => $excluded, 'legacy_active' => (bool) $legacy->active,
                ]);
            });
        }

        return $out;
    }

    /** Whether a legacy external recipient now lives in the shared model (the old sender then leaves it alone). */
    public static function migrated(string $legacyId): bool
    {
        return DB::table('notification_external_recipients')->where('legacy_source', self::SOURCE)->where('legacy_id', $legacyId)->exists();
    }
}
