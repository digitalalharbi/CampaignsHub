<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Forms\Services\FormDataRetention;
use App\Domains\Ops\Services\ScheduledRunRows;
use Illuminate\Console\Command;

/** FORMS-PDPL-001 — anonymise the evidence around form submissions that has outlived its form's retention. Consent proof is never touched. */
final class PurgeFormEvidenceCommand extends Command
{
    protected $signature = 'forms:purge-evidence';

    protected $description = 'Erase attribution, session ids, funnel events and delivery payloads past each form\'s retention; keep the consent record.';

    public function handle(FormDataRetention $retention): int
    {
        $r = $retention->purgeExpired();
        // What the ops page shows beside «succeeded»: how many rows this run actually changed.
        app(ScheduledRunRows::class)->report($r['submissions'] + $r['events'] + $r['deliveries']);
        $this->info("Submissions anonymised: {$r['submissions']} · events deleted: {$r['events']} · delivery payloads cleared: {$r['deliveries']}");

        return self::SUCCESS;
    }
}
