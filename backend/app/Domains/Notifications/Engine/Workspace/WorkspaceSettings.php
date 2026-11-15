<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Workspace;

/** A workspace's notification settings with every default filled in (spec §0 layer 2). Read through {@see WorkspaceSettingsRepository}. */
final class WorkspaceSettings
{
    /**
     * @param  list<int>  $workDays  0 = Sunday
     * @param  array{enabled: bool, start: string, end: string}  $quietHours
     * @param  list<string>  $holidays  Y-m-d
     * @param  list<string>  $orgDomains  lower-case
     * @param  array<string, array{roles?: list<string>, channels?: list<string>, enabled?: bool}>  $routing
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $workspaceName,
        public readonly string $typeKey,
        public readonly string $timezone,
        public readonly array $workDays,
        public readonly array $quietHours,
        public readonly array $holidays,
        public readonly array $orgDomains,
        public readonly array $routing,
        public readonly array $settings,
    ) {}

    public function isOrgAddress(string $email): bool
    {
        $domain = strtolower((string) substr((string) strrchr($email, '@'), 1));

        return $domain !== '' && in_array($domain, $this->orgDomains, true);
    }

    /** @return array{roles?: list<string>, channels?: list<string>, enabled?: bool} */
    public function routeFor(string $eventKey): array
    {
        return $this->routing[$eventKey] ?? [];
    }
}
