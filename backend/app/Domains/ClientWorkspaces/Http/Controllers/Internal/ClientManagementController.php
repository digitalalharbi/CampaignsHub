<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Http\Controllers\Internal;

use App\Domains\ClientWorkspaces\Enums\ClientStatus;
use App\Domains\ClientWorkspaces\Enums\Industry;
use App\Domains\ClientWorkspaces\Enums\ServiceLevel;
use App\Domains\ClientWorkspaces\Services\ClientAccess;
use App\Domains\ClientWorkspaces\Services\ClientManagementService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Manageable client classification + settings + archive/restore. Every write is permission-gated, access-gated
 * (ClientAccess), validated against the real enums, and audited (which surfaces on the Activity tab).
 */
final class ClientManagementController
{
    public function __construct(
        private readonly ClientAccess $access,
        private readonly ClientManagementService $service,
        private readonly TenantContext $tenant,
    ) {}

    /** PATCH /app/clients/{client}/classification */
    public function updateClassification(Request $request, string $client): JsonResponse
    {
        $c = $this->access->resolve($client);
        $this->access->assert($request->user(), 'clients.update', $c);

        $data = $request->validate([
            'client_status' => ['sometimes', 'required', Rule::in(ClientStatus::values())],
            'service_level' => ['sometimes', 'nullable', Rule::in(ServiceLevel::values())],
            'industry' => ['sometimes', 'nullable', Rule::in(Industry::values())],
            'owner_id' => ['sometimes', 'nullable', 'integer'],
            'priority' => ['sometimes', 'required', Rule::in(['low', 'normal', 'high'])],
            'default_currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'timezone' => ['sometimes', 'nullable', 'timezone'],
            'language' => ['sometimes', 'nullable', 'string', 'in:ar,en'],
            // Additive taxonomy-driven multi-selects (tenant-managed vocabularies → free strings).
            'tags' => ['sometimes', 'nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
            'enabled_services' => ['sometimes', 'nullable', 'array'],
            'enabled_services.*' => ['string', 'max:64'],
        ]);

        if (array_key_exists('owner_id', $data) && $data['owner_id'] !== null) {
            $this->assertSameTenantUser((int) $data['owner_id']);
        }

        $c = $this->service->updateClassification($c, $data, $request->user());

        return response()->json(['data' => ['classification' => $this->classification($c)]]);
    }

    /** PATCH /app/clients/{client}/settings */
    public function updateSettings(Request $request, string $client): JsonResponse
    {
        $c = $this->access->resolve($client);
        $this->access->assert($request->user(), 'clients.manage_settings', $c);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'branding.logo_url' => ['sometimes', 'nullable', 'url'],
            'settings.week_start' => ['sometimes', 'nullable', 'in:sunday,monday'],
            'settings.report_identity.display_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'settings.report_identity.footer' => ['sometimes', 'nullable', 'string', 'max:280'],
            'settings.report_prefs.default_audience' => ['sometimes', 'nullable', 'in:client,internal,executive'],
            'settings.report_prefs.default_format' => ['sometimes', 'nullable', 'in:pdf,xlsx,csv'],
            'settings.alert_prefs.budget_risk' => ['sometimes', 'boolean'],
            'settings.alert_prefs.sync_failed' => ['sometimes', 'boolean'],
        ]);

        $c = $this->service->updateSettings(
            $c,
            $validated['settings'] ?? [],
            $request->user(),
            $validated['name'] ?? null,
            $validated['branding'] ?? null,
        );

        return response()->json(['data' => [
            'name' => $c->name,
            'settings' => $c->settings,
            'branding' => $c->branding,
        ]]);
    }

    /** POST /app/clients/{client}/archive */
    public function archive(Request $request, string $client): JsonResponse
    {
        $c = $this->access->resolve($client);
        $this->access->assert($request->user(), 'clients.archive', $c);
        $c = $this->service->archive($c, $request->user(), $request->string('reason')->toString() ?: null);

        return response()->json(['data' => ['is_archived' => true, 'archived_at' => optional($c->archived_at)->toIso8601String()]]);
    }

    /** POST /app/clients/{client}/restore */
    public function restore(Request $request, string $client): JsonResponse
    {
        $c = $this->access->resolve($client);
        $this->access->assert($request->user(), 'clients.restore', $c);
        $c = $this->service->restore($c, $request->user());

        return response()->json(['data' => ['is_archived' => false, 'client_status' => $c->client_status]]);
    }

    private function assertSameTenantUser(int $userId): void
    {
        $ok = User::query()->whereKey($userId)->inTenant((string) $this->tenant->tenantId())->exists();
        if (! $ok) {
            throw ValidationException::withMessages(['owner_id' => 'Owner must be a user in this workspace.']);
        }
    }

    /** @return array<string,mixed> */
    private function classification($c): array
    {
        return [
            'client_status' => $c->client_status,
            'service_level' => $c->service_level,
            'industry' => $c->industry,
            'owner_id' => $c->owner_id,
            'owner_name' => $c->owner?->name,
            'priority' => $c->priority,
            'default_currency' => $c->default_currency,
            'timezone' => $c->timezone,
            'language' => $c->language,
            'week_start' => $c->week_start,
        ];
    }
}
