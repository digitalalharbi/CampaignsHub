<?php

declare(strict_types=1);

namespace App\Domains\AI\Resources;

use App\Domains\AI\Models\AIProviderCredential;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AIProviderCredential
 *
 * NEVER exposes the secret — only a masked hint. This is the only shape the API returns for a key.
 */
final class AICredentialResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'credential_scope' => $this->credential_scope,
            'client_workspace_id' => $this->client_workspace_id,
            'project_id' => $this->project_id,
            'masked_key' => $this->maskedKey(),
            'status' => $this->status,
            'organization_id' => $this->organization_id,
            'monthly_budget' => $this->monthly_budget !== null ? (float) $this->monthly_budget : null,
            'monthly_token_limit' => $this->monthly_token_limit,
            'allowed_models' => $this->allowed_models ?? [],
            'allowed_features' => $this->allowed_features ?? [],
            'last_health_check_at' => optional($this->last_health_check_at)->toIso8601String(),
            'last_used_at' => optional($this->last_used_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
