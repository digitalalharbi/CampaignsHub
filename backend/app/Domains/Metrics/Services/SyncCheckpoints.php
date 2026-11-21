<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Metrics\Models\MetricSyncCheckpoint;
use App\Domains\Metrics\Models\MetricSyncRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * INTEG-SYNC-ENGINE-001 — date-window checkpoints per binding and grain.
 *
 * ## What moves one
 *
 * `advance()` is called from inside the write's own `whileBound()` transaction, and only by a grain whose whole window landed: the
 * provider answered every page, every row was placed, no metric was refused, no money was withheld. So rows and checkpoint commit
 * together or not at all, and a detach (which waits on that transaction) cannot leave a checkpoint over a refused write.
 *
 * Grains move independently. An ad-set failure leaves `ad_set` where it was while `campaign` advances, and the run is still partial.
 *
 * ## Why a date window and never a page cursor
 *
 * No provider offers an incremental cursor (the capability registry says `incremental_cursor=no` for all). The connectors do page with
 * cursors, but resuming mid-window is not safe here: pages are buffered and written all at once, so there is no half-window to resume
 * into; Meta's `paging.next` is an absolute URL carrying the access token, so storing it stores a secret; and Snapchat's `next_link`
 * is good for that response only. Hence no cursor column.
 *
 * ## What a checkpoint changes
 *
 * Only a SCHEDULED sweep consults it, and only when the sweep's window would leave a hole: the checkpoint's `to` is older than the
 * day before the window starts. The sweep then starts from the checkpoint less the restatement window (`incremental.restate_days`).
 * A healthy account asks for exactly what it asked for before. A person's window is theirs.
 *
 * Two brakes, because a grain can be stuck for reasons a re-read does not cure (a campaign never discovered, a currency unknown):
 * a wide read is tried once per cooldown unless it failed outright, and it never reaches further than `resume_max_days`. Days a capped
 * resume skips are written on the checkpoint, and `integrations:resync-window` is how a person reads them.
 */
final class SyncCheckpoints
{
    public const CAMPAIGN = 'campaign';

    public const AD_SET = 'ad_set';

    public const AD = 'ad';

    public const CREATIVE = 'creative';

    /** Record a grain's window as landed. Must be called inside the transaction that wrote its rows. */
    public function advance(ExternalAccount $account, string $projectId, string $grain, Carbon $from, Carbon $to, MetricSyncRun $run): void
    {
        $now = Carbon::now();
        $window = [
            'window_from' => $from->toDateString(),
            'window_to' => $to->toDateString(),
            'completed_at' => $now,
            'metric_sync_run_id' => $run->getKey(),
        ];

        $inserted = DB::table('metric_sync_checkpoints')->insertOrIgnore($window + [
            // Not `Str::uuid()`: a checkpoint is bookkeeping beside the figures and must not draw from the id sequence their rows use.
            'id' => Uuid::uuid4()->toString(),
            'tenant_id' => $account->tenant_id,
            'project_id' => $projectId,
            'external_account_id' => $account->getKey(),
            'provider' => $account->provider,
            'grain' => $grain,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted > 0) {
            return;
        }

        $row = $this->rows($account, $projectId)->where('grain', $grain)->lockForUpdate()->firstOrFail();
        $held = $row->window_to->toDateString();

        // An older window read again says nothing about how far the account has landed.
        if ($to->toDateString() < $held) {
            return;
        }

        $firstUnread = $row->window_to->copy()->addDay()->toDateString();
        $hole = $from->toDateString() > $firstUnread;
        $capped = ((array) ($run->meta ?? []))['resume']['clamped'] ?? false;

        // A later window that leaves days unread does not claim them. Only a capped resume moves past a hole, and it says so.
        if ($hole && $capped !== true) {
            return;
        }

        $row->forceFill($window + ($hole ? [
            'unread_from' => $firstUnread,
            'unread_to' => $from->copy()->subDay()->toDateString(),
        ] : []))->save();
    }

    /**
     * Where a scheduled sweep should start for this binding, when its own window would leave a hole; null when it would not.
     *
     * @return array{requested_from:string,resumed_from:string,clamped:bool}|null
     */
    public function resumePoint(ExternalAccount $account, string $projectId, Carbon $from): ?array
    {
        $rows = $this->rows($account, $projectId)->get();

        if ($rows->isEmpty()) {
            return null;
        }

        /** @var MetricSyncCheckpoint $behind */
        $behind = $rows->sortBy(static fn (MetricSyncCheckpoint $r): string => $r->window_to->toDateString())->first();

        if ($behind->window_to->copy()->addDay()->toDateString() >= $from->toDateString()) {
            return null;
        }

        $cooldown = Carbon::now()->subHours(max(0, (int) config('integrations.checkpoints.resume_cooldown_hours', 24)));
        if ($rows->contains(static fn (MetricSyncCheckpoint $r): bool => $r->resume_attempted_at !== null && $r->resume_attempted_at->gt($cooldown))) {
            return null;
        }

        $target = $behind->window_to->copy()->subDays(max(0, (int) config('integrations.incremental.restate_days', 7)));
        $floor = Carbon::now()->startOfDay()->subDays(max(1, (int) config('integrations.checkpoints.resume_max_days', 35)));
        $clamped = $target->toDateString() < $floor->toDateString();
        $resumed = $clamped ? $floor : $target;

        if ($resumed->toDateString() >= $from->toDateString()) {
            return null;
        }

        $this->rows($account, $projectId)->update(['resume_attempted_at' => Carbon::now()]);

        return [
            'requested_from' => $from->toDateString(),
            'resumed_from' => $resumed->toDateString(),
            'clamped' => $clamped,
        ];
    }

    /** The wide read never reached the provider's answer, so it proved nothing about this grain: the next sweep may try again. */
    public function forgetResumeAttempt(ExternalAccount $account, string $projectId): void
    {
        $this->rows($account, $projectId)->update(['resume_attempted_at' => null]);
    }

    /** @return Builder<MetricSyncCheckpoint> */
    private function rows(ExternalAccount $account, string $projectId)
    {
        return MetricSyncCheckpoint::withoutGlobalScopes()
            ->where('external_account_id', $account->getKey())
            ->where('project_id', $projectId);
    }
}
