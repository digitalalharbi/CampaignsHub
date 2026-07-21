<?php

declare(strict_types=1);

namespace App\Domains\CRM\DTOs;

/** Validated input for creating/updating a lead. */
final readonly class LeadData
{
    public function __construct(
        public string $name,
        public ?string $email,
        public ?string $phone,
        public string $source,
        public string $status,
        public float $estimatedValue,
        public string $currency,
        public ?string $notes,
        public ?string $companyId,
        public ?string $contactId,
        public ?int $ownerId,
        /** @var array<int,string>|null */
        public ?array $tags,
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) $data['name'],
            email: $data['email'] ?? null,
            phone: $data['phone'] ?? null,
            source: (string) ($data['source'] ?? 'manual'),
            status: (string) ($data['status'] ?? 'new'),
            estimatedValue: (float) ($data['estimated_value'] ?? 0),
            currency: (string) ($data['currency'] ?? 'SAR'),
            notes: $data['notes'] ?? null,
            companyId: $data['company_id'] ?? null,
            contactId: $data['contact_id'] ?? null,
            ownerId: isset($data['owner_id']) ? (int) $data['owner_id'] : null,
            tags: $data['tags'] ?? null,
        );
    }

    /** @return array<string,mixed> */
    public function toAttributes(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'source' => $this->source,
            'status' => $this->status,
            'estimated_value' => $this->estimatedValue,
            'currency' => $this->currency,
            'notes' => $this->notes,
            'company_id' => $this->companyId,
            'contact_id' => $this->contactId,
            'owner_id' => $this->ownerId,
            'tags' => $this->tags,
        ];
    }
}
