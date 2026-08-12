<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Domains\Integrations\Configuration\ProviderProbe;
use App\Domains\Integrations\Models\ProviderConfiguration;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\Frontend;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * PROVCFG-001 — the platform operator's provider configuration, from `/admin`.
 *
 * ## This is the SYSTEM half of every integration, and it is the only place it can be edited
 *
 * The brief's architecture in one line:
 *
 *     System provider configuration  →  user OAuth consent  →  external account  →  client  →  project
 *
 * Everything to the left of the first arrow lives here and is reachable only by the platform owner.
 * Everything to the right of it lives in `/app` and `/agency` and is reachable only by a member of the
 * workspace it belongs to. The two never meet: a tenant cannot read a client secret, and this console
 * cannot see a tenant's campaigns.
 *
 * ## Three rules this controller enforces that are easy to lose
 *
 * 1. **A secret goes in and never comes out.** There is no endpoint that returns a stored value, for
 *    the platform owner or anybody else. The list and detail responses carry presence, source and four
 *    characters. An operator who has lost a secret rotates it at the provider — which is the correct
 *    answer regardless of what this console offered.
 * 2. **Writes are partial.** An omitted or empty field is left alone, so changing the environment does
 *    not require re-typing a secret nobody can read back. Clearing one is `DELETE`, explicitly.
 * 3. **Every write is audited by FIELD NAME, never by value.** The audit trail answers "who changed the
 *    Meta app secret, and when" without becoming a second, unencrypted copy of it.
 *
 * Deleting a provider's credentials or disabling it does NOT touch a single tenant's connection,
 * account or synced figure. Suspending an integration and destroying a customer's history are
 * different acts, and only one of them is available here.
 */
final class PlatformProviderSettingsController extends Controller
{
    public function __construct(
        private readonly ProviderConfigurationService $settings,
        private readonly ProviderProbe $probe,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /admin/settings/integrations/providers — every provider, in the product's order. */
    public function index(): JsonResponse
    {
        $providers = $this->settings->summaries();

        return ApiResponse::success([
            'providers' => $providers,
            /*
             * LEGAL-DELETE-001 — the compliance URLs every platform review asks for, ready to copy.
             *
             * They are DERIVED from the configured URLs rather than typed into a settings page,
             * because a hand-entered copy of a URL the application already serves is a copy that goes
             * stale the day the domain changes — and the failure mode is a rejected app review with
             * no obvious cause. `callback_required` is per provider and honest: Meta is the only one
             * of the six that asks for a machine-readable callback, and saying so stops an operator
             * hunting for a field the others do not have.
             */
            'compliance_urls' => $this->complianceUrls(),
            // The one number an operator actually opens this page for.
            'summary' => [
                'total' => count($providers),
                'connectable' => count(array_filter($providers, static fn (array $p) => $p['connectable'])),
                'needs_attention' => count(array_filter(
                    $providers,
                    static fn (array $p) => $p['state'] === 'configuration_error',
                )),
            ],
        ], 'Integration providers.');
    }

    /**
     * The three public URLs a platform review needs, plus the callback for the one that requires it.
     *
     * @return array<string, mixed>
     */
    private function complianceUrls(): array
    {
        $api = rtrim((string) config('app.url'), '/');

        return [
            // FRONTEND-URL-001 — one reader for the SPA's origin, never a second config path.
            'privacy_policy' => Frontend::url('/privacy'),
            'terms_of_service' => Frontend::url('/terms'),
            'user_data_deletion' => Frontend::url('/data-deletion'),
            'data_deletion_callback' => [
                // Only Meta asks for one today. The endpoint exists for any provider that adds the
                // requirement; what is per-provider is whether a console has a field for it.
                'meta' => $api.'/api/v1/webhooks/data-deletion/meta',
            ],
        ];
    }

    /** GET /admin/settings/integrations/providers/{provider} */
    public function show(string $provider): JsonResponse
    {
        $this->assertKnown($provider);

        return ApiResponse::success($this->settings->summary($provider), 'Integration provider.');
    }

    /**
     * PUT /admin/settings/integrations/providers/{provider}
     *
     * Validation is built from the provider's own field list rather than from a fixed schema, so a
     * value no provider asks for cannot be smuggled into the encrypted blob and sit there forever.
     */
    public function update(Request $request, string $provider): JsonResponse
    {
        $this->assertKnown($provider);
        $definition = ProviderCatalogue::get($provider);

        $rules = [
            'environment' => ['sometimes', Rule::in(['sandbox', 'production'])],
            'scopes' => ['sometimes', 'nullable', 'array'],
            'scopes.*' => ['string', 'max:200'],
        ];

        foreach ($definition->fields as $field) {
            $rules[$field->key] = ['sometimes', 'nullable', 'string', 'max:2000'];
        }

        $validated = $request->validate($rules);

        $before = [
            'state' => $this->settings->state($provider)->value,
            'environment' => $this->settings->environment($provider),
        ];

        $changed = $this->settings->save(
            provider: $definition->key,
            values: array_intersect_key($validated, array_flip(array_map(
                static fn ($f) => $f->key,
                $definition->fields,
            ))),
            environment: $validated['environment'] ?? null,
            actorId: $request->user()?->getKey(),
        );

        if (array_key_exists('scopes', $validated)) {
            $this->saveScopes($definition->key, $validated['scopes']);
            $changed[] = 'scopes';
        }

        if ($changed !== []) {
            $this->audit->log(
                action: 'platform.integration.provider.updated',
                entityType: ProviderConfiguration::class,
                entityId: $definition->key,
                before: $before,
                // FIELD NAMES ONLY. The whole point of encrypting the column is lost the moment the
                // audit log carries what was written into it.
                after: ['fields_changed' => array_values(array_unique($changed)), 'state' => $this->settings->state($provider)->value],
            );
        }

        return ApiResponse::success([
            ...$this->settings->summary($provider),
            'fields_changed' => array_values(array_unique($changed)),
        ], 'Provider configuration saved.');
    }

    /**
     * POST /admin/settings/integrations/providers/{provider}/test
     *
     * A real HTTP round trip. See `ProviderProbe` for the exact — and deliberately narrow — claim a
     * pass makes.
     */
    public function test(string $provider): JsonResponse
    {
        $this->assertKnown($provider);

        $result = $this->probe->run($provider);
        $this->settings->recordTest($provider, $result['passed'], $result['message']);

        $this->audit->log(
            action: 'platform.integration.provider.tested',
            entityType: ProviderConfiguration::class,
            entityId: ProviderCatalogue::get($provider)->key,
            after: ['passed' => $result['passed'], 'state' => $this->settings->state($provider)->value],
        );

        return ApiResponse::success([
            'passed' => $result['passed'],
            'message' => $result['message'],
            ...$this->settings->summary($provider),
        ], 'Configuration tested.');
    }

    /**
     * POST /admin/settings/integrations/providers/{provider}/rotate
     *
     * One field, one new value. Existing tenant connections keep working — a customer's access token
     * was issued to the app, not to the secret — and the next refresh is what exercises the new value,
     * which is why the test verdict is cleared and the page asks for a re-test.
     */
    public function rotate(Request $request, string $provider): JsonResponse
    {
        $this->assertKnown($provider);
        $definition = ProviderCatalogue::get($provider);

        $validated = $request->validate([
            'key' => ['required', 'string', Rule::in(array_map(static fn ($f) => $f->key, $definition->fields))],
            'value' => ['required', 'string', 'max:2000'],
        ]);

        $this->settings->rotate($definition->key, $validated['key'], $validated['value'], $request->user()?->getKey());

        $this->audit->log(
            action: 'platform.integration.provider.rotated',
            entityType: ProviderConfiguration::class,
            entityId: $definition->key,
            after: ['field' => $validated['key']],
        );

        return ApiResponse::success($this->settings->summary($provider), 'Credential rotated.');
    }

    /**
     * PATCH /admin/settings/integrations/providers/{provider}/status
     *
     * Take the provider out of service, or put it back. Nothing is deleted either way.
     */
    public function status(Request $request, string $provider): JsonResponse
    {
        $this->assertKnown($provider);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            // An operator disabling a live provider is stopping every customer's sync; saying why is
            // the least that should be recorded, and it is what the audit row is read for later.
            'reason' => ['required_if:enabled,false', 'nullable', 'string', 'min:3', 'max:500'],
        ]);

        $definition = ProviderCatalogue::get($provider);
        $before = ['enabled' => $this->settings->isEnabled($provider)];

        $this->settings->setEnabled($definition->key, $validated['enabled']);

        $this->audit->log(
            action: $validated['enabled']
                ? 'platform.integration.provider.enabled'
                : 'platform.integration.provider.disabled',
            entityType: ProviderConfiguration::class,
            entityId: $definition->key,
            before: $before,
            after: ['enabled' => $validated['enabled']],
            reason: $validated['reason'] ?? null,
        );

        return ApiResponse::success($this->settings->summary($provider), 'Provider status updated.');
    }

    /**
     * DELETE /admin/settings/integrations/providers/{provider}/credentials/{key}
     *
     * Clear ONE stored value. Explicit and single-field so that removing a key is always something
     * somebody chose. Tenant connections are untouched: they hold their own tokens and keep serving
     * the figures already synced, and stop only at the next refresh, which is honest — the platform
     * operator removed the app's identity, so re-authorisation is genuinely required.
     */
    public function forget(string $provider, string $key): JsonResponse
    {
        $this->assertKnown($provider);
        $definition = ProviderCatalogue::get($provider);

        abort_if($definition->field($key) === null, 404);

        if (! $this->settings->forget($definition->key, $key)) {
            return ApiResponse::error(
                message: 'That credential is not stored, so there is nothing to clear.',
                errors: ['key' => [$key]],
                status: 422,
            );
        }

        $this->audit->log(
            action: 'platform.integration.provider.credential_cleared',
            entityType: ProviderConfiguration::class,
            entityId: $definition->key,
            after: ['field' => $key],
        );

        return ApiResponse::success($this->settings->summary($provider), 'Credential cleared.');
    }

    /** @param array<int,string>|null $scopes */
    private function saveScopes(string $provider, ?array $scopes): void
    {
        $row = ProviderConfiguration::query()->firstOrNew(['provider' => $provider]);
        $row->provider = $provider;
        $row->scopes = $scopes === null || $scopes === [] ? null : array_values($scopes);
        $row->save();

        $this->settings->forgetCache($provider);
    }

    private function assertKnown(string $provider): void
    {
        abort_unless(ProviderCatalogue::has($provider), 404);
    }
}
