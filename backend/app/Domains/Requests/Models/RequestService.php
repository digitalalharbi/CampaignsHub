<?php

declare(strict_types=1);

namespace App\Domains\Requests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One selected paid-media service on an external request (canonical). Follows the parent ExternalRequest's
 * tenancy — it is reached only through a tenant-scoped request, so it carries no global scope of its own.
 *
 * @property int $id
 * @property string $request_id
 * @property string $service_key
 * @property string|null $category_key
 * @property array<string,mixed>|null $details
 * @property int $position
 */
final class RequestService extends Model
{
    protected $fillable = ['request_id', 'service_key', 'category_key', 'details', 'position'];

    protected $casts = [
        'details' => 'array',
        'position' => 'int',
    ];

    /** @return BelongsTo<ExternalRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ExternalRequest::class, 'request_id');
    }
}
