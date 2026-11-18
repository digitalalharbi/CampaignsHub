<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Console;

use App\Domains\Notifications\Push\PushDispatcher;
use App\Domains\Ops\Services\ScheduledRunRows;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * NOTIF-PUSH-001 — the ONE summary for pushes that waited through quiet hours (or beyond the hourly ceiling).
 *
 * Every ten minutes; a person whose quiet window is still open is skipped and asked again next time. Each person gets at most one
 * summary per run, however many pushes waited, and nothing about what they were (no campaign, no amount) — the bell has the detail.
 * Also prunes the hourly ledger, which only ever needs the last hour.
 */
final class FlushDeferredPush extends Command
{
    protected $signature = 'notifications:flush-deferred-push';

    protected $description = 'Send one summary push to each person whose held pushes may now go out.';

    public function handle(PushDispatcher $push): int
    {
        DB::table('push_ledger')->where('sent_at', '<', now()->subDay())->delete();

        $summaries = 0;
        DB::table('push_deferred')->select('user_id', 'tenant_id')->distinct()->get()->each(function (object $row) use ($push, &$summaries): void {
            $user = User::query()->find($row->user_id);
            if ($user === null) {
                return;
            }
            $summaries += $push->flushFor($user, (string) $row->tenant_id)['sent'] > 0 ? 1 : 0;
        });

        app(ScheduledRunRows::class)->report($summaries);
        $this->info("summaries sent: {$summaries}");

        return self::SUCCESS;
    }
}
