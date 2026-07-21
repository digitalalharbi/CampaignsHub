<?php

declare(strict_types=1);

namespace App\Domains\Tasks\Models;

use App\Domains\Projects\Concerns\BelongsToProject;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Task extends Model
{
    use BelongsToProject;
    use BelongsToTenant;
    use HasUuidKey;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'client_workspace_id', 'project_id', 'assignee_id', 'created_by',
        'title', 'description', 'status', 'priority', 'start_date', 'due_date', 'checklist', 'meta',
    ];

    protected $casts = [
        'checklist' => 'array',
        'meta' => 'array',
        'start_date' => 'date',
        'due_date' => 'date',
    ];

    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->due_date->isPast()
            && ! in_array($this->status, ['completed', 'cancelled'], true);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
