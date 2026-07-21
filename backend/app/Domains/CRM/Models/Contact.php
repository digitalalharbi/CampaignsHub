<?php

declare(strict_types=1);

namespace App\Domains\CRM\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Contact extends Model
{
    use BelongsToTenant;
    use HasUuidKey;
    use SoftDeletes;

    protected $fillable = ['tenant_id', 'company_id', 'name', 'email', 'phone', 'position'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
