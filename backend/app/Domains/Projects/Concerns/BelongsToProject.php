<?php

declare(strict_types=1);

namespace App\Domains\Projects\Concerns;

use App\Domains\Projects\Context\ProjectContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Apply to project-owned models (bindings, sync runs, …). When a project is active in the request
 * (set by ResolveProject), every query is constrained to it AND new rows auto-fill project_id.
 * When no project is active (tenant-level listing), only the tenant scope applies.
 */
trait BelongsToProject
{
    public static function bootBelongsToProject(): void
    {
        static::addGlobalScope(new ProjectScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('project_id') === null) {
                $context = app(ProjectContext::class);
                if ($context->hasProject()) {
                    $model->setAttribute('project_id', $context->projectId());
                }
            }
        });
    }
}
