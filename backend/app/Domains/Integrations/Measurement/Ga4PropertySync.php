<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Measurement;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Integrations\Support\ProviderErrorText;
use App\Domains\Metrics\Enums\SyncRunStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * GA4-INTEGRATION-001 — pulling a SELECTED property's days into the product.
 *
 * Only a property bound to a project is read. Everything else discovery found is left alone, which is
 * the whole of «DISCOVERED ≠ SELECTED»: an agency identity reaching forty properties syncs the one
 * somebody chose, and the other thirty-nine cost nothing and appear nowhere.
 *
 * ## The window, and why it overlaps
 *
 * GA4 restates a day for up to 48 hours as late events arrive, and a property can also be configured
 * to process data with a delay. A sync that fetched only yesterday would freeze every day at its
 * first, incomplete value — the figure would be wrong, stable, and indistinguishable from right.
 * So each run re-asks a window and upserts; the unique key on
 * (`external_account_id`, `metric_date`, `metric_key`) is what makes a second run an update.
 *
 * ## The window is computed in the PROPERTY's timezone
 *
 * «Today» on the server is not «today» on a property set to `Asia/Riyadh` or `America/Los_Angeles`.
 * Asking GA4 for a server-local date returns a day the client's own Analytics screen does not have,
 * and the two then disagree for ever. The timezone is read from the property and recorded on every
 * row, so a figure can always be reconciled with the calendar that produced it.
 */
final class Ga4PropertySync
{
    /**
     * How far back each run re-asks.
     *
     * Three days rather than one: GA4's restatement window is up to 48 hours, and a run that happens
     * to fire early in the property's day would otherwise re-ask a window that does not reach back
     * over the whole of it.
     */
    public const RESTATEMENT_DAYS = 3;

    public function __construct(
        private readonly Ga4Reporting $reporting,
        private readonly AccountAssignment $assignment,
    ) {}

    /**
     * Sync one property. Returns what was written, for the caller to report honestly.
     *
     * @param  int|null  $days  how far back to ask; null means the restatement window
     * @return array{property_id: string, project_id: string, timezone: string, from: string, to: string, days: int, figures: int}
     *
     * @throws Ga4NotSelected when the property is not bound to a project
     * @throws Ga4ReportFailed when Google refuses
     */
    public function sync(ExternalAccount $account, ?int $days = null): array
    {
        if ($account->account_type !== Ga4PropertyDiscovery::ACCOUNT_TYPE) {
            throw new Ga4NotSelected('That account is not an Analytics property.');
        }

        /*
         * GA4-INTEGRATION-001 — every read is RECORDED, through the run the product already has.
         *
         * «History» and «freshness» were listed as pieces of this integration and neither existed:
         * a property carried `last_synced_at` and nothing else, so «it says it synced two hours ago
         * and the figures look wrong» had no answer — not how many figures arrived, not whether the
         * last attempt failed, not whether the one before it did.
         *
         * `IntegrationSyncRun` with `type = 'measurement'`, not a table of our own. The commerce
         * sweep writes `commerce` into the same table and the ad sweeps write theirs; a second
         * history for a third family would be a second place for «did this run» to be answered, and
         * the ops surfaces would have to learn about it one at a time.
         *
         * Opened BEFORE the first provider call, so a read that dies mid-flight leaves a `running`
         * row the abandoned-run sweep can close, rather than leaving no trace at all.
         */
        $run = new IntegrationSyncRun;
        $run->forceFill([
            'tenant_id' => $account->tenant_id,
            'provider_connection_id' => $account->provider_connection_id,
            'type' => 'measurement',
            'status' => SyncRunStatus::Running->value,
            'started_at' => Carbon::now(),
        ])->save();

        $projectId = $this->assignment->projectIdFor($account);

        if ($projectId === null) {
            /*
             * `awaiting_assignment`, the same word the commerce sweep uses for the same situation:
             * nothing broke, and the next move is to choose a project. Recording it as `failed`
             * would put a red row on the ops page for an estate that is merely unselected — which
             * is the NORMAL state for most of an agency's discovered properties.
             */
            $this->finish($run, SyncRunStatus::AwaitingAssignment, 0, 'This property is not selected for a project yet, so nothing was read.');

            /*
             * Refused, not skipped silently.
             *
             * A discovered property with no binding is the NORMAL state for most of an agency's
             * estate, and syncing it would both spend the client's Data API quota and file their
             * site's traffic under no project — which is to say under whichever project read the
             * table next. The caller is told so it can say «اختر المشروع أولًا» rather than
             * reporting a successful sync of nothing.
             */
            throw new Ga4NotSelected('This property is not connected to a project yet. Select it for a project first.');
        }

        $connection = ProviderConnection::withoutGlobalScopes()->findOrFail($account->provider_connection_id);

        try {
            $settings = $this->reporting->settings($connection, (string) $account->external_id);
        } catch (Ga4ReportFailed $e) {
            /*
             * A refusal is a recorded FAILURE, and then it is re-thrown.
             *
             * Swallowing it here would turn «Google refused» into a quiet no-op, which is the one
             * thing this integration refuses to do everywhere else. The caller still decides what
             * the reader is told; the run is what remembers it happened.
             */
            $this->finish($run, SyncRunStatus::Failed, 0, $e->getMessage());

            throw $e;
        }

        /*
         * The property's own settings are written back before the figures are read.
         *
         * They are facts about the source, and a reader looking at a stored figure needs to be able
         * to see the calendar and the currency it was measured in without a second API call. They
         * also change — a client can move a property's timezone — and the stored rows keep saying
         * which one applied when they were written.
         */
        $account->forceFill([
            'timezone' => $settings['timezone'],
            'currency' => $settings['currency'],
            'last_sync_attempt_at' => Carbon::now(),
        ])->save();

        $to = Carbon::now($settings['timezone']);
        $from = $to->copy()->subDays(max(1, $days ?? self::RESTATEMENT_DAYS) - 1);

        try {
            $rows = $this->reporting->dailyRows($connection, (string) $account->external_id, $from, $to);
        } catch (Ga4ReportFailed $e) {
            $this->finish($run, SyncRunStatus::Failed, 0, $e->getMessage());

            throw $e;
        }

        $written = $this->store($account, $projectId, $settings, $rows);

        $account->forceFill(['last_synced_at' => Carbon::now(), 'last_sync_error_category' => null])->save();

        /*
         * `no_data` when the property answered with nothing, and that is not a failure.
         *
         * A site with no traffic in the window is a true answer, and colouring it red would send
         * somebody to fix an integration that is working. The same distinction the ad and commerce
         * sweeps make, in the same word.
         */
        $this->finish(
            $run,
            $written === 0 ? SyncRunStatus::NoData : SyncRunStatus::Success,
            $written,
            null,
            [
                'project_id' => $projectId,
                'property_id' => (string) $account->external_id,
                'timezone' => $settings['timezone'],
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'days' => count($rows),
            ],
        );

        return [
            'property_id' => (string) $account->external_id,
            'project_id' => $projectId,
            'timezone' => $settings['timezone'],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => count($rows),
            'figures' => $written,
        ];
    }

    /**
     * Close a run with its verdict, and never leave one `running`.
     *
     * `records` is the count of FIGURES written, not of days: a day with no ecommerce configured
     * legitimately carries fewer, and counting days would report «14» for a window that produced
     * almost nothing.
     *
     * @param  array<string, mixed>  $meta
     */
    private function finish(IntegrationSyncRun $run, SyncRunStatus $status, int $records, ?string $error = null, array $meta = []): void
    {
        $run->forceFill([
            'status' => $status->value,
            'records' => $records,
            /*
             * Redacted before it is stored. A Google failure message can name the URL that failed,
             * query string and all, and an access token has been seen in one — and this column is
             * read back onto an operator's screen.
             */
            /*
             * Bounded by the contract, never here. Five call sites once carried five different limits and
             * the column threw on the largest; `ProviderErrorContractTest` refuses a trim at the call site,
             * and `forStorage` is the one bound that knows the column and says when it fired.
             */
            'error' => $error === null ? null : ProviderErrorText::forStorage($error),
            'meta' => $meta === [] ? null : $meta,
            'finished_at' => Carbon::now(),
        ])->save();
    }

    /**
     * Write the days down, idempotently.
     *
     * @param  array{timezone: string, currency: ?string, display_name: string}  $settings
     * @param  list<array{date: string, values: array<string, float>}>  $rows
     * @return int how many figures were written — not how many days, because a day with no ecommerce
     *             configured legitimately carries fewer
     */
    private function store(ExternalAccount $account, string $projectId, array $settings, array $rows): int
    {
        $now = Carbon::now();
        $payload = [];

        foreach ($rows as $row) {
            foreach ($row['values'] as $key => $value) {
                $payload[] = [
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $account->tenant_id,
                    'project_id' => $projectId,
                    'external_account_id' => $account->getKey(),
                    'property_id' => (string) $account->external_id,
                    'metric_date' => $row['date'],
                    'metric_key' => $key,
                    'value' => $value,
                    /*
                     * The currency rides on MONEY keys only. A currency stamped on a session count
                     * would be read by the display layer as «this is money», and an engagement rate
                     * shown as ‏61.00 ر.س is the sort of thing a client screenshots.
                     */
                    'currency' => Ga4Metrics::isMoney($key) ? $settings['currency'] : null,
                    'timezone' => $settings['timezone'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($payload === []) {
            return 0;
        }

        /*
         * Chunked because a backfill of a year is ~2,900 rows per metric and Postgres binds every
         * value in the statement — one upsert of the lot exceeds the parameter limit.
         */
        foreach (array_chunk($payload, 500) as $chunk) {
            DB::table('measurement_daily_metrics')->upsert(
                $chunk,
                ['external_account_id', 'metric_date', 'metric_key'],
                ['value', 'currency', 'timezone', 'project_id', 'property_id', 'updated_at'],
            );
        }

        return count($payload);
    }
}
