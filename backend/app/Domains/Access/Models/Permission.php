<?php

declare(strict_types=1);

namespace App\Domains\Access\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A fine-grained, global permission such as "clients.view" or "campaigns.launch".
 */
final class Permission extends Model
{
    protected $fillable = ['key', 'group', 'description'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
