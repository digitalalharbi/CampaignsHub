<?php

declare(strict_types=1);

namespace App\Domains\Identity\DTOs;

/**
 * Immutable input for tenant self-registration.
 */
final readonly class RegisterData
{
    public function __construct(
        public string $tenantName,
        public string $name,
        public string $email,
        public string $password,
        /** Chosen on the public site; null when someone lands on /register directly. */
        public ?string $accountType = null,
        /** paid_media | influencer_marketing | combined — the service the visitor came for. */
        public ?string $service = null,
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            tenantName: (string) $data['tenant_name'],
            name: (string) $data['name'],
            email: (string) $data['email'],
            password: (string) $data['password'],
            accountType: isset($data['account_type']) ? (string) $data['account_type'] : null,
            service: isset($data['service']) ? (string) $data['service'] : null,
        );
    }
}
