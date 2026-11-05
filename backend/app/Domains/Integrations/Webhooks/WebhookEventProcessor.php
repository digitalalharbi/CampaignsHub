<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Webhooks;

use App\Domains\Commerce\Install\SallaInstalls;
use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Catalogue\ProviderKind;
use App\Domains\Integrations\Jobs\ProcessWebhookEventJob;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationWebhookEvent;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Integrations\Support\ProviderErrorText;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * INTEG-WEBHOOK-ENGINE-001 — what a recorded delivery is allowed to DO, and the honest state it ends in.
 *
 * ## «Processed» is written by the worker, never by the HTTP answer
 *
 * The receiver answers 2xx as soon as the row is safe (a provider retries anything else for hours), but
 * that answer says «recorded», not «worked». The row moves `received → queued → processed` only when the
 * sync it asked for actually ran without failing. A failed attempt leaves `failed` and the queue retries
 * with backoff, bounded; the last failure ends in `dead_letter`, which only an operator's replay moves.
 *
 * ## A delivery changes nothing it is not bound to
 *
 * The account named in the payload is resolved to every tenant's copy, and only copies with an active
 * project binding are worked. A store the merchant owns but nobody selected for a project is
 * `ignored_unbound`: no fetch, no write, no queue entry for its own sake.
 *
 * ## An older event never overrides a newer one
 *
 * The payload is never written as data (see `IntegrationWebhookController`): the worker re-reads the
 * store, which cannot go backwards. What ordering adds is that a LATE event for an order whose newer
 * event was already worked is `superseded` and fetches nothing, so a stale provider cache answered to a
 * late event cannot overwrite what the newer one just established.
 *
 * ## A burst is one read
 *
 * A busy store sends dozens of events a minute. A sync that STARTED after an event arrived already read
 * everything that event describes, so such an event is covered by it rather than syncing again. This is
 * the same protection the sync jobs' unique lock gave the old direct dispatch, kept without losing the
 * event's own outcome.
 */
final class WebhookEventProcessor
{
    public function __construct(
        private readonly WebhookIngest $ingest,
        private readonly AccountAssignment $assignment,
        private readonly WebhookSyncRunner $runner,
        private readonly TenantContext $tenant,
    ) {}

    /** Hand a recorded delivery to the queue. */
    public function enqueue(IntegrationWebhookEvent $event): void
    {
        $event->forceFill(['status' => IntegrationWebhookEvent::QUEUED])->save();

        ProcessWebhookEventJob::dispatch((string) $event->getKey());
    }

    /** Salla's app lifecycle (install, uninstall, subscription) is handled where it always was, not by a sync. */
    public function isLifecycle(IntegrationWebhookEvent $event): bool
    {
        return $event->provider === 'salla' && str_starts_with((string) $event->topic, 'app.');
    }

    /**
     * SALLA-INSTALL-001 — `app.store.authorize` hands over (or renews) a merchant's tokens, `app.uninstalled`
     * withdraws the app. Moved here unchanged; what is new is that a handler that throws leaves `failed`
     * on the row instead of a 500 with nothing recorded about why.
     *
     * @param  array<string,mixed>  $payload
     */
    public function runLifecycle(IntegrationWebhookEvent $event, array $payload): bool
    {
        try {
            match ((string) ($payload['event'] ?? '')) {
                'app.store.authorize' => app(SallaInstalls::class)->onAuthorize($payload),
                'app.installed' => app(SallaInstalls::class)->onInstalled($payload),
                'app.uninstalled' => app(SallaInstalls::class)->onUninstall($payload),
                'app.subscription.started', 'app.subscription.renewed', 'app.subscription.expired', 'app.subscription.canceled' => app(SallaInstalls::class)->onSubscription($payload, (string) $payload['event']),
                default => null,
            };
        } catch (Throwable $e) {
            $event->forceFill([
                'status' => IntegrationWebhookEvent::FAILED,
                'attempts' => $event->attempts + 1,
                'error' => ProviderErrorText::forStorage(class_basename($e).': '.$e->getMessage()),
            ])->save();
            Log::error('webhook.lifecycle_failed', ['event' => $event->getKey(), 'exception' => class_basename($e)]);

            return false;
        }

        $event->forceFill(['status' => IntegrationWebhookEvent::PROCESSED, 'processed_at' => Carbon::now(), 'error' => null])->save();

        return true;
    }

    /**
     * One processing attempt. Throws when the attempt failed so the queue retries it; returns quietly
     * for every terminal outcome, including «nothing to do».
     */
    public function process(string $eventId): void
    {
        $event = IntegrationWebhookEvent::query()->find($eventId);

        // Only a queued (or retrying) delivery is worked. A processed one is a no-op, which is what makes
        // a second replay, a double dispatch and a worker restart all harmless.
        if ($event === null || ! in_array($event->status, [IntegrationWebhookEvent::QUEUED, IntegrationWebhookEvent::FAILED], true)) {
            return;
        }

        $event->forceFill(['attempts' => $event->attempts + 1])->save();

        /** @var array<string,mixed> $payload */
        $payload = is_array($event->payload) ? $event->payload : [];
        $definition = ProviderCatalogue::get($event->provider);

        $active = $this->ingest->accountsFor($event->provider, $payload)
            ->filter(fn (ExternalAccount $a): bool => $this->assignment->isActivelyAssigned($a))
            ->values();

        if ($active->isEmpty()) {
            $this->finish($event, IntegrationWebhookEvent::IGNORED_UNBOUND, 'No active project assignment for this account — nothing was fetched.');

            return;
        }

        if ($this->superseded($event)) {
            $this->finish($event, IntegrationWebhookEvent::SUPERSEDED, 'A newer event for the same entity was already worked — nothing was fetched.');

            return;
        }

        $failures = [];

        foreach ($active as $account) {
            if ($this->covered($account, $event)) {
                continue;
            }

            $startedAt = Carbon::now();
            $failure = $this->syncOne($account, $definition->kind, $event);

            if ($failure !== null) {
                $failures[] = $failure;

                continue;
            }

            Cache::put($this->coverKey($account), (int) $startedAt->valueOf(), Carbon::now()->addHours(2));
        }

        if ($failures !== []) {
            $message = ProviderErrorText::forStorage(implode(' ', $failures)) ?? 'The sync failed.';
            $event->forceFill(['status' => IntegrationWebhookEvent::FAILED, 'error' => $message])->save();

            throw new RuntimeException($message);
        }

        $this->finish($event, IntegrationWebhookEvent::PROCESSED, null);
    }

    /** The queue gave up. Never silent: the row says so and the log carries the id. */
    public function deadLetter(string $eventId, string $message): void
    {
        $event = IntegrationWebhookEvent::query()->find($eventId);

        if ($event === null || $event->status === IntegrationWebhookEvent::PROCESSED) {
            return;
        }

        $event->forceFill([
            'status' => IntegrationWebhookEvent::DEAD_LETTER,
            'dead_lettered_at' => Carbon::now(),
            'error' => ProviderErrorText::forStorage($message),
        ])->save();

        Log::error('webhook.dead_letter', ['event' => $eventId, 'provider' => $event->provider, 'attempts' => $event->attempts]);
    }

    private function finish(IntegrationWebhookEvent $event, string $status, ?string $note): void
    {
        $event->forceFill(['status' => $status, 'processed_at' => Carbon::now(), 'error' => $note])->save();
    }

    /** @return ?string a failure sentence, or null when the sync did not fail */
    private function syncOne(ExternalAccount $account, ProviderKind $kind, IntegrationWebhookEvent $event): ?string
    {
        $previous = $this->tenant->hasTenant() ? $this->tenant->tenantId() : null;

        try {
            $this->tenant->setTenantId($account->tenant_id);
            $run = $this->runner->run($account, $kind, $event);
        } catch (Throwable $e) {
            return class_basename($e).': '.$e->getMessage();
        } finally {
            $this->tenant->setTenantId($previous);
        }

        if ($run === null) {
            return null;
        }

        $status = $run->status instanceof \BackedEnum ? $run->status->value : (string) $run->status;

        return $status === 'failed' ? 'The sync failed: '.($run->error ?? 'no reason recorded.') : null;
    }

    private function superseded(IntegrationWebhookEvent $event): bool
    {
        if ($event->entity_key === null || $event->event_time === null) {
            return false;
        }

        return IntegrationWebhookEvent::query()
            ->where('provider', $event->provider)
            ->where('entity_key', $event->entity_key)
            ->where('id', '!=', $event->getKey())
            ->where('status', IntegrationWebhookEvent::PROCESSED)
            ->where('event_time', '>', $event->event_time)
            ->exists();
    }

    private function covered(ExternalAccount $account, IntegrationWebhookEvent $event): bool
    {
        $stamp = Cache::get($this->coverKey($account));

        return is_numeric($stamp) && (int) $stamp >= (int) Carbon::parse($event->received_at)->valueOf();
    }

    private function coverKey(ExternalAccount $account): string
    {
        return 'webhook-sync-started:'.$account->getKey();
    }
}
