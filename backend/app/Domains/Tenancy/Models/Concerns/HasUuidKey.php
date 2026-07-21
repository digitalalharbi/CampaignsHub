<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Models\Concerns;

use Illuminate\Support\Str;

/**
 * For models whose primary key is a UUID string (e.g. Tenant, Workspace).
 * Generates the key on create and configures Eloquent for a non-incrementing string key.
 */
trait HasUuidKey
{
    public static function bootHasUuidKey(): void
    {
        static::creating(function ($model): void {
            if (empty($model->getKey())) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    public function initializeHasUuidKey(): void
    {
        $this->incrementing = false;
        $this->keyType = 'string';
    }
}
