<?php

declare(strict_types=1);

namespace App\Domains\CRM\Http\Requests;

use App\Domains\CRM\Enums\LeadSource;
use App\Domains\CRM\Enums\LeadStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('leads.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'source' => ['required', Rule::in(LeadSource::values())],
            'status' => ['sometimes', Rule::in(LeadStatus::values())],
            'estimated_value' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'notes' => ['nullable', 'string'],
            'company_id' => ['nullable', 'uuid', Rule::exists('companies', 'id')->where('tenant_id', $this->tenantId())],
            'contact_id' => ['nullable', 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', $this->tenantId())],
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:40'],
        ];
    }

    private function tenantId(): ?string
    {
        return $this->user()?->tenant_id;
    }
}
