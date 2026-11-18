<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Data;

/**
 * Per-workspace-type defaults (spec §0): roles, terminology keys, the responsible role, the role treated as «client»
 * (separate message per project, review on, simplified language), default work days and how tenant role slugs map
 * to profile roles. Pure data from config/notification_workspace_types.php: no code branches on a concrete type.
 */
final class WorkspaceTypeProfile
{
    /**
     * @param  list<string>  $roles  role keys; label = notifications_v2.roles.<key>
     * @param  array<string, string>  $terminology  term => translation key (e.g. client => notifications_v2.terms.agency.client)
     * @param  list<int>  $workDays  0 = Sunday
     * @param  array<string, string>  $memberRoles  tenant role slug => profile role key
     */
    public function __construct(
        public readonly string $key,
        public readonly array $roles,
        public readonly array $terminology,
        public readonly string $responsibleRole,
        public readonly ?string $clientRole,
        public readonly array $workDays,
        public readonly array $memberRoles,
        public readonly bool $seesSensitiveEvents = true,
    ) {}

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function isClientRole(string $role): bool
    {
        return $this->clientRole !== null && $this->clientRole === $role;
    }

    public function roleForMember(?string $tenantRoleSlug): string
    {
        $mapped = $tenantRoleSlug === null ? null : ($this->memberRoles[$tenantRoleSlug] ?? null);

        return $mapped !== null && $this->hasRole($mapped) ? $mapped : (in_array('other', $this->roles, true) ? 'other' : $this->roles[count($this->roles) - 1]);
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(string $key, array $a): self
    {
        return new self(
            key: $key,
            roles: array_values(array_map('strval', (array) ($a['roles'] ?? ['other']))),
            terminology: array_map('strval', (array) ($a['terminology'] ?? [])),
            responsibleRole: (string) ($a['responsible_role'] ?? 'other'),
            clientRole: isset($a['client_role']) ? (string) $a['client_role'] : null,
            workDays: array_values(array_map('intval', (array) ($a['work_days'] ?? [0, 1, 2, 3, 4]))),
            memberRoles: array_map('strval', (array) ($a['member_roles'] ?? [])),
            seesSensitiveEvents: (bool) ($a['sees_sensitive_events'] ?? true),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'roles' => array_map(static fn (string $r): array => ['key' => $r, 'label_key' => 'notifications_v2.roles.'.$r], $this->roles),
            'terminology' => $this->terminology,
            'responsible_role' => $this->responsibleRole,
            'client_role' => $this->clientRole,
            'work_days' => $this->workDays,
            'sees_sensitive_events' => $this->seesSensitiveEvents,
        ];
    }
}
