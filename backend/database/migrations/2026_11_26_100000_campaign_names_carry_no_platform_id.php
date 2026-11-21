<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CAMPAIGN-NAME-PLATFORM-ONLY-001 (Owner, 2026-11-21) — no campaign name carries an id.
 *
 * `unified_campaigns` was unique on `(project_id, name)`, so the importer appended the platform's campaign id
 * («26Aug 2026- ReSano Power Spot Pro · 03682f53-…») whenever a project already held the name. Identity is the
 * link on `external_campaigns.unified_campaign_id`, never the name, so the unique index goes (a plain index
 * stays for lookups) and every name the importer qualified goes back to its platform's name.
 *
 * Only names that match the importer's own pattern exactly — `{platform name} · {external_id}` or
 * `{platform name} · {external_id} · {unified id}` — are touched; a name a person typed is left alone.
 * The name before is kept in `meta.name_before_platform_only`, so the change is reversible row by row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unified_campaigns', function ($table) {
            $table->dropUnique(['project_id', 'name']);
            $table->index(['project_id', 'name']);
        });

        DB::statement(<<<'SQL'
            UPDATE unified_campaigns u
               SET meta = COALESCE(u.meta, '{}'::jsonb) || jsonb_build_object('name_before_platform_only', u.name),
                   name = LEFT(e.name, 160),
                   updated_at = NOW()
              FROM external_campaigns e
             WHERE e.unified_campaign_id = u.id
               AND e.name IS NOT NULL
               AND e.name <> ''
               AND (
                    u.name = LEFT(e.name || ' · ' || e.external_id, 160)
                 OR u.name = LEFT(e.name || ' · ' || e.external_id || ' · ' || u.id::text, 160)
               )
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            UPDATE unified_campaigns
               SET name = meta->>'name_before_platform_only',
                   meta = meta - 'name_before_platform_only'
             WHERE meta->>'name_before_platform_only' IS NOT NULL
        SQL);

        // The unique index is not restored: campaigns created after this migration may legitimately share a
        // platform name, and re-adding it would fail on them. The plain index stays.
    }
};
