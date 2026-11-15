<?php

declare(strict_types=1);

namespace App\Domains\Projects\Lifecycle;

use App\Domains\Notifications\Services\NotificationDispatcher;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * PROJECT-LIFECYCLE-001 §4 (builder N2) — the internal notices to the WORKSPACE OWNER(S): on soft delete, and 3 days
 * before the automatic permanent delete.
 *
 * Internal events: `docs/notifications-v2/CONTRACTS.md` is not on this base, so these go through the existing in-app
 * mechanism ({@see NotificationDispatcher} — dedup, preferences, delivery log). Merge point: when notifications-v2 lands,
 * register `project.deleted` / `project.purge_soon` there. Owner only: never a client, never an external recipient.
 */
final class LifecycleNotices
{
    public const DELETED = 'project.deleted';

    public const PURGE_SOON = 'project.purge_soon';

    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function deleted(Project $project, ?User $actor): int
    {
        $date = optional($project->purge_after)->format('Y-m-d') ?? '—';
        $by = $actor === null ? null : (string) $actor->name;

        return $this->toOwners($project, ['type' => 'project.deleted', 'severity' => 'warning'],
            title: "Project «{$project->name}» moved to the trash",
            titleAr: "نُقل المشروع «{$project->name}» إلى المحذوفات",
            message: 'Deleted by '.($by ?? 'the system').". It can be restored until {$date}; after that it is deleted permanently. The ad platforms are not affected.",
            messageAr: 'حذفه '.($by ?? 'النظام')."، ويمكن استعادته حتى {$date}، وبعدها يُحذف نهائيًا. المنصات الإعلانية لا تتأثر.",
        );
    }

    public function purgeSoon(Project $project): int
    {
        $date = optional($project->purge_after)->format('Y-m-d') ?? '—';

        return $this->toOwners($project, ['type' => 'project.purge_soon', 'severity' => 'warning'],
            title: "Project «{$project->name}» will be deleted permanently on {$date}",
            titleAr: "سيُحذف المشروع «{$project->name}» نهائيًا في {$date}",
            message: 'Restore it from the trash before then to keep it. The ad platforms are not affected.',
            messageAr: 'استعده من المحذوفات قبل ذلك للإبقاء عليه. المنصات الإعلانية لا تتأثر.',
        );
    }

    /** @return list<int> active workspace owners of the project's tenant */
    public function ownerIds(string $tenantId): array
    {
        return DB::table('users')
            ->join('role_user', 'role_user.user_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->join('memberships', 'memberships.user_id', '=', 'users.id')
            ->where('memberships.tenant_id', $tenantId)
            ->where('memberships.status', 'active')
            ->where('roles.slug', 'tenant-owner')
            ->where('roles.tenant_id', $tenantId)
            ->whereNull('users.disabled_at')
            ->distinct()
            ->pluck('users.id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  array{type: string, severity: string}  $kind  written as literals by the callers, so NotificationChannelPolicyTest reads the types
     */
    private function toOwners(Project $project, array $kind, string $title, string $titleAr, string $message, string $messageAr): int
    {
        ['type' => $type, 'severity' => $severity] = $kind;
        $sent = 0;
        foreach ($this->ownerIds((string) $project->tenant_id) as $userId) {
            $n = $this->dispatcher->dispatch([
                'tenant_id' => (string) $project->tenant_id,
                'user_id' => $userId,
                'client_workspace_id' => $project->client_workspace_id,
                'project_id' => (string) $project->getKey(),
                'type' => $type,
                'severity' => $severity,
                'title' => $title,
                'title_ar' => $titleAr,
                'message' => $message,
                'message_ar' => $messageAr,
                'source' => 'project_lifecycle',
                'entity_type' => 'project',
                'entity_id' => (string) $project->getKey(),
                'dedup_extra' => $type.':'.optional($project->purge_after)->toDateString(),
            ]);
            $sent += $n === null ? 0 : 1;
        }

        return $sent;
    }
}
