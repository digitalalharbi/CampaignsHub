<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Models;

use App\Domains\Projects\Concerns\BelongsToProject;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * One metrics sync attempt for an account/window. Tenant + project scoped. Records status, timing,
 * upsert count and errors so resyncs are idempotent and connector failures are observable.
 */
final class MetricSyncRun extends Model
{
    use BelongsToProject;
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'project_id', 'connection_id', 'external_account_id', 'provider',
        'status', 'window_start', 'window_end', 'metrics_upserted', 'attempts',
        'provider_raw_rows', 'parsed_rows', 'mapped_campaign_rows',
        'queued_at', 'started_at', 'finished_at', 'error', 'meta',
    ];

    protected $casts = [
        'window_start' => 'date',
        'window_end' => 'date',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'metrics_upserted' => 'integer',
        /*
         * INTEG-RUNTIME §7 — nullable integers, and the nullability is the point.
         *
         * A run recorded before these columns existed did not count anything; casting a NULL to 0
         * here would hand every screen a measurement that was never taken.
         */
        'provider_raw_rows' => 'integer',
        'parsed_rows' => 'integer',
        'mapped_campaign_rows' => 'integer',
        'attempts' => 'integer',
        'meta' => 'array',
    ];

    /**
     * What CAUSED this run — INTEG-RUNTIME §9.
     *
     * Derived rather than stored, because every caller already writes its reason into `meta` and a
     * second column would be a second answer to drift from. A sync log that cannot tell «the schedule
     * did this» from «somebody pressed a button» from «we went back and refilled a month» reads as a
     * wall of identical lines, and the customer's first question about any of them is exactly this.
     */
    public function trigger(): string
    {
        $meta = (array) ($this->meta ?? []);

        return match (true) {
            ($meta['backfill'] ?? false) === true => 'backfill',
            ($meta['manual'] ?? false) === true, isset($meta['triggered_by']) => 'manual',
            default => 'automatic',
        };
    }

    /**
     * WHAT ASKED for this run — INTEG-RUNTIME §9, in the five words a sync log needs.
     *
     * {@see self::trigger()} answers the older, coarser question («did a person do this») and is kept
     * for the surfaces built on it. This is the one a reader of a run log is actually asking, because
     * the next move differs for every value: a failed `first_sync` means the setup never worked, a
     * failed `scheduled` means something that used to work stopped, and a failed `reconnect` means
     * the new authorisation is no better than the old one.
     *
     * Derived from the `meta` every dispatcher already writes rather than stored in a column of its
     * own: a second place to say what caused a run is a second place for it to be wrong.
     *
     * `reconnect` outranks `first_sync` deliberately — the first sync after re-authorising is caused
     * BY the reconnect, and reading it as an ordinary first sync loses the only fact that explains it.
     * A run with no `meta` at all predates these flags and was the scheduler, which is what it says.
     */
    public function source(): string
    {
        $meta = (array) ($this->meta ?? []);
        $named = is_string($meta['source'] ?? null) ? $meta['source'] : null;

        return match (true) {
            $named === 'reconnect' => 'reconnect',
            ($meta['first_sync'] ?? false) === true => 'first_sync',
            ($meta['backfill'] ?? false) === true, $named === 'backfill' => 'backfill',
            ($meta['manual'] ?? false) === true, isset($meta['triggered_by']), $named === 'resync' => 'manual',
            default => 'scheduled',
        };
    }

    /**
     * How long this run waited in the queue before a worker began it, or null.
     *
     * Null is «nobody wrote down when it was asked for», which is true of every run older than the
     * `queued_at` column. It is not zero — a zero here would claim a run started the instant it was
     * requested, which is the one thing a queue never does.
     */
    public function waitedSeconds(): ?int
    {
        if ($this->queued_at === null || $this->started_at === null) {
            return null;
        }

        return max(0, (int) $this->queued_at->diffInSeconds($this->started_at));
    }

    /**
     * How long it took, or null while it is still running.
     *
     * ## Why this threw, and why it threw INTERMITTENTLY
     *
     * `diffInSeconds()` returns a FLOAT in Carbon 3 — always, `11.0` for eleven whole seconds — and
     * `max(0, 11.0)` returns that float, against a declared `?int`. So this method raised a
     * `TypeError` for every run that took a measurable amount of time.
     *
     * It did not fail every time because of how `max()` breaks a tie: `max(0, 0.0)` returns the
     * FIRST argument, the int `0`. A run that started and finished inside the same second therefore
     * came back as a clean int, and that is what most of the suite's fake syncs do. The failure rode
     * on how busy the machine was — the same commit passed 2,710 tests on one CI run and failed one
     * assertion on the next with nothing relevant changed between them.
     *
     * The surface it crashes is the sync log, which is the page an operator opens BECAUSE something
     * already looks wrong.
     *
     * Whole seconds throughout: the model's datetime cast stores `Y-m-d H:i:s`, so microseconds are
     * gone long before this is called, and there is no fraction here left to round.
     */
    public function durationSeconds(): ?int
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }

        return (int) max(0, $this->finished_at->diffInSeconds($this->started_at, absolute: true));
    }

    /**
     * One run, in the shape every sync log renders — and there is only one shape, deliberately.
     *
     * `SyncRunController` and `CampaignMetricsController` each built their own dictionary from the
     * same row, so a field added for one log was simply missing from the other and nobody could see
     * it from either file. The four counts are the reason it matters now: a log that shows
     * `metrics_imported` without `provider_rows` beside it re-creates the unreadable zero this whole
     * unit exists to remove.
     *
     * @return array<string,mixed>
     */
    public function logRow(?string $accountName = null, ?string $accountExternalId = null): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'status' => $this->status,
            'trigger' => $this->trigger(),
            // What ASKED for it, and how long it waited before anything began.
            'source' => $this->source(),
            'queued_at' => $this->queued_at?->toIso8601String(),
            'waited_seconds' => $this->waitedSeconds(),
            'account' => $accountName,
            'account_external_id' => $accountExternalId,
            'window_start' => $this->window_start?->toDateString(),
            'window_end' => $this->window_end?->toDateString(),
            // NULL where nothing was measured — see the migration. A 0 here would be a claim.
            'provider_rows' => $this->provider_raw_rows,
            'parsed_rows' => $this->parsed_rows,
            'mapped_rows' => $this->mapped_campaign_rows,
            'metrics_imported' => (int) $this->metrics_upserted,
            'duration_seconds' => $this->durationSeconds(),
            'attempts' => (int) $this->attempts,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'error' => $this->error,
            // Demo runs are labelled, never disguised as production traffic.
            'is_demo' => (bool) $this->is_demo,
        ];
    }
}
