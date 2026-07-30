<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Services\MembershipProvisioner;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Guarantees every tenant user has a portal to land in (ADR 0002).
 *
 * The memberships migration backfills from `users`, but on a clean install the migration runs while
 * that table is still empty and the seeders create users afterwards — so a `migrate:fresh --seed`
 * produced users with no membership at all, every one of whom would have fallen through to onboarding.
 *
 * Runs LAST and is idempotent, so it covers every seeder that creates users, including ones added
 * later, without each of them having to remember. Platform users (`tenant_id` null) are skipped on
 * purpose: they belong to no tenant and therefore to no portal.
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
