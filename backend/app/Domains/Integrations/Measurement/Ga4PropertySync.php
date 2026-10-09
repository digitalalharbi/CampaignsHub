<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Measurement;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\AccountAssignment;
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

        $projectId = $this->assignment->projectIdFor($account);

        if ($projectId === null) {
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

        $settings = $this->reporting->settings($connection, (string) $account->external_id);

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

        $rows = $this->reporting->dailyRows($connection, (string) $account->external_id, $from, $to);

        $written = $this->store($account, $projectId, $settings, $rows);

        $account->forceFill(['last_synced_at' => Carbon::now(), 'last_sync_error_category' => null])->save();

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
