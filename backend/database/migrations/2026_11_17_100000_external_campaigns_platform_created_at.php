<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CONS-I-AGE — when the campaign was CREATED on the platform. ADDITIVE ONLY: one nullable column, nothing rewritten.
 *
 * `created_at` is when CampaignsHub first synced the row, so a campaign made in January and linked this week
 * read as 3.5 days old. Rows are filled by the next sync (the connectors now ask the platform for it); until then
 * the age reads «not available» rather than the first-sync date.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('external_campaigns', 'platform_created_at')) {
            Schema::table('external_campaigns', function (Blueprint $table): void {
                $table->timestampTz('platform_created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('external_campaigns', 'platform_created_at')) {
            Schema::table('external_campaigns', function (Blueprint $table): void {
                $table->dropColumn('platform_created_at');
            });
        }
    }
};
