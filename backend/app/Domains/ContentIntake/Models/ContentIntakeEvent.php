<?php

declare(strict_types=1);

namespace App\Domains\ContentIntake\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One line of the decision trail. Append-only: a saved row can never be changed or deleted through the app
 * (the item name is a SNAPSHOT, so a later rename does not rewrite history).
 */
final class ContentIntakeEvent extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['meta' => 'array'];

    protected static function booted(): void
    {
        self::updating(static function (): void {
            throw new LogicException('The content decision trail is append-only.');
        });
        self::deleting(static function (): void {
            throw new LogicException('The content decision trail is append-only.');
        });
    }
}
