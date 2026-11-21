<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use App\Domains\Integrations\Webhooks\WebhookReconciler;
use App\Domains\Ops\Services\ScheduledRunRows;
use Illuminate\Console\Command;

/** INTEG-WEBHOOK-ENGINE-001 — compare recent store orders with what webhooks and sweeps delivered. */
final class ReconcileWebhooksCommand extends Command
{
    protected $signature = 'webhooks:reconcile
        {--days= : How many days of orders to read (default from config, max 14)}
        {--stores= : At most this many bound stores per run (default from config)}
        {--provider= : Limit to one webhook-delivering store platform}
        {--dry-run : Report the gaps without recording them}';

    protected $description = 'Read recent orders from each bound store and record where they differ from what we hold. Read-only toward the provider.';

    public function handle(WebhookReconciler $reconciler): int
    {
        $result = $reconciler->run(
            (int) ($this->option('days') ?: config('integrations.webhooks.reconcile.days', 3)),
            (int) ($this->option('stores') ?: config('integrations.webhooks.reconcile.max_stores', 20)),
            (bool) $this->option('dry-run'),
            $this->option('provider') ?: null,
        );

        app(ScheduledRunRows::class)->report(count($result['gaps']));

        $this->info(sprintf(
            '%d store(s), %d order(s) read, %d gap(s)%s.',
            $result['stores'], $result['orders_read'], count($result['gaps']), $result['dry_run'] ? ' (dry run: nothing recorded)' : ' recorded',
        ));

        foreach ($result['gaps'] as $gap) {
            $this->line(sprintf(
                '  %s order %s: provider=%s local=%s webhook_seen=%s',
                $gap['gap'], $gap['order_id'], $gap['provider_status'], $gap['local_status'] ?? '-', $gap['inbox_seen'] ? 'yes' : 'no',
            ));
        }

        foreach ($result['skipped'] as $why) {
            $this->warn('  skipped '.$why);
        }

        return self::SUCCESS;
    }
}
