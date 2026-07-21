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
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            tenantName: (string) $data['tenant_name'],
            name: (string) $data['name'],
            email: (string) $data['email'],
            password: (string) $data['password'],
        );
    }
}
