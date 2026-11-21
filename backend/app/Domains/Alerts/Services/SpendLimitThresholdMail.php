<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Services;

use App\Domains\Metrics\Models\SpendLimit;
use App\Domains\Notifications\Mail\AlertBundleMail;
use App\Domains\Notifications\Support\AlertMailItem;
use App\Domains\Projects\Lifecycle\LiveProjects;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * BUDGET-LIMIT-ALERTS-001 — the mail that goes with the in-system alert when a spend limit passes 75%, 90% or 100%.
 *
 * It is the standard alert message (`AlertBundleMail` and its card), addressed to the configured address
 * (`budgets.limit_alert_email`, default info@campaignshub.io). The caller sends it ONLY for a threshold the ledger
 * (`spend_limit_events`, unique per limit and threshold) has just recorded, so each threshold of each limit period mails once.
 * A failed send never stops the sweep: it is reported and the in-system alert still stands.
 */
final class SpendLimitThresholdMail
{
    /** @param  array<string, mixed>  $reading  `SpendLimitGovernor::read()` */
    public function send(SpendLimit $limit, array $reading, int $threshold, Carbon $now): bool
    {
        $addresses = array_values(array_filter(array_map('trim', explode(',', (string) config('budgets.limit_alert_email', '')))));
        if ($addresses === [] || ! LiveProjects::isLive((string) $limit->project_id)) {
            return false; // no address, or the project is archived/deleted (PROJECT-LIFECYCLE-001)
        }

        $project = Project::withoutGlobalScopes()->find($limit->project_id);
        $item = [
            'rule_type' => 'internal_spend_limit',
            'notification_type' => 'internal_spend_limit',
            'severity' => $threshold >= 100 ? 'critical' : 'warning',
            'event_id' => 'limit-'.$limit->getKey().'-'.$threshold,
            'triggered_at' => $now->toIso8601String(),
            'project_id' => (string) $limit->project_id,
            'project_name' => (string) ($project->name ?? ''),
            'entity_type' => SpendLimit::class,
            'entity_id' => (string) $limit->getKey(),
            'context' => [
                'threshold' => $threshold,
                'consumed' => $reading['consumed'],
                'limit' => $reading['amount'],
                'currency' => $limit->currency,
                'scope' => $limit->scope->value,
                'projected_exhaustion' => $reading['projected_exhaustion'] ?? null,
            ],
        ];

        try {
            Mail::to($addresses)->send(new AlertBundleMail([AlertMailItem::build($item, true)], 'ar', ''));

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
