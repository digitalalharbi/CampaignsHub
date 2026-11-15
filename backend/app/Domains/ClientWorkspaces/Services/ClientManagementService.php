<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Services;

use App\Domains\Audit\AuditLogger;
use App\Domains\ClientWorkspaces\Enums\ClientStatus;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Notifications\Services\NotificationDispatcher;
use App\Domains\Projects\Lifecycle\ProjectLifecycle;
use App\Models\User;

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

    /** Archive = pause new operations; keeps all projects/campaigns/reports. Idempotent. */
    public function archive(ClientWorkspace $client, User $actor, ?string $reason = null): ClientWorkspace
    {
        if ($client->isArchived()) {
            return $client;
        }
        $before = ['client_status' => $client->client_status, 'archived_at' => null];
        // Kept for rows archived before the lifecycle columns existed; the machine records `previous_status`.
        $client->forceFill(['settings' => array_replace($client->settings ?? [], ['status_before_archive' => $client->client_status])])->save();
        $this->lifecycle->transitionClient($client, ClientStatus::Archived, $actor, $reason);

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
        $this->lifecycle->transitionClient($client, $target, $actor, 'restore');

        $this->audit->log('client.restored', 'client_workspace', (string) $client->id, $before,
            ['client_status' => $client->client_status, 'archived_at' => null]);

        return $client->refresh();
    }
}
