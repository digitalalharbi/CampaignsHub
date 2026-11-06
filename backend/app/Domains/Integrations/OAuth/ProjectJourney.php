<?php

declare(strict_types=1);

namespace App\Domains\Integrations\OAuth;

use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use App\Support\Frontend;
use Illuminate\Validation\Rule;

/**
 * INTEG-PROJECT-LINK-STORES-GA4-001 (Owner 2026-11-06) — the project half of an authorisation, shared by the
 * store and measurement sign-ins so both carry the project exactly as the advertising one does.
 *
 * A journey is opened FOR a project: the start request names it, the single-use state carries it across the
 * provider's pages, and the public callback proves it again before a code is exchanged. Nothing in the
 * returning address is believed about which project it was for.
 */
final class ProjectJourney
{
    /** The two portals a project page lives in. */
    public const PORTALS = ['app', 'agency'];

    /** @return array<string, list<mixed>> validation rules for the two fields a start request may carry */
    public static function rules(string $tenantId): array
    {
        return [
            'project_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('projects', 'id')
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')],
            // A prefix to land on, never a permission.
            'return_portal' => ['sometimes', 'nullable', Rule::in(self::PORTALS)],
        ];
    }

    /** May this person link a source to this project — asked before a state exists. */
    public static function allows(User $user, string $projectId): bool
    {
        return app(ProjectAbilities::class)->allows($user, $projectId, ProjectCapability::INTEGRATIONS_MANAGE);
    }

    /**
     * What a start request adds to the state.
     *
     * @param  array<string,mixed>  $validated
     * @return array<string,string>
     */
    public static function stateOf(array $validated): array
    {
        return array_filter([
            'project_id' => $validated['project_id'] ?? null,
            'return_portal' => $validated['return_portal'] ?? null,
        ], static fn ($v): bool => is_string($v) && $v !== '');
    }

    /** @param  array<string,mixed>|null  $record */
    public static function projectId(?array $record): ?string
    {
        return isset($record['project_id']) ? (string) $record['project_id'] : null;
    }

    /**
     * Does the project the state names still belong to this tenant, and may its issuer still link to it?
     * Asked on the callback, minutes later, before anything is exchanged or opened.
     *
     * @param  array<string,mixed>  $record
     */
    public static function mayStillLink(array $record, string $tenantId, string $projectId): bool
    {
        $exists = Project::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('id', $projectId)->whereNull('deleted_at')->exists();
        $user = isset($record['user_id']) ? User::find((int) $record['user_id']) : null;

        return $exists
            && $user !== null
            && $user->hasPermission('integrations.connect')
            && self::allows($user, $projectId);
    }

    /**
     * The project page a journey returns to — on success, refusal and failure alike — or null for a
     * workspace-wide authorisation. A place to land, not a decision.
     *
     * @param  array<string,mixed>|null  $record
     */
    public static function returnUrl(?array $record): ?string
    {
        $projectId = self::projectId($record);
        if ($projectId === null) {
            return null;
        }

        $portal = in_array($record['return_portal'] ?? null, self::PORTALS, true) ? (string) $record['return_portal'] : 'app';

        return Frontend::origin().'/'.$portal.'/projects/'.$projectId.'/integrations';
    }
}
