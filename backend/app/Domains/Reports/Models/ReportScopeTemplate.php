<?php

declare(strict_types=1);

namespace App\Domains\Reports\Models;

use App\Domains\Reports\Support\ReportScope;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * A saved report scope somebody expects to use again (§14.5).
 *
 * Deliberately NOT project-scoped by the global `BelongsToProject`: a template naming only platforms
 * or a marketing path is the kind an agency reuses across every client, and a scope that could only
 * ever be read inside the project that created it would make the reusable case unexpressible. The
 * tenant scope is non-negotiable and stays.
 */
final class ReportScopeTemplate extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'project_id', 'name', 'description', 'scope', 'created_by',
    ];

    protected $casts = [
        'scope' => 'array',
    ];

    /** The stored shape as the object every surface reads. */
    public function toScope(): ReportScope
    {
        return ReportScope::fromArray($this->scope);
    }
}
