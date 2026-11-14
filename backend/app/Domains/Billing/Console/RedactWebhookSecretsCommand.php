<?php

declare(strict_types=1);

namespace App\Domains\Billing\Console;

use App\Domains\Billing\Models\PaymentAttempt;
use App\Domains\Billing\Models\PaymentWebhookEvent;
use App\Domains\Billing\Support\WebhookPayloadRedactor;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * PAY-WEBHOOK-SECRET-REDACT-001 — remove the webhook secret from rows stored before the redaction existed.
 *
 * Two places held it: `payment_webhook_events.payload` (the received body) and `payment_attempts.provider_response`
 * (the verified result, which carried that body). DRY RUN by default: it reads every row of both and prints counts
 * only — rows, rows still carrying a credential, per provider or status. Nothing is written without `--apply`, and
 * `--apply` is an Owner-approved, one-off step on Production. It replaces only the credential's value with
 * `[redacted]` inside that one column (and `updated_at` moves where the table has it); the row, its tenant, status
 * and ids are untouched. No value is ever printed.
 */
final class RedactWebhookSecretsCommand extends Command
{
    protected $signature = 'billing:redact-webhook-secrets {--apply : Write the redaction. Without it nothing changes.}';

    protected $description = 'Dry run by default: count (and with --apply, redact) webhook secrets stored in payment_webhook_events and payment_attempts.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $this->line('mode: '.($apply ? 'apply' : 'dry-run'));

        $this->sweep('payment_webhook_events', PaymentWebhookEvent::class, 'payload', 'provider', $apply);
        $this->sweep('payment_attempts', PaymentAttempt::class, 'provider_response', 'status', $apply);

        return self::SUCCESS;
    }

    /** @param  class-string<Model>  $model */
    private function sweep(string $table, string $model, string $column, string $groupBy, bool $apply): void
    {
        $total = 0;
        $carrying = [];
        $redacted = 0;

        $model::query()->withoutGlobalScopes()->chunkById(200, function ($rows) use ($column, $groupBy, $apply, &$total, &$carrying, &$redacted): void {
            foreach ($rows as $row) {
                $total++;
                $value = $row->getAttribute($column);

                if (! WebhookPayloadRedactor::carriesSecret($value)) {
                    continue;
                }

                $group = (string) $row->getAttribute($groupBy);
                $carrying[$group] = ($carrying[$group] ?? 0) + 1;

                if ($apply && is_array($value)) {
                    $clean = WebhookPayloadRedactor::redact($value);
                    if (isset($clean['raw']) && is_string($clean['raw'])) {
                        $clean['raw'] = WebhookPayloadRedactor::redactText($clean['raw']);
                    }
                    $row->forceFill([$column => $clean])->saveQuietly();
                    $redacted++;
                }
            }
        });

        ksort($carrying);
        $this->line("{$table} rows: {$total}");
        $this->line("{$table} rows carrying a secret: ".array_sum($carrying));
        foreach ($carrying as $group => $count) {
            $this->line("  {$group}: {$count}");
        }
        $this->line("{$table} rows redacted: {$redacted}");
    }
}
