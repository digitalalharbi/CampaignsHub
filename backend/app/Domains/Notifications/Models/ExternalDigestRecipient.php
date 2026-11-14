<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * SPEND-SUMMARY-EXTERNAL-RECIPIENTS-001 — an address outside the platform that receives the budget follow-up of the projects
 * bound to it. Tenant-scoped (`BelongsToTenant`); the projects are read from the pivot, always narrowed to the same tenant.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $email
 * @property string|null $name
 * @property string $cadence
 * @property bool $active
 */
final class ExternalDigestRecipient extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    public const CADENCES = ['daily', 'weekly', 'monthly'];

    protected $table = 'external_digest_recipients';

    protected $fillable = ['tenant_id', 'email', 'name', 'cadence', 'active', 'created_by'];

    protected $casts = ['active' => 'boolean'];

    /**
     * The bound projects — read with the tenant in the WHERE, never through the pivot alone, so a row that somehow named
     * another workspace's project is not followed.
     *
     * @return list<string>
     */
    public function projectIds(): array
    {
        return DB::table('external_digest_recipient_projects')
            ->where('recipient_id', $this->id)
            ->where('tenant_id', $this->tenant_id)
            ->orderBy('project_id')
            ->pluck('project_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }

    /** @param list<string> $projectIds already checked to belong to this tenant */
    public function bindProjects(array $projectIds): void
    {
        DB::table('external_digest_recipient_projects')->where('recipient_id', $this->id)->delete();
        DB::table('external_digest_recipient_projects')->insert(array_map(fn (string $p): array => [
            'recipient_id' => $this->id, 'project_id' => $p, 'tenant_id' => $this->tenant_id,
        ], array_values(array_unique($projectIds))));
    }
}
