<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Lifecycle\LiveProjects;
use App\Domains\Projects\Lifecycle\ProjectStateMachine;
use App\Domains\Projects\Lifecycle\ProjectStatus;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A project inside a client workspace. Owns its own sources/campaigns/reports (bindings added by
 * their domains). Tenant-scoped and workspace-scoped.
 *
 * @property array<string,mixed>|null $list_summary
 *                                                  PROJECT-LIST-SURFACE-001 — the listing's per-card facts, attached in memory by
 *                                                  `ProjectController::index` and read by `ProjectResource`. TRANSIENT: there is no such column,
 *                                                  it is absent from `$fillable` so nothing can mass-assign it, and a project read anywhere else
 *                                                  simply does not carry it. Declared here because it is a real attribute at runtime and static
 *                                                  analysis is entitled to know its type.
 * @property array<string,mixed>|null $lifecycle_facts
 *                                                     The same facts for ONE project, read by `show` to derive its status;
 *                                                     never returned.
 *
 * PROJECT-LIFECYCLE-001 — `status` is one of {@see ProjectStatus}; it is written ONLY by
 * {@see ProjectStateMachine::transition()} (an update that changes it anywhere else throws). «Live» projects are
 * selected with {@see self::scopeLive()} / {@see LiveProjects}, never a hand-written status filter.
 * @property string $status
 * @property Carbon|null $archived_at
 * @property int|null $archived_by
 * @property int|null $deleted_by
 * @property Carbon|null $purge_after
 * @property string|null $previous_status
 */
final class Project extends Model
{
    use BelongsToTenant;
    use HasUuidKey;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'client_workspace_id', 'account_manager_id', 'name', 'status', 'setup_completion', 'meta',
        // `status` stays fillable for CREATION only — an update of it outside ProjectStateMachine throws.
    ];

    protected $casts = [
        'meta' => 'array',
        'setup_completion' => 'integer',
        'archived_at' => 'datetime',
        'purge_after' => 'datetime',
        'purged_at' => 'datetime',
        'purge_warned_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // A new project starts in a canonical state; a legacy value (`setup`, …) is read as what it behaved as.
        self::creating(static function (self $project): void {
            $project->status = ProjectStatus::normalise($project->status ?? null)->value;
        });

        // Rule 3 — the status changes in ONE function. Anything else is a bug, caught here and by the source guard.
        self::updating(static function (self $project): void {
            if ($project->isDirty('status') && ! ProjectStateMachine::isWriting()) {
                throw new LogicException('projects.status is written only by ProjectStateMachine::transition().');
            }
        });
    }

    public function lifecycleStatus(): ProjectStatus
    {
        return ProjectStatus::normalise($this->status);
    }

    /**
     * Live projects: active + paused (rule 5). The one Eloquent entry point.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLive(Builder $query): Builder
    {
        return LiveProjects::constrain($query, $this->getTable());
    }

    /**
     * Running projects: active only (sync, daily digests).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRunning(Builder $query): Builder
    {
        return LiveProjects::constrainRunning($query, $this->getTable());
    }

    /**
     * Projects in exactly these lifecycle states (the «المؤرشفة» list, the trash, a filter). The one explicit-state
     * lookup — no hand-written `status` filter elsewhere.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInLifecycle(Builder $query, ProjectStatus ...$statuses): Builder
    {
        return $query->whereIn($this->getTable().'.status', array_map(static fn (ProjectStatus $s): string => $s->value, $statuses));
    }

    /**
     * Listed projects: draft + active + paused (management lists, «link to project» pickers).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeListed(Builder $query): Builder
    {
        return LiveProjects::constrainListed($query, $this->getTable());
    }

    /** @return BelongsTo<ClientWorkspace, $this> */
    public function clientWorkspace(): BelongsTo
    {
        return $this->belongsTo(ClientWorkspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function accountManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_manager_id');
    }
}
