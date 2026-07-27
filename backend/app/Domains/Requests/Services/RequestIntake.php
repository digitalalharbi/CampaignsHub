<?php

declare(strict_types=1);

namespace App\Domains\Requests\Services;

use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Requests\Models\RequestUploadSession;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Persists a validated external request atomically: reference + record + timeline event + a secure
 * tracking token (plaintext returned once, only the hash stored). Never trusts the client for status,
 * reference, token or tenant.
 */
final class RequestIntake
{
    /**
     * @param  array<string,mixed>  $data  validated payload
     * @return array{request: ExternalRequest, token: string}
     */
    public function create(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $type = RequestType::where('key', $data['type'])->firstOrFail();
            $status = RequestStatus::where('key', 'new')->firstOrFail();

            $request = new ExternalRequest;
            $request->fill([
                'tenant_id' => $this->portalTenantId(),
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
                'sla_started_at' => now(),
                'sla_due_at' => now()->addHours((int) config('requests.default_sla_hours', 48)),
                'submitted_at' => now(),
                'last_activity_at' => now(),
                'is_external' => true,
                'is_demo' => false,
                'metadata' => $data['metadata'] ?? [],
            ]);
            $request->save();

            $request->events()->create([
                'type' => 'created',
                'to_status' => 'new',
                'is_client_visible' => true,
                'message' => 'Request submitted',
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
     * The tenant that owns public-portal requests. Configurable for a multi-portal deployment; otherwise
     * a single-portal install belongs to the platform owner's (first) tenant so requests are visible to
     * that tenant's internal dashboard.
     */
    private function portalTenantId(): ?string
    {
        $configured = config('requests.portal_tenant_id');
        if ($configured !== null) {
            return (string) $configured;
        }

        return Tenant::query()->orderBy('created_at')->value('id');
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
