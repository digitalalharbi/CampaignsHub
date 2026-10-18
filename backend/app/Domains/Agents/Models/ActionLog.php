<?php

declare(strict_types=1);

namespace App\Domains\Agents\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/** One phase of one proposal, append-only: nothing in the product updates or deletes these rows. */
final class ActionLog extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    public const UPDATED_AT = null;

    protected $table = 'action_log';

    protected $fillable = ['tenant_id', 'proposal_id', 'phase', 'actor_id', 'api_request', 'api_response', 'note'];

    protected $casts = ['api_request' => 'array', 'api_response' => 'array', 'created_at' => 'datetime'];
}
