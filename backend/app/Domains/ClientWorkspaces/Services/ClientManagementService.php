<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Services;

use App\Domains\Audit\AuditLogger;
use App\Domains\ClientWorkspaces\Enums\ClientStatus;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Notifications\Services\NotificationDispatcher;
use App\Domains\Projects\Lifecycle\ArchiveEffects;
use App\Domains\Projects\Lifecycle\InvalidLifecycleTransition;
use App\Domains\Projects\Lifecycle\ProjectLifecycle;
use App\Domains\Projects\Lifecycle\ProjectStatus;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Applies classification / settings / archive changes to a client and records an immutable audit entry for
 * each (entity_type = client_workspace) — which is exactly what the Activity timeline reads back. Archive is
 * a lifecycle pause (never a delete): projects, campaigns and reports are untouched; restore is a separate act.
 */
final class ClientManagementService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationDispatcher $notifications,
        private readonly ProjectLifecycle $lifecycle,
    ) {}

    /** Editable classification fields (whitelist — nothing else is mass-assignable here). */
    private const CLASSIFICATION = ['client_status', 'service_level', 'industry', 'owner_id', 'priority',
        'default_currency', 'timezone', 'language', 'tags', 'enabled_services'];

    /**
     * @param  array<string,mixed>  $data  already-validated subset of CLASSIFICATION
     */
    public function updateClassification(ClientWorkspace $client, array $data, User $actor): ClientWorkspace
    {
        $before = $client->only(self::CLASSIFICATION);

        // PROJECT-LIFECYCLE-001 — the client state moves only through the one transition function (logged there).
        $statusChanged = false;
        if (array_key_exists('client_status', $data) && (string) $data['client_status'] !== (string) $client->client_status) {
            $this->lifecycle->transitionClient($client, ClientStatus::from((string) $data['client_status']), $actor, 'classification');
            $statusChanged = true;
        }
        unset($data['client_status']);

        $client->fill(array_intersect_key($data, array_flip(self::CLASSIFICATION)));
        $changed = $client->getDirty();
        $client->save();
        if ($statusChanged) {
            $changed['client_status'] = $client->client_status;
        }

        if ($changed !== []) {
            $this->audit->log(
                'client.classification_updated', 'client_workspace', (string) $client->id,
                array_intersect_key($before, $changed), $client->only(array_keys($changed)),
            );
        }

        // A client moving INTO "needs attention" notifies its owner (deduped so re-saves don't spam).
        if (array_key_exists('client_status', $changed)
            && $client->client_status === 'needs_attention'
            && $client->owner_id !== null) {
            $this->notifications->dispatch([
                'tenant_id' => $client->tenant_id,
                'user_id' => (int) $client->owner_id,
                'client_workspace_id' => $client->id,
                'type' => 'client_needs_attention',
                'severity' => 'warning',
                'title' => "Client needs attention: {$client->name}",
                'source' => 'clients',
                'entity_type' => 'client_workspace',
                'entity_id' => $client->id,
                /*
                   * Explicit, because `clients` exists ONLY under /agency.
                   *
                   * A portal-relative link resolves against the portal the reader is LOOKING at,
                   * not the one their membership belongs to — so an operator who happens to be in
                   * /app when the bell rings gets `/app/clients/…`, which is not a route. Naming
                   * the portal is right in exactly this case and no other: same destination from
                   * /agency, a working one from anywhere else.
                   */
                'action_url' => "/agency/clients/{$client->id}",
                'dedup_extra' => 'needs_attention',
            ]);
        }

        return $client->refresh();
    }

    /**
     * Update client-level settings (report identity, report prefs, client alert prefs, display prefs).
     * Stored in the `settings` bag; logo lives in `branding`. Merges (does not clobber unrelated keys).
     *
     * @param  array<string,mixed>  $settings
     */
    public function updateSettings(ClientWorkspace $client, array $settings, User $actor, ?string $name = null, ?array $branding = null): ClientWorkspace
    {
        $before = ['settings' => $client->settings, 'name' => $client->name, 'branding' => $client->branding];

        $client->settings = array_replace($client->settings ?? [], $settings);
        if ($name !== null) {
            $client->name = $name;
        }
        if ($branding !== null) {
            $client->branding = array_replace($client->branding ?? [], $branding);
        }
        $client->save();

        $this->audit->log(
            'client.settings_updated', 'client_workspace', (string) $client->id,
            $before, ['settings' => $client->settings, 'name' => $client->name, 'branding' => $client->branding],
        );

        return $client->refresh();
    }

    /**
     * PROJECT-LIFECYCLE-001 §8 (N2) — what «أرشفة العميل» archives with it: every project of the client that is not
     * already archived/deleted (draft, active, paused), and the accounts among them shared with another client's project.
     * Read-only.
     *
     * @return array{client: array{id: string, name: string}, projects: list<array{id: string, name: string, status: string}>, shared_accounts: list<array<string,mixed>>, ad_platforms_untouched: true}
     */
    public function archiveImpact(ClientWorkspace $client): array
    {
        $projects = $this->projectsArchivedWith($client);
        $effects = app(ArchiveEffects::class);
        $shared = [];
        foreach ($projects as $project) {
            foreach ($effects->sharedAccounts($project) as $account) {
                // Shared only between projects of THIS client is not a cross-client warning, but still listed with the others.
                $shared[$account['external_account_id']] = $account;
            }
        }

        return [
            'client' => ['id' => (string) $client->getKey(), 'name' => (string) $client->name],
            'projects' => $projects->map(static fn (Project $p): array => ['id' => (string) $p->getKey(), 'name' => (string) $p->name, 'status' => (string) $p->status])->values()->all(),
            'shared_accounts' => array_values($shared),
            'ad_platforms_untouched' => true,
        ];
    }

    /**
     * Archive a client (§8): every project of it that is draft/active/paused is archived first, through the lifecycle
     * (archive effects, reason `client_archived`), then the client state moves to `archived`. Restoring the client
     * un-archives exactly those projects. Never deletes anything.
     */
    public function archive(ClientWorkspace $client, User $actor, ?string $reason = null): ClientWorkspace
    {
        if ($client->isArchived()) {
            return $client;
        }
        $before = ['client_status' => $client->client_status, 'archived_at' => null];
        DB::transaction(function () use ($client, $actor, $reason): void {
            foreach ($this->projectsArchivedWith($client) as $project) {
                $this->lifecycle->archive($project, $actor, false, 'client_archived');
            }
            // Kept for rows archived before the lifecycle columns existed; the machine records `previous_status`.
            $client->forceFill(['settings' => array_replace($client->settings ?? [], ['status_before_archive' => $client->client_status])])->save();
            $this->lifecycle->transitionClient($client, ClientStatus::Archived, $actor, $reason);
        });

        $this->audit->log('client.archived', 'client_workspace', (string) $client->id, $before,
            ['client_status' => 'archived', 'archived_at' => optional($client->archived_at)->toIso8601String()], $reason);

        return $client->refresh();
    }

    /** Restore a previously archived client (separate, explicit action). */
    public function restore(ClientWorkspace $client, User $actor): ClientWorkspace
    {
        if (! $client->isArchived()) {
            return $client;
        }
        $before = ['client_status' => $client->client_status, 'archived_at' => optional($client->archived_at)->toIso8601String()];
        $restoreTo = (string) ($client->previous_status ?? $client->settings['status_before_archive'] ?? 'active');
        $target = ClientStatus::tryFrom($restoreTo);
        if ($target === null || in_array($target, [ClientStatus::Archived, ClientStatus::Deleted], true)) {
            $target = ClientStatus::Active;
        }
        DB::transaction(function () use ($client, $target, $actor): void {
            $this->lifecycle->transitionClient($client, $target, $actor, 'restore');
            // The projects archived WITH the client come back with it (each to its own previous state).
            foreach ($this->projectsArchivedByClient($client) as $project) {
                try {
                    $this->lifecycle->unarchive($project, $actor, true);
                } catch (InvalidLifecycleTransition) {
                    // changed since (e.g. deleted) — left as it is
                }
            }
        });

        $this->audit->log('client.restored', 'client_workspace', (string) $client->id, $before,
            ['client_status' => $client->client_status, 'archived_at' => null]);

        return $client->refresh();
    }

    /** @return Collection<int, Project> */
    private function projectsArchivedWith(ClientWorkspace $client): Collection
    {
        return Project::withoutGlobalScopes()
            ->where('tenant_id', $client->tenant_id)
            ->where('client_workspace_id', $client->getKey())
            ->listed()
            ->orderBy('name')
            ->get();
    }

    /**
     * Archived projects of this client whose latest transition is the archive done WITH the client.
     *
     * @return Collection<int, Project>
     */
    private function projectsArchivedByClient(ClientWorkspace $client): Collection
    {
        return Project::withoutGlobalScopes()
            ->where('tenant_id', $client->tenant_id)
            ->where('client_workspace_id', $client->getKey())
            ->inLifecycle(ProjectStatus::Archived)
            ->get()
            ->filter(static fn (Project $p): bool => DB::table('project_status_transitions')->where('project_id', $p->getKey())
                ->orderByDesc('created_at')->orderByDesc('id')->value('reason') === 'client_archived')
            ->values();
    }
}
