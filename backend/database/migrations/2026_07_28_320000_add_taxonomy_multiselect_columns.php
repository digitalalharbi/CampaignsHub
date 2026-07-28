<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive homes for the taxonomy-driven multi-selects the SPA adopts. Every column is nullable jsonb, so no
 * existing row is touched and nothing is back-filled. Existing enum columns (objective, status, industry,
 * service_level, priority, …) are deliberately NOT changed — their keys already match the taxonomy engine, so
 * no data migration is needed. `unified_campaigns.regions` already exists and is left as-is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unified_campaigns', function (Blueprint $table): void {
            if (! Schema::hasColumn('unified_campaigns', 'platforms')) {
                $table->jsonb('platforms')->nullable()->after('objective');           // campaign.platforms keys
            }
            if (! Schema::hasColumn('unified_campaigns', 'audiences')) {
                $table->jsonb('audiences')->nullable()->after('audience');             // campaign.audiences (tenant-managed)
            }
            if (! Schema::hasColumn('unified_campaigns', 'conversion_events')) {
                $table->jsonb('conversion_events')->nullable()->after('audiences');    // campaign.conversion_events
            }
            if (! Schema::hasColumn('unified_campaigns', 'creative_types')) {
                $table->jsonb('creative_types')->nullable()->after('conversion_events'); // campaign.creative_types keys
            }
            if (! Schema::hasColumn('unified_campaigns', 'tags')) {
                $table->jsonb('tags')->nullable()->after('creative_types');            // campaign.tags (tenant-managed)
            }
        });

        Schema::table('client_workspaces', function (Blueprint $table): void {
            if (! Schema::hasColumn('client_workspaces', 'tags')) {
                $table->jsonb('tags')->nullable()->after('industry');                  // client.tags (tenant-managed)
            }
            if (! Schema::hasColumn('client_workspaces', 'enabled_services')) {
                $table->jsonb('enabled_services')->nullable()->after('tags');          // enabled service keys
            }
        });
    }

    public function down(): void
    {
        Schema::table('unified_campaigns', function (Blueprint $table): void {
            foreach (['platforms', 'audiences', 'conversion_events', 'creative_types', 'tags'] as $column) {
                if (Schema::hasColumn('unified_campaigns', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('client_workspaces', function (Blueprint $table): void {
            foreach (['tags', 'enabled_services'] as $column) {
                if (Schema::hasColumn('client_workspaces', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
