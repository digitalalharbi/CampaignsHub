<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Portal configuration on the tenant, so the public request portal resolves its owning tenant WITHOUT a
 * fragile env UUID: by request host (portal_domain) or the single default-portal tenant. Only one tenant
 * may be the default portal at a time (enforced by a partial unique index).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('portal_domain')->nullable()->unique();
            $table->boolean('is_default_portal')->default(false);
            $table->boolean('portal_enabled')->default(true);
        });

        // At most one default portal (Postgres partial unique index).
        DB::statement('CREATE UNIQUE INDEX tenants_single_default_portal ON tenants ((is_default_portal)) WHERE is_default_portal = true');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS tenants_single_default_portal');
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['portal_domain', 'is_default_portal', 'portal_enabled']);
        });
    }
};
