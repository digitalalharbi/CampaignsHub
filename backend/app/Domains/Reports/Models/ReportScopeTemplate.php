<?php

declare(strict_types=1);

namespace App\Domains\Reports\Models;

use App\Domains\Reports\Sections\ReportSectionRegistry;
use App\Domains\Reports\Sections\SectionSettings;
use App\Domains\Reports\Support\ReportScope;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use App\Models\User;
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
        'tenant_id', 'project_id', 'name', 'description', 'scope', 'section_settings', 'created_by',
    ];

    protected $casts = [
        'scope' => 'array',
        'section_settings' => 'array',
    ];

    /** The section choices a report built from this template starts with. */
    public function sectionSettings(): SectionSettings
    {
        return SectionSettings::fromArray($this->section_settings, app(ReportSectionRegistry::class));
    }

    /**
     * Whether this person may change or remove it — SECURITY CLUSTER 5.
     *
     * A tenant-wide template (no project) is read by EVERY project of the workspace. A person whose reach is one project holds the
     * `reports.manage` capability for that project only, and that is not the authority to rewrite what the other projects read.
     * Those rows are the workspace administrator's; a project's own templates stay with its managers.
     */
    public function mayBeChangedBy(?User $user): bool
    {
        if ($this->project_id !== null) {
            return true;
        }

        return $user !== null && ($user->is_platform_admin || $user->hasPermission('settings.manage'));
    }

    /** The stored shape as the object every surface reads. */
    public function toScope(): ReportScope
    {
        return ReportScope::fromReport($this->scope);
    }
}
