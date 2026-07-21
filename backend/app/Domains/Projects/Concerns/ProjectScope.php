<?php

declare(strict_types=1);

namespace App\Domains\Projects\Concerns;

use App\Domains\Projects\Context\ProjectContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Named global scope so it can be removed via withoutGlobalScope(ProjectScope::class) when a query
 * legitimately spans projects (e.g. revoke impact, sharing checks). When a project is active it
 * constrains every query to that project.
 */
final class ProjectScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(ProjectContext::class);
        if ($context->hasProject()) {
            $builder->where($model->getTable().'.project_id', $context->projectId());
        }
    }
}
