<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Data;

use App\Domains\Notifications\Engine\Enums\Channel;
use App\Domains\Notifications\Engine\Enums\EntitlementKey;
use App\Domains\Notifications\Engine\Enums\Priority;
use App\Domains\Notifications\Engine\Enums\Scope;

/**
 * One entry of the ONE event catalog (spec §3). Defaults: config/notification_events.php; platform overrides:
 * `notification_event_catalog`. Format documented in docs/notifications-v2/CONTRACTS.md §3.
 *
 * Text is never stored here: titles and bodies are translation keys, see {@see textKey()}.
 */
final class EventDefinition
{
    /**
     * @param  array<string, list<string>>  $defaultRoles  workspace type key => role keys of that type's profile
     * @param  list<Channel>  $channels  allowed channels (external scope: email only)
     */
    public function __construct(
        public readonly string $key,
        public readonly Scope $scope,
        public readonly Priority $priority,
        public readonly array $defaultRoles,
        public readonly array $channels,
        public readonly ?EntitlementKey $entitlement,
        public readonly bool $createsTask = false,
        public readonly bool $internalOnly = false,
        public readonly ?string $preferenceType = null,
        public readonly ?string $requiredCapability = null,
        public readonly bool $groupable = true,
        public readonly bool $enabled = true,
        public readonly int $version = 1,
    ) {}

    /** `notifications_v2.events.<key with _>.<scope>.<part>` — part is `title` or `body`. */
    public function textKey(Scope $scope, string $part): string
    {
        return 'notifications_v2.events.'.str_replace('.', '_', $this->key).'.'.$scope->value.'.'.$part;
    }

    /** @return list<string> */
    public function rolesFor(string $workspaceType): array
    {
        return $this->defaultRoles[$workspaceType] ?? [];
    }

    public function allows(Channel $channel): bool
    {
        return in_array($channel, $this->channels, true);
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(string $key, array $a): self
    {
        $scope = Scope::from((string) ($a['scope'] ?? 'internal'));
        $internalOnly = (bool) ($a['internal_only'] ?? false);
        $channels = array_values(array_filter(array_map(
            static fn ($c): ?Channel => Channel::tryFrom((string) $c),
            (array) ($a['channels'] ?? ['in_app']),
        )));
        // An internal-only event can never reach the external scope, whatever an override says.
        if ($internalOnly && $scope !== Scope::Internal) {
            $scope = Scope::Internal;
        }

        return new self(
            key: $key,
            scope: $scope,
            priority: Priority::from((string) ($a['priority'] ?? 'info')),
            defaultRoles: array_map(static fn ($r): array => array_values(array_map('strval', (array) $r)), (array) ($a['default_roles'] ?? [])),
            channels: $channels,
            entitlement: isset($a['entitlement']) ? EntitlementKey::from((string) $a['entitlement']) : null,
            createsTask: (bool) ($a['creates_task'] ?? false),
            internalOnly: $internalOnly,
            preferenceType: isset($a['preference_type']) ? (string) $a['preference_type'] : null,
            requiredCapability: isset($a['required_capability']) ? (string) $a['required_capability'] : null,
            groupable: (bool) ($a['groupable'] ?? true),
            enabled: (bool) ($a['enabled'] ?? true),
            version: (int) ($a['version'] ?? 1),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'scope' => $this->scope->value,
            'priority' => $this->priority->value,
            'default_roles' => $this->defaultRoles,
            'channels' => array_map(static fn (Channel $c): string => $c->value, $this->channels),
            'entitlement' => $this->entitlement?->value,
            'creates_task' => $this->createsTask,
            'internal_only' => $this->internalOnly,
            'preference_type' => $this->preferenceType,
            'required_capability' => $this->requiredCapability,
            'groupable' => $this->groupable,
            'enabled' => $this->enabled,
            'version' => $this->version,
            'text' => [
                'internal' => $this->scope->includes(Scope::Internal) ? ['title' => $this->textKey(Scope::Internal, 'title'), 'body' => $this->textKey(Scope::Internal, 'body')] : null,
                'external' => $this->scope->includes(Scope::External) ? ['title' => $this->textKey(Scope::External, 'title'), 'body' => $this->textKey(Scope::External, 'body')] : null,
            ],
        ];
    }
}
