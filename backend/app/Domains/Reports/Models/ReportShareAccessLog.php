<?php

declare(strict_types=1);

namespace App\Domains\Reports\Models;

use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

final class ReportShareAccessLog extends Model
{
    use HasUuidKey;

    public const UPDATED_AT = null;

    protected $fillable = ['share_id', 'action', 'ip', 'user_agent', 'detail', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];
}
