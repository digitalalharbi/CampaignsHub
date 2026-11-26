<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Projects\Lifecycle\LiveProjects;
use Illuminate\Support\Collection;

/**
 * SHARED-ACCOUNT-PROJECTS-001 (Owner 2026-11-26) — one ad account feeding more than one project, split by campaign.
 *
 * ## The case
 *
 * An agency runs two clients' campaigns from ONE ad account (the Owner's «hanka» Meta account carries Hankah's
 * campaigns and Buzz's). The one-project-per-account rule (SEC-CLUSTER-2) refused the second project outright, so
 * Buzz's campaigns could never reach Buzz's reports.
 *
 * ## The rule, and why it cannot double count
 *
 *  - The account keeps ONE home binding (the project that linked it first, the one `AccountAssignment::projectIdFor`
 *    answers). The account is fetched once, under that binding, exactly as before — no second fetch, no second seat.
 *  - Every other project the account is shared with holds a SHARED binding: the same account row, `is_primary=false`,
 *    and `settings.shared_scope` naming the campaigns that belong to it (by id, or by a piece of the name).
 *  - Every campaign is filed under exactly ONE project: the first shared binding whose scope claims it, otherwise the
 *    home project. Ad sets, ads, creatives and every metric row already take their project from the campaign row, so
 *    a figure lands in one project and only one. The sum of the projects is the account.
 *  - A shared binding starts with an EMPTY scope: it feeds nothing until somebody picks its campaigns.
 *
 * The fences stay: same tenant only, and an account discovered for one client workspace never feeds another client's
 * project. Stores and GA4 properties are not shareable — this is about ad accounts.
 */
final class SharedAccounts
{
    public const KEY = 'shared_scope';

    /**
     * Per instance, per account: an importer asks once per campaign row, and the answer cannot change inside one run.
     *
     * @var array<string, Collection<int, ProjectIntegrationBinding>>
     */
    private array $cache = [];

    /** Whether this binding is a SHARED one (it feeds only the campaigns its scope names). */
    public static function isShared(ProjectIntegrationBinding $binding): bool
    {
        return is_array($binding->settings) && array_key_exists(self::KEY, $binding->settings);
    }

    /**
     * @return array{campaign_external_ids: list<string>, name_contains: list<string>}
     */
    public static function scopeOf(ProjectIntegrationBinding $binding): array
    {
        $raw = is_array($binding->settings) ? (array) ($binding->settings[self::KEY] ?? []) : [];

        return [
            'campaign_external_ids' => array_values(array_unique(array_map('strval', array_filter((array) ($raw['campaign_external_ids'] ?? []), static fn ($v): bool => is_scalar($v) && (string) $v !== '')))),
            'name_contains' => array_values(array_unique(array_filter(array_map(static fn ($v): string => trim((string) $v), (array) ($raw['name_contains'] ?? [])), static fn (string $v): bool => $v !== ''))),
        ];
    }

    /** A new, empty scope — a shared binding feeds nothing until its campaigns are chosen. */
    public static function emptySettings(?array $settings = null): array
    {
        return array_merge((array) $settings, [self::KEY => ['campaign_external_ids' => [], 'name_contains' => []]]);
    }

    /**
     * The SHARED bindings of this account that may receive data now: active, syncing, into a running project.
     * Oldest first, so when two scopes claim the same campaign the earlier one wins (the API refuses that overlap).
     *
     * @return Collection<int, ProjectIntegrationBinding>
     */
    public function sharedBindings(ExternalAccount $account): Collection
    {
        $key = (string) $account->getKey();

        return $this->cache[$key] ??= $this->loadSharedBindings($account);
    }

    /** Drop what this instance read, so the next question is answered from the database again. */
    public function forget(): void
    {
        $this->cache = [];
    }

    /** @return Collection<int, ProjectIntegrationBinding> */
    private function loadSharedBindings(ExternalAccount $account): Collection
    {
        return ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('external_account_id', $account->getKey())
            ->where('is_active', true)
            ->where('sync_enabled', true)
            ->whereIn('project_id', LiveProjects::runningIdsQuery())
            ->orderBy('created_at')
            ->get()
            ->filter(static fn (ProjectIntegrationBinding $b): bool => self::isShared($b))
            ->values();
    }

    /**
     * The one project a campaign of this account is filed under, or null when no project may take it.
     *
     * `$homeProjectId` is the project the sync runs for. A campaign a shared scope claims goes to that shared project;
     * any other campaign stays home. When the run's project is itself only a SHARED binding (the home project was
     * unlinked), it takes only the campaigns its own scope names — the rest are filed nowhere rather than into a
     * client's report they were never chosen for.
     */
    public function projectForCampaign(ExternalAccount $account, string $homeProjectId, string $externalCampaignId, ?string $campaignName = null): ?string
    {
        $homeIsShared = false;

        foreach ($this->sharedBindings($account) as $binding) {
            if ((string) $binding->project_id === $homeProjectId) {
                $homeIsShared = true;
            }
            if (self::claims(self::scopeOf($binding), $externalCampaignId, $campaignName)) {
                return (string) $binding->project_id;
            }
        }

        return $homeIsShared ? null : $homeProjectId;
    }

    /**
     * Every project this account's run may write to: the home project and each running shared one.
     *
     * @return list<string>
     */
    public function projectsFed(ExternalAccount $account, ?string $homeProjectId): array
    {
        $ids = $homeProjectId === null ? [] : [$homeProjectId];
        foreach ($this->sharedBindings($account) as $binding) {
            $ids[] = (string) $binding->project_id;
        }

        return array_values(array_unique($ids));
    }

    /** @param  array{campaign_external_ids: list<string>, name_contains: list<string>}  $scope */
    public static function claims(array $scope, string $externalCampaignId, ?string $campaignName): bool
    {
        if ($externalCampaignId !== '' && in_array($externalCampaignId, $scope['campaign_external_ids'], true)) {
            return true;
        }

        if ($campaignName === null || $campaignName === '') {
            return false;
        }

        foreach ($scope['name_contains'] as $needle) {
            if (mb_stripos($campaignName, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
