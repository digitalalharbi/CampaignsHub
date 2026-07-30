<?php

declare(strict_types=1);

use App\Domains\Accounts\Enums\AccountType;
use App\Domains\Tenancy\Enums\Portal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Retire `users.tenant_id` (ADR 0002).
 *
 * The column answered "which workspace is this person in?" with exactly one id. A user may hold
 * memberships in several — an agency owner who is also a client of another agency, a freelancer on
 * two rosters — so for anyone with more than one it answered with whichever tenant happened to be
 * stamped on the row at registration. Authorisation has read memberships for some time; this removes
 * the second answer so the two can no longer disagree.
 *
 * TWO STEPS IN ONE MIGRATION, in this order, and the order is the whole safety of it:
 *
 *   1. Grant a membership to every user who still has a tenant and none. This is the LAST moment the
 *      column can be read, and a user left without a membership after the drop is locked out with no
 *      way to reconstruct where they belonged.
 *   2. Only then drop the column.
 *
 * If step 1 throws, the transaction rolls back and the column survives — which is the failure we
 * want, because the alternative is a dropped column and a set of users nobody can place.
 *
 * Deliberately raw queries rather than the Eloquent models: a migration that goes through models
 * breaks the day someone edits a cast or adds a global scope, and it would break retroactively, on
 * the one run that matters. `Portal` and `AccountType` are enums, not models, so reading them here
 * costs nothing and keeps the mapping in one place.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'tenant_id')) {
            return;
        }

        DB::transaction(function (): void {
            $stranded = DB::table('users')
                ->join('tenants', 'tenants.id', '=', 'users.tenant_id')
                ->whereNotNull('users.tenant_id')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('memberships')->whereColumn('memberships.user_id', 'users.id'))
                ->select('users.id as user_id', 'tenants.id as tenant_id', 'tenants.account_type')
                ->get();

            $now = now();

            foreach ($stranded as $row) {
                DB::table('memberships')->insert([
                    'id' => (string) Str::uuid(),
                    'user_id' => $row->user_id,
                    'tenant_id' => $row->tenant_id,
                    // The portal their account type implies — the same rule registration uses. A
                    // starting point, never a permanent property: they may be granted others later.
                    'portal' => $row->account_type === AccountType::Agency->value
                        ? Portal::Agency->value
                        : Portal::App->value,
                    'workspace_id' => null,
                    'role' => 'member',
                    'status' => 'active',
                    // They had none, so this one is where they land.
                    'is_default' => true,
                    'invited_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // A user whose tenant row is already gone cannot be placed, and joining above skipped
            // them. Refuse rather than drop: "we cannot tell where 4 people belong" is a thing an
            // operator must see before the evidence disappears, not after.
            $unplaceable = DB::table('users')
                ->whereNotNull('users.tenant_id')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('memberships')->whereColumn('memberships.user_id', 'users.id'))
                ->count();

            if ($unplaceable > 0) {
                throw new RuntimeException(
                    "Refusing to drop users.tenant_id: {$unplaceable} user(s) still have a tenant but no "
                    .'membership, and their tenant row no longer exists. Resolve them first.'
                );
            }

            Schema::table('users', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('tenant_id');
            });
        });
    }

    /**
     * Restores the column, empty.
     *
     * It cannot restore the VALUES, and pretending otherwise by guessing one membership per user
     * would write a definitive-looking answer for exactly the users the column was wrong about.
     * Anything that needs a tenant reads memberships; nothing should need this back.
     */
    public function down(): void
    {
        if (Schema::hasColumn('users', 'tenant_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignUuid('tenant_id')->nullable()->after('id')
                ->constrained('tenants')->nullOnDelete();
        });
    }
};
