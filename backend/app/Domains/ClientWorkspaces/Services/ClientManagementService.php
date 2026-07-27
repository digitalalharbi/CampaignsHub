<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Services;

use App\Domains\Audit\AuditLogger;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Applies classification / settings / archive changes to a client and records an immutable audit entry for
 * each (entity_type = client_workspace) — which is exactly what the Activity timeline reads back. Archive is
 * a lifecycle pause (never a delete): projects, campaigns and reports are untouched; restore is a separate act.
 */
final class ClientManagementService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Editable classification fields (whitelist — nothing else is mass-assignable here). */
    private const CLASSIFICATION = ['client_status', 'service_level', 'industry', 'owner_id', 'priority',
        'default_currency', 'timezone', 'language'];

    /**
     * @param  array<string,mixed>  $data  already-validated subset of CLASSIFICATION
     */
    public function updateClassification(ClientWorkspace $client, array $data, User $actor): ClientWorkspace
    {
        $before = $client->only(self::CLASSIFICATION);
        $client->fill(array_intersect_key($data, array_flip(self::CLASSIFICATION)));
        $changed = $client->getDirty();
        $client->save();

        if ($changed !== []) {
            $this->audit->log(
                'client.classification_updated', 'client_workspace', (string) $client->id,
                array_intersect_key($before, $changed), $client->only(array_keys($changed)),
            );
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
        $client->forceFill([
            'archived_at' => Carbon::now(),
            'archived_by' => $actor->id,
            'client_status' => 'archived',
            'settings' => array_replace($client->settings ?? [], ['status_before_archive' => $client->client_status]),
        ])->save();

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
        $restoreTo = $client->settings['status_before_archive'] ?? 'active';
        $client->forceFill([
            'archived_at' => null,
            'archived_by' => null,
            'client_status' => $restoreTo === 'archived' ? 'active' : $restoreTo,
        ])->save();

        $this->audit->log('client.restored', 'client_workspace', (string) $client->id, $before,
            ['client_status' => $client->client_status, 'archived_at' => null]);

        return $client->refresh();
    }
}
