<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http\Controllers;

use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Catalogue\ProviderDefinition;
use App\Domains\Integrations\Catalogue\ProviderKind;
use App\Domains\Integrations\Catalogue\WebhookSupport;
use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Domains\Integrations\Models\IntegrationWebhookEvent;
use App\Domains\Integrations\Webhooks\WebhookIngest;
use App\Domains\Integrations\Webhooks\WebhookSignature;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * WEBHOOK-001 — the public endpoint a provider POSTs to.
 *
 * ## It trusts nothing about its own request except the signature
 *
 * No session, no tenant header, no CSRF — a provider's server has none of those. Everything that
 * decides where this delivery lands comes out of data we already hold: the account id in the payload
 * is looked up in `external_accounts`, and the tenant comes off that row. A body that could name its
 * own tenant is a body that could name somebody else's.
 *
 * ## Verify, record, acknowledge, THEN work
 *
 * The response is a bare 200 as soon as the row is safely in. Providers have short delivery timeouts
 * and treat a slow response as a failure: doing the sync inside the request means Meta marks the
 * subscription unhealthy and eventually stops sending, which presents as "the integration silently
 * stopped working" weeks later. The actual work is a queued job.
 *
 * ## What a verified delivery is allowed to DO
 *
 * For an advertising provider it triggers a metrics sync for the account it names — that is all. It
 * never writes a figure straight from the payload: a change notification says something CHANGED, not
 * what the number now is, and treating a notification as data is how a dashboard ends up disagreeing
 * with the platform's own reporting. The sync is the same one the scheduler runs, so the webhook only
 * ever makes the product FASTER at noticing, never a second source of truth.
 *
 * Commerce deliveries are recorded and verified here and consumed by the commerce domain; see
 * `docs/RESUME_STATE.md` for what does and does not yet read them.
 */
final class IntegrationWebhookController extends Controller
{
    public function __construct(
        private readonly WebhookSignature $signatures,
        private readonly WebhookIngest $ingest,
        private readonly ProviderConfigurationService $settings,
    ) {}

    /**
     * GET /webhooks/{kind}/{provider} — Meta's one-time subscription handshake.
     *
     * Meta activates a subscription by GETting the URL with a challenge and the verify token the
     * operator typed into its console. Echoing the challenge back proves we control the endpoint. The
     * token is compared with `hash_equals` for the same reason every other comparison here is.
     */
    public function verify(Request $request, string $kind, string $provider): Response
    {
        $definition = $this->definitionOr404($kind, $provider);

        $configured = $this->settings->value($definition->key, 'webhook_verify_token');
        $offered = (string) $request->query('hub_verify_token', (string) $request->query('hub.verify_token', ''));

        if ($configured === null || $offered === '' || ! hash_equals($configured, $offered)) {
            return response('', 403);
        }

        $challenge = (string) $request->query('hub_challenge', (string) $request->query('hub.challenge', ''));

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    /** POST /webhooks/{kind}/{provider} */
    public function receive(Request $request, string $kind, string $provider): Response
    {
        $definition = $this->definitionOr404($kind, $provider);

        // A provider the operator took out of service stops being listened to as well as stops being
        // connectable. Otherwise a suspended integration keeps feeding the funnel it was suspended from.
        if (! $this->settings->isEnabled($definition->key)) {
            return response('', 403);
        }

        $raw = $request->getContent();
        $header = $definition->webhookSignatureHeader ?? 'X-Signature';
        $check = $this->signatures->check($definition->key, $raw, $request->header($header));

        if (! $check['verified']) {
            // Nothing is recorded. An unverified body is not evidence of anything except that
            // somebody posted to a public URL, and storing it would put attacker-controlled content
            // in the same table a consumer reads as trusted.
            return response('', 401);
        }

        /** @var array<string,mixed> $payload */
        $payload = json_decode($raw, true) ?: [];

        $result = $this->ingest->record($definition->key, $raw, $payload, verified: true);
        $event = $result['event'];

        if (! $result['duplicate'] && $event->status === 'received' && $definition->kind === ProviderKind::Advertising) {
            $this->scheduleSync($event);
        }

        // Always 2xx once the row is safe. A provider retries anything else, for hours.
        return response('', 200);
    }

    /**
     * Ask for a sync of the account this delivery named.
     *
     * Deliberately the SAME job the scheduler runs, over the same short window. A webhook makes the
     * product notice sooner; it does not become a second path by which numbers arrive, because two
     * paths mean two answers and no way to tell which is right.
     */
    private function scheduleSync(IntegrationWebhookEvent $event): void
    {
        if ($event->external_account_id === null) {
            return;
        }

        SyncAccountMetricsJob::dispatch(
            (string) $event->external_account_id,
            Carbon::now()->subDays(2)->toDateString(),
            Carbon::now()->toDateString(),
        );

        $event->forceFill(['status' => 'processed', 'processed_at' => Carbon::now()])->save();
    }

    private function definitionOr404(string $kind, string $provider): ProviderDefinition
    {
        abort_unless(ProviderCatalogue::has($provider), 404);

        $definition = ProviderCatalogue::get($provider);

        abort_unless($definition->kind->routeSegment() === $kind, 404);
        // A provider that does not deliver webhooks has no endpoint at all, rather than one that
        // always refuses — the two read very differently to somebody registering a URL.
        abort_if($definition->webhooks === WebhookSupport::PollingOnly, 404);

        return $definition;
    }
}
