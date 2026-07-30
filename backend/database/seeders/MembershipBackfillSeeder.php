<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Services\MembershipProvisioner;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Grants the demo and legacy users their membership (ADR 0002).
 *
 * This is the MIGRATION PATH, and the one place `users.tenant_id` is still read on purpose: it takes
 * users who predate the membership layer — seeded fixtures, rows created before the migration — and
 * moves them into an explicit membership.
 *
 * It is not a substitute for granting access properly. New code grants through `GrantMembership`,
 * naming tenant, workspace, portal, role and scopes; this exists only until no unmigrated user
 * remains. Platform users (`tenant_id` null) are skipped: they belong to no tenant, so no portal.
 *
 * Runs LAST and is idempotent.
 */
final class MembershipBackfillSeeder extends Seeder
{
    public function run(): void
    {
        $provisioner = app(MembershipProvisioner::class);
        $granted = 0;

        User::whereNotNull('tenant_id')
            ->whereDoesntHave('memberships')
            ->chunkById(200, function ($users) use ($provisioner, &$granted): void {
                foreach ($users as $user) {
                    if ($provisioner->ensureForOwnWorkspace($user, 'member') !== null) {
                        $granted++;
                    }
                }
            });

        $this->command?->info("Memberships: {$granted} granted, ".Membership::count().' total.');
    }
}
