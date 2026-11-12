<?php

declare(strict_types=1);

namespace App\Domains\Billing\Console;

use App\Domains\Billing\Models\PaymentWebhookEvent;
use App\Domains\Billing\Support\WebhookPayloadRedactor;
use Illuminate\Console\Command;

/**
 * PAY-WEBHOOK-SECRET-REDACT-001 — remove the webhook secret from ledger rows stored before the redaction existed.
 *
 * DRY RUN by default: it reads every `payment_webhook_events` row and prints counts only — how many rows there are,
 * how many still carry a credential, per provider. Nothing is written without `--apply`, and `--apply` is an
 * Owner-approved, one-off step on Production. It only replaces the credential's value with `[redacted]` inside
 * `payload`; every other field, the row itself, its tenant, its status and its ids are untouched. No value is
 * printed, ever.
 */
final class RedactWebhookSecretsCommand extends Command
{
    protected $signature = 'billing:redact-webhook-secrets {--apply : Write the redaction. Without it nothing changes.}';

    protected $description = 'Dry run by default: count (and with --apply, redact) webhook secrets stored in payment_webhook_events.payload.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $total = 0;
        $carrying = [];
        $redacted = 0;

        PaymentWebhookEvent::query()->withoutGlobalScopes()->orderBy('id')
            ->chunkById(200, function ($rows) use ($apply, &$total, &$carrying, &$redacted): void {
                foreach ($rows as $row) {
                    $total++;
                    $payload = $row->payload;

                    if (! WebhookPayloadRedactor::carriesSecret($payload)) {
                        continue;
                    }

                    $provider = (string) $row->provider;
                    $carrying[$provider] = ($carrying[$provider] ?? 0) + 1;

                    if ($apply && is_array($payload)) {
                        $clean = WebhookPayloadRedactor::redact($payload);
                        if (isset($clean['raw']) && is_string($clean['raw'])) {
                            $clean['raw'] = WebhookPayloadRedactor::redactText($clean['raw']);
                        }
                        $row->forceFill(['payload' => $clean])->saveQuietly();
                        $redacted++;
                    }
                }
            });

        ksort($carrying);
        $this->line('mode: '.($apply ? 'apply' : 'dry-run'));
        $this->line('rows: '.$total);
        $this->line('rows carrying a secret: '.array_sum($carrying));
        foreach ($carrying as $provider => $count) {
            $this->line("  {$provider}: {$count}");
        }
        $this->line('rows redacted: '.$redacted);

        return self::SUCCESS;
    }
}
