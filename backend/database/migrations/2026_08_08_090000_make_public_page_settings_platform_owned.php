<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PAGES-001 — the public marketing site belongs to the platform, not to a tenant.
 *
 * ## What was wrong
 *
 * These four documents — the marketing homepage and the three public portals — were stored one row
 * per (tenant, page), and the public endpoint that serves visitors read `latest('published_at')`
 * across every tenant with no filter. So the homepage a visitor saw belonged to whichever customer
 * had published most recently. Any tenant administrator could rewrite the platform's own front page,
 * and the next one to publish would take it from them.
 *
 * The editor had already been moved to `/admin` (the router redirects `/settings/public-pages` there,
 * and the console's own docblock calls these platform-level). Only the storage and the API were left
 * behind, which is why the console showed «تعذّر تحميل إعدادات الصفحات»: the platform operator belongs
 * to no tenant, and the endpoint was behind tenant scope.
 *
 * ## What this does
 *
 * `tenant_id` becomes nullable and the platform's row is the one where it is NULL. A partial unique
 * index keeps that to exactly one row per page.
 *
 * ## What it deliberately does NOT do
 *
 * It does not delete the per-tenant rows. Postgres treats NULLs as distinct in a unique index, so the
 * old composite unique cannot stop two platform rows on its own — hence the partial index — but the
 * legacy rows collide with nothing and are simply no longer read. Dropping content somebody wrote, to
 * tidy up a schema, is not a migration's decision to make; they can be reviewed and removed by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_page_settings', function ($table): void {
            $table->uuid('tenant_id')->nullable()->change();
        });

        // One platform row per page. Partial, so the legacy per-tenant rows are untouched by it.
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS public_page_settings_platform_page_unique
             ON public_page_settings (page) WHERE tenant_id IS NULL',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS public_page_settings_platform_page_unique');

        // Rows owned by the platform have no tenant to hand back to, so they go before the column is
        // made NOT NULL again — otherwise the change itself fails on them.
        DB::table('public_page_settings')->whereNull('tenant_id')->delete();

        Schema::table('public_page_settings', function ($table): void {
            $table->uuid('tenant_id')->nullable(false)->change();
        });
    }
};
