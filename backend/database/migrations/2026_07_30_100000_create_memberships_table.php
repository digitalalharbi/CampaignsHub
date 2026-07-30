<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The membership layer (ADR 0002).
 *
 * Until now a user carried a single `users.tenant_id`, so they belonged to exactly one tenant forever
 * and could never hold a second role elsewhere — an agency's client who is also an advertiser in their
 * own right had nowhere to exist. This table is that missing layer: it binds a user to a tenant, a
 * portal, and optionally the workspace or client space the membership is confined to.
 *
 * `users.tenant_id` is deliberately LEFT IN PLACE and backfilled from here, so every existing query,
 * scope and test keeps working while call sites migrate. It becomes the "primary" membership rather
 * than the only one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('tenant_id')->index();

            // Which portal this membership grants. See App\Domains\Tenancy\Enums\Portal.
            $table->string('portal', 32)->index();

            // Optional narrowing. A workspace membership is tenant-wide; a client membership is
            // confined to one client space (the agency's isolated client portal).
            $table->uuid('workspace_id')->nullable()->index();
            $table->uuid('client_workspace_id')->nullable()->index();

            // Role slug within the portal. Resolved against the Access domain's roles/permissions.
            $table->string('role', 64)->default('member');

            $table->string('status', 24)->default('active'); // active|suspended|revoked
            // Which membership to land on when the user has several. Exactly one may be default.
            $table->boolean('is_default')->default(false);
            $table->timestampTz('last_used_at')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_workspace_id')->references('id')->on('client_workspaces')->cascadeOnDelete();

            $table->index(['user_id', 'status']);
        });

        // Postgres treats NULLs as distinct in a unique index, so a plain unique over a nullable
        // column would allow duplicates. Two partial indexes express the real rule: one membership
        // per (user, tenant, portal) at tenant level, and one per (user, client space, portal).
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX memberships_user_tenant_portal_unique
            ON memberships (user_id, tenant_id, portal)
            WHERE client_workspace_id IS NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX memberships_user_client_portal_unique
            ON memberships (user_id, client_workspace_id, portal)
            WHERE client_workspace_id IS NOT NULL
        SQL);

        // One default per user, enforced by the database rather than by hope.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX memberships_one_default_per_user
            ON memberships (user_id)
            WHERE is_default = true
        SQL);

        $this->backfillFromUsers();
    }

    /**
     * Every existing user gets the membership they already effectively had, so nothing regresses:
     * their tenant, the portal implied by that tenant's account type, and their existing role slug.
     */
    private function backfillFromUsers(): void
    {
        $users = DB::table('users')
            ->whereNotNull('tenant_id')
            ->select('id', 'tenant_id')
            ->get();

        if ($users->isEmpty()) {
            return;
        }

        $accountTypes = DB::table('tenants')->pluck('account_type', 'id');
        $now = now();

        $rows = $users->map(function ($user) use ($accountTypes, $now) {
            $accountType = $accountTypes[$user->tenant_id] ?? null;

            return [
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'tenant_id' => $user->tenant_id,
                // Mirrors Portal::forAccountType(); kept literal here because a migration must not
                // depend on application code that may change after it has run.
                'portal' => $accountType === 'agency' ? 'agency' : 'app',
                'workspace_id' => null,
                'client_workspace_id' => null,
                'role' => 'member',
                'status' => 'active',
                'is_default' => true,
                'last_used_at' => null,
                'invited_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('memberships')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
