<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Billing\Gateways\PaymentGatewayCatalogue;
use App\Domains\Billing\Gateways\PaymentGatewaySettings;
use App\Domains\Billing\Providers\SubscriptionProviderRegistry;
use App\Domains\Subscriptions\Notifications\MailTransportState;
use App\Domains\Subscriptions\Services\PlanCatalogue;
use App\Domains\Subscriptions\Services\RecurringBilling;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\Frontend;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The payment gateways, from the platform owner's console (PAYSET-001, PAY-GATEWAY-CONSOLE-001).
 *
 * Since PAY-GATEWAY-CONSOLE-001 (Owner decision, 2026-10-31) the keys ARE entered here: stored encrypted in
 * `provider_configurations`, write-only (no endpoint ever returns a secret, a fragment of one or its length), every change audited
 * by field name. An install configured from the environment keeps working until a key is saved here. Saving keys, testing,
 * enabling (live needs its own confirmation) and choosing the primary gateway are four separate acts, and a gateway is enabled only
 * after a passing test with the exact keys and mode now stored. New payments go to the primary; every existing payment and
 * subscription keeps the gateway it was made with.
 */
final class PlatformPaymentSettingsController extends Controller
{
    public function __construct(
        private readonly SubscriptionProviderRegistry $providers,
        private readonly RecurringBilling $recurring,
        private readonly PaymentGatewaySettings $settings,
    ) {}

    /** GET /api/v1/admin/settings/integrations/payments — every gateway, what it needs, what it has, and what it may do. */
    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'default' => $this->settings->primary(),
            'primary' => $this->settings->primary(),
            'primary_source' => $this->settings->primaryIsFromConsole() ? 'console' : 'environment',
            // The currency new customers are quoted in (the published catalogue's); existing subscriptions keep their own.
            'currency' => app(PlanCatalogue::class)->currency(),
            'providers' => array_map(fn (string $key) => $this->describe($key), PaymentGatewayCatalogue::KEYS),
            'recurring' => $this->recurring->readiness(),
            'mail' => [
                'state' => MailTransportState::current(),
                'driver' => (string) config('mail.default'),
            ],
        ], 'Payment settings.');
    }

    /** PUT /api/v1/admin/settings/integrations/payments/{provider} — store keys and mode. Blank fields keep what is stored. */
    public function save(Request $request, string $provider): JsonResponse
    {
        $definition = $this->definitionOr404($provider);
        $rules = ['mode' => ['sometimes', 'nullable', 'in:test,live']];
        foreach ($definition['fields'] as $field) {
            $rules[$field['key']] = ['sometimes', 'nullable', 'string', 'max:4000'];
        }
        $data = $request->validate($rules);

        $changed = $this->settings->save($provider, $data, $data['mode'] ?? null, $request->user()?->id);

        return ApiResponse::success(['changed' => $changed, 'provider' => $this->describe($provider)], $changed === [] ? 'Nothing changed.' : 'Saved.');
    }

    /** POST /api/v1/admin/settings/integrations/payments/{provider}/activation — enable or disable for NEW payments. */
    public function activation(Request $request, string $provider): JsonResponse
    {
        $this->definitionOr404($provider);
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'confirm_live' => ['sometimes', 'boolean']]);

        $this->settings->setEnabled($provider, (bool) $data['enabled'], (bool) ($data['confirm_live'] ?? false), $request->user()?->id);

        return ApiResponse::success(['provider' => $this->describe($provider)], $data['enabled'] ? 'Enabled.' : 'Disabled.');
    }

    /** PUT /api/v1/admin/settings/integrations/payments/primary — where NEW payments go. Nothing existing moves. */
    public function primary(Request $request): JsonResponse
    {
        $data = $request->validate(['gateway' => ['required', 'string', 'in:'.implode(',', PaymentGatewayCatalogue::KEYS)], 'confirm' => ['required', 'accepted']]);

        $this->settings->setPrimary($data['gateway'], $request->user()?->id);

        return $this->index();
    }

    /**
     * POST /api/v1/admin/settings/integrations/payments/{provider}/test
     *
     * A REAL round trip to the gateway, not a config check: the smallest checkout session it accepts, which is an intent that expires
     * unused — nothing is charged. The result is recorded against the exact keys and mode in use.
     */
    public function test(Request $request, string $provider): JsonResponse
    {
        $this->definitionOr404($provider);

        if (! $this->implemented($provider)) {
            return ApiResponse::error('This gateway has no adapter yet.', meta: ['status' => 'not_implemented'], status: 422);
        }

        $adapter = $this->providers->for($provider);

        if (! $adapter->isConfigured()) {
            return ApiResponse::error(
                'This provider has no credentials, so there is nothing to test.',
                meta: ['status' => 'awaiting_credentials'],
                status: 422,
            );
        }

        $result = $adapter->createSession([
            'amount' => '1.00',
            'currency' => app(PlanCatalogue::class)->currency(),
            'description' => 'CampaignsHub connection test',
            'reference' => 'connection-test:'.now()->timestamp,
            'return_url' => Frontend::origin().'/admin/settings',
            // Some gateways (Tap) will not open an intent without a named, addressable customer: the operator running the test is
            // that customer, and nothing is charged.
            'customer' => ['name' => (string) $request->user()->name, 'email' => (string) $request->user()->email],
        ]);

        $reachable = ($result['status'] ?? '') === 'created';
        $this->settings->recordTest($provider, $reachable, $reachable ? null : (string) ($result['error'] ?? 'The gateway refused the request.'), $request->user()?->id);

        return ApiResponse::success([
            'provider' => $provider,
            'reachable' => $reachable,
            'status' => $result['status'] ?? 'failed',
            // The gateway's own words: a wrong key, a closed account and an unsupported currency are three different fixes.
            'error' => $result['error'] ?? null,
        ], $reachable ? 'The gateway accepted the request.' : 'The gateway refused the request.');
    }

    /**
     * GET /api/v1/admin/settings/integrations/payments/{provider}/webhook
     *
     * Where the gateway should send events, and what it must be configured with. An operator
     * setting up a webhook needs the URL and the secret's NAME, never the secret itself.
     */
    public function webhook(string $provider): JsonResponse
    {
        $definition = $this->definitionOr404($provider);

        return ApiResponse::success([
            'provider' => $provider,
            'url' => $this->webhookUrl($provider),
            'authentication' => $definition['webhook']['scheme'],
            'header' => $definition['webhook']['header'],
            'events' => $definition['webhook']['events'],
        ], 'Webhook configuration.');
    }

    /** GET …/{provider}/rotation — rotating a key is: issue it at the gateway, save it here, test, then revoke the old one. */
    public function rotation(string $provider): JsonResponse
    {
        $definition = $this->definitionOr404($provider);

        return ApiResponse::success([
            'provider' => $provider,
            'fields' => array_values(array_map(fn (array $f) => $f['key'], array_filter($definition['fields'], fn (array $f) => $f['secret']))),
            'steps' => [
                'Issue the new key in the provider’s own dashboard, leaving the old one active.',
                'Paste it here and save; the old value is replaced and never shown.',
                'Run the connection test and confirm it passes with the new key.',
                'Revoke the old key in the provider’s dashboard.',
            ],
            'note' => 'Saved secrets are never shown again, not even here.',
        ], 'Secret rotation.');
    }

    /** @return array<string, mixed> */
    private function describe(string $key): array
    {
        $definition = PaymentGatewayCatalogue::get($key);
        $implemented = $this->implemented($key);
        $configured = $implemented && $this->settings->isConfigured($key);
        $enabled = $implemented && $this->settings->isEnabled($key);
        $row = $this->settings->row($key);
        $testCurrent = $this->settings->testIsCurrent($key);

        $status = match (true) {
            ! $implemented => 'not_implemented',
            ! $configured => 'awaiting_credentials',
            $enabled => 'enabled',
            ! $testCurrent => 'needs_test',
            default => 'ready',
        };

        return [
            'provider' => $key,
            'label' => $definition['label'],
            'role' => $key === 'moyasar' ? 'primary' : 'alternative',
            'is_default' => $this->settings->primary() === $key,
            'is_primary' => $this->settings->primary() === $key,
            'implemented' => $implemented,
            'status' => $status,
            'available' => $configured && $enabled,
            'enabled' => $enabled,
            'mode' => $configured ? $this->settings->mode($key) : null,
            'environment' => ! $configured ? 'unset' : ($this->settings->mode($key) === 'live' ? 'live' : 'sandbox'),
            'source' => $this->settings->source($key),
            'fields' => array_map(fn (array $f) => [
                'key' => $f['key'],
                'label' => $f['label'],
                'secret' => $f['secret'],
                'required' => $f['required'],
                'present' => $this->settings->value($key, $f['key']) !== null,
                // A publishable key is meant to be public; a secret is never shown in any form once saved.
                'value' => $f['secret'] ? null : $this->settings->value($key, $f['key']),
            ], $definition['fields']),
            // Kept for the readiness view: the legacy environment names, presence only.
            'requires' => array_values(array_map(fn (array $f) => [
                'key' => (string) ($f['env'] ?? strtoupper($key.'_'.$f['key'])),
                'present' => $this->settings->value($key, $f['key']) !== null,
            ], array_filter($definition['fields'], fn (array $f) => $f['required']))),
            'test' => [
                'status' => $row?->last_test_status,
                'at' => $row?->last_tested_at?->toIso8601String(),
                'message' => $row?->last_test_status === 'failed' ? $row->last_test_message : null,
                'current' => $testCurrent,
            ],
            'last_rotated_at' => $row?->last_rotated_at?->toIso8601String(),
            'webhook_url' => $this->webhookUrl($key),
            'webhook' => $definition['webhook'],
            'capabilities' => $definition['capabilities'],
            'methods' => $definition['methods'],
            'docs' => $definition['docs'],
            'note' => $definition['note'],
        ];
    }

    private function implemented(string $key): bool
    {
        return PaymentGatewayCatalogue::get($key)['fields'] !== [] && array_key_exists($key, (array) config('subscriptions.providers', []));
    }

    private function webhookUrl(string $provider): string
    {
        return PaymentGatewaySettings::webhookUrl($provider);
    }

    /** @return array<string, mixed> */
    private function definitionOr404(string $provider): array
    {
        abort_unless(PaymentGatewayCatalogue::exists($provider), 404);

        return PaymentGatewayCatalogue::get($provider);
    }
}
