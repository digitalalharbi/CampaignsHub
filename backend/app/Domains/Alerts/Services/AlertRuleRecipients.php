<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Services;

use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Notifications\Services\DigestScope;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Models\User;

/**
 * ALERT-RULES-MANAGE-001 — who an alert rule may name, and who it names for one alert.
 *
 * A rule's `recipients` is `{project_manager: bool, user_ids: int[]}`, or null. Null is the behaviour every rule had
 * before this existed: everybody in the alert's scope who chose alert email. Set, it is the rule's own list.
 *
 * Only members of THIS workspace on an internal portal (app / agency) can be named. An alert carries campaign names
 * and figures; a client-portal login or an outside address is never a recipient here — outside recipients have their
 * own budget-only path (OWNER_NOTIFICATIONS_RULES §8). A member is nameable on a project rule only when that project
 * is inside their membership ceiling, so a rule cannot widen anybody's reach.
 */
final class AlertRuleRecipients
{
    /** How many people one rule may name. A rule naming the whole team is the default, not a list. */
    public const MAX_NAMED = 20;

    public function __construct(private readonly DigestScope $scope) {}

    /**
     * The members a rule on this project (or workspace-wide when null) may name.
     *
     * @return list<array{id:int,name:string,email:string}>
     */
    public function options(string $tenantId, ?string $projectId): array
    {
        $userIds = Membership::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereIn('portal', [Portal::App->value, Portal::Agency->value])
            ->pluck('user_id')
            ->unique()
            ->all();

        return User::query()->whereIn('id', $userIds)->orderBy('name')->get()
            ->filter(fn (User $u): bool => $u->email !== null && $this->reaches($u, $tenantId, $projectId))
            ->map(fn (User $u): array => ['id' => (int) $u->getKey(), 'name' => (string) $u->name, 'email' => (string) $u->email])
            ->values()
            ->all();
    }

    /**
     * The ids in $userIds this rule may NOT name.
     *
     * @param  list<int>  $userIds
     * @return list<int>
     */
    public function refused(string $tenantId, ?string $projectId, array $userIds): array
    {
        $allowed = array_column($this->options($tenantId, $projectId), 'id');

        return array_values(array_diff(array_map('intval', $userIds), $allowed));
    }

    /**
     * Whether the rule names its own recipients (false = the default, everyone in scope who chose alert email).
     */
    public static function names(AlertRule $rule): bool
    {
        return self::normalise($rule->recipients) !== null;
    }

    /**
     * The people a rule's alert about $projectId goes to, or null when the rule names nobody (the default path).
     *
     * Every person is re-checked here, at send time: a membership revoked or narrowed after the rule was saved
     * drops them, and the project's manager is read now rather than remembered.
     *
     * @return list<User>|null
     */
    public function resolve(AlertRule $rule, ?string $projectId): ?array
    {
        $recipients = self::normalise($rule->recipients);
        if ($recipients === null) {
            return null;
        }

        $tenantId = (string) $rule->tenant_id;
        $ids = $recipients['user_ids'];
        if ($recipients['project_manager'] && $projectId !== null) {
            $manager = Project::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($projectId)->value('account_manager_id');
            if ($manager !== null) {
                $ids[] = (int) $manager;
            }
        }

        $allowed = array_column($this->options($tenantId, $projectId), 'id');

        return User::query()->whereIn('id', array_values(array_intersect(array_unique($ids), $allowed)))->get()->all();
    }

    /**
     * One shape, or null for «the default». A list naming nobody and not asking for the manager is the default too.
     *
     * @return array{project_manager:bool,user_ids:list<int>}|null
     */
    public static function normalise(mixed $recipients): ?array
    {
        if (! is_array($recipients)) {
            return null;
        }
        $manager = (bool) ($recipients['project_manager'] ?? false);
        $ids = array_values(array_unique(array_map('intval', (array) ($recipients['user_ids'] ?? []))));

        return ! $manager && $ids === [] ? null : ['project_manager' => $manager, 'user_ids' => $ids];
    }

    private function reaches(User $user, string $tenantId, ?string $projectId): bool
    {
        $ceiling = $this->scope->ceilingIdsFor($user, $tenantId);

        return $projectId === null ? $ceiling !== [] : in_array($projectId, $ceiling, true);
    }
}
