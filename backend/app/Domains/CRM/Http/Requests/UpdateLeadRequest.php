<?php

declare(strict_types=1);

namespace App\Domains\CRM\Http\Requests;

use App\Domains\CRM\Enums\LeadSource;
use App\Domains\CRM\Enums\LeadStatus;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('leads.update') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:160'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'source' => ['sometimes', Rule::in(LeadSource::values())],
            'status' => ['sometimes', Rule::in(LeadStatus::values())],
            'estimated_value' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'notes' => ['nullable', 'string'],
            'company_id' => ['nullable', 'uuid', Rule::exists('companies', 'id')->where('tenant_id', app(TenantContext::class)->tenantId())],
            'contact_id' => ['nullable', 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', app(TenantContext::class)->tenantId())],
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:40'],
        ];
    }
}
