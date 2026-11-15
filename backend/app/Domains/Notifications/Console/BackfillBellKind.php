<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Console;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Notifications\Support\BellKind;
use Illuminate\Console\Command;

/**
 * NOTIF-BELL-001 — names the notifications written before `kind` existed.
 *
 * Run by hand, once, after the migration: `php artisan notifications:backfill-kind` (add `--dry-run` to count only).
 * The bell does not wait for it — it derives a missing kind from the alert behind the row at read time — but the
 * panel's server-side filters (ميزانية / أداء / مزامنة) read the stored column. It only fills rows whose kind is empty
 * and only from an alert event the row is linked to or the row's own type; it never overwrites a kind.
 */
final class BackfillBellKind extends Command
{
    protected $signature = 'notifications:backfill-kind {--dry-run : count what would be named, change nothing}';

    protected $description = 'Fill the kind of old bell notifications from their alert event.';

    public function handle(): int
    {
        $named = 0;

        AlertEvent::withoutGlobalScopes()->whereNotNull('notification_id')->orderBy('id')->chunkById(500, function ($events) use (&$named): void {
            foreach ($events as $event) {
                $kind = BellKind::forAlert((string) $event->type, (array) ($event->context ?? []));
                if ($kind === null) {
                    continue;
                }
                $query = AppNotification::withoutGlobalScopes()->whereKey($event->notification_id)->whereNull('kind');
                $named += $this->option('dry-run') ? $query->count() : $query->update(['kind' => $kind]);
            }
        });

        foreach (['report_ready', 'report_failed', 'sync_failed', 'token_expiring'] as $type) {
            $kind = BellKind::forType($type);
            $query = AppNotification::withoutGlobalScopes()->where('type', $type)->whereNull('kind');
            $named += $this->option('dry-run') ? $query->count() : $query->update(['kind' => $kind]);
        }

        $this->info(($this->option('dry-run') ? 'would name ' : 'named ').$named.' notification(s)');

        return self::SUCCESS;
    }
}
