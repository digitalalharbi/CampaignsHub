<?php

declare(strict_types=1);

namespace App\Domains\Requests\Services;

use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Requests\Models\RequestUploadSession;
use App\Domains\Taxonomy\Services\PaidServiceCatalog;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Portal tenant is resolved by PortalTenantResolver and passed in — no fragile env/Tenant::first().

/**
 * Persists a validated external request atomically: reference + record + timeline event + a secure
 * tracking token (plaintext returned once, only the hash stored). Never trusts the client for status,
 * reference, token or tenant.
 */
final class RequestIntake
{
    public function __construct(private readonly PaidServiceCatalog $paidServices) {}

    /**
     * @param  array<string,mixed>  $data  validated payload
     * @return array{request: ExternalRequest, token: string}
     */
    public function create(array $data, Tenant $tenant): array
    {
        return DB::transaction(function () use ($data, $tenant) {
            $type = RequestType::where('key', $data['type'])->firstOrFail();
            $status = RequestStatus::where('key', 'new')->firstOrFail();

            $request = new ExternalRequest;
            $request->fill([
                'tenant_id' => $tenant->id,
                'reference' => $this->reference(),
                'module' => $type->module,
                'type_id' => $type->id,
                'status_id' => $status->id,
                'priority' => $data['priority'] ?? 'medium',
                'source' => 'public_portal',
                'contact_name' => $data['contact_name'],
                'contact_email' => $data['contact_email'],
                'contact_phone' => $data['contact_phone'] ?? null,
                'company_name' => $data['company_name'] ?? null,
                'objective' => $data['objective'] ?? null,
                'budget' => $data['budget'] ?? null,
                'currency' => $data['currency'] ?? 'SAR',
                'start_date' => $data['start_date'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'services' => $this->normalizeServices($data['services'] ?? null),
                'service_details' => $data['service_details'] ?? null,
                'sla_started_at' => now(),
                'sla_due_at' => now()->addHours((int) config('requests.default_sla_hours', 48)),
                'submitted_at' => now(),
                'last_activity_at' => now(),
                'is_external' => true,
                'is_demo' => false,
                'metadata' => $data['metadata'] ?? [],
            ]);
            $request->save();

            // Canonical per-service rows (source of truth for quote/invoice line items). Keys were already
            // server-validated in the controller against the public catalog; category_key + optional per-service
            // details are attached here. The `services` jsonb column stays as a denormalized mirror.
            $this->persistServices($request, $request->services ?? [], $data['service_details'] ?? []);

            $request->events()->create([
                'type' => 'created',
                'to_status' => 'new',
                'is_client_visible' => true,
                'message' => 'Request submitted',
                'meta' => $request->services ? ['services' => $request->services] : null,
                'created_at' => now(),
            ]);

            $plain = Str::random(48);
            $request->tokens()->create([
                'token_hash' => hash('sha256', $plain),
                'created_at' => now(),
            ]);

            // Associate any files uploaded to a temp session, then retire the session.
            if (! empty($data['upload_token'])) {
                $session = RequestUploadSession::where('token_hash', hash('sha256', $data['upload_token']))->first();
                if ($session !== null) {
                    $session->files()->whereNull('request_id')->update([
                        'request_id' => $request->id,
                        'upload_session_id' => null,
                    ]);
                    $session->delete();
                }
            }

            return ['request' => $request->fresh(['type', 'status']), 'token' => $plain];
        });
    }

    /**
     * Write the canonical request_services rows for the accepted service keys. category_key is resolved from the
     * public catalog map (keys were validated upstream); per-service details come from service_details[key].
     *
     * @param  list<string>  $serviceKeys
     * @param  array<string,mixed>  $serviceDetails
     */
    private function persistServices(ExternalRequest $request, array $serviceKeys, array $serviceDetails): void
    {
        if ($serviceKeys === []) {
            return;
        }

        $categoryByKey = $this->paidServices->publicServiceMap();

        $position = 0;
        foreach ($serviceKeys as $key) {
            $details = $serviceDetails[$key] ?? null;

            $request->requestServices()->create([
                'service_key' => $key,
                'category_key' => $categoryByKey[$key] ?? null,
                'details' => is_array($details) ? $details : null,
                'position' => $position++,
            ]);
        }
    }

    /**
     * Normalize submitted services to a de-duplicated list of non-empty string keys, or null when none. Never
     * throws — an empty/invalid selection simply persists as null (legacy path stays valid).
     *
     * @return list<string>|null
     */
    private function normalizeServices(mixed $services): ?array
    {
        if (! is_array($services)) {
            return null;
        }

        $keys = [];
        foreach ($services as $service) {
            if (is_string($service) && $service !== '') {
                $keys[$service] = $service;
            }
        }

        return $keys === [] ? null : array_values($keys);
    }

    private function reference(): string
    {
        // REQ-YYYY-XXXXXX (uppercase, unambiguous). Retry on the rare unique collision.
        do {
            $ref = 'REQ-'.now()->format('Y').'-'.Str::upper(Str::random(6));
        } while (ExternalRequest::where('reference', $ref)->exists());

        return $ref;
    }
}
