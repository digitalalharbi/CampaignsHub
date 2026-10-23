<?php

declare(strict_types=1);

namespace App\Domains\Forms\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * FORMS-HUB-001 — one definition of a form. Once `published_at` is set it is never edited: a change is a new version.
 *
 * @property string $id
 * @property string $lead_form_id
 * @property int $version
 * @property array<string,mixed> $definition
 */
final class LeadFormVersion extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = ['tenant_id', 'lead_form_id', 'version', 'definition', 'checksum', 'published_at', 'created_by'];

    protected $casts = ['definition' => 'array', 'published_at' => 'datetime'];

    protected static function booted(): void
    {
        self::updating(function (self $v): void {
            if ($v->getOriginal('published_at') !== null && $v->isDirty(['definition', 'checksum', 'version'])) {
                throw new \LogicException('A published form version is immutable; save a new version instead.');
            }
        });
    }
}
