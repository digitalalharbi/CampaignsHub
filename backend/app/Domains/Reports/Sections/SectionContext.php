<?php

declare(strict_types=1);

namespace App\Domains\Reports\Sections;

/**
 * What a predicate may know when it decides whether a section is supported or available.
 *
 * `payload` is null when the figures have not been assembled — the builder's preview of a live
 * report, which computes nothing until a client opens it. Availability is then NOT judged (a section
 * is not «unavailable» because nobody looked), and the resolution says so.
 */
final class SectionContext
{
    /**
     * @param  list<string>  $providers
     * @param  array<string, mixed>|null  $payload
     */
    public function __construct(
        public readonly string $audience = 'client',
        public readonly string $form = 'detailed',
        public readonly array $providers = [],
        public readonly ?string $objective = null,
        public readonly ?array $payload = null,
        public readonly string $surface = 'snapshot',
    ) {}

    public function isClientFacing(): bool
    {
        return $this->audience !== 'internal';
    }

    /** @param array<string, mixed>|null $payload */
    public function withPayload(?array $payload): self
    {
        return new self($this->audience, $this->form, $this->providers, $this->objective, $payload, $this->surface);
    }

    /** A payload value, or null when the key is absent or the payload was not assembled. */
    public function value(string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (is_array($this->payload) && array_key_exists($key, $this->payload) && $this->payload[$key] !== null) {
                return $this->payload[$key];
            }
        }

        return null;
    }

    /** The rows under the first present key, as a list of arrays. */
    public function rows(string ...$keys): array
    {
        $value = $this->value(...$keys);

        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}
