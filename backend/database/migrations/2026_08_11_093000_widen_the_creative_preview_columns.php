<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `thumbnail_url` and `preview_url` were `varchar(255)` — shorter than the URLs they hold.
 *
 * A signed Meta or Snapchat preview URL routinely runs past 255 characters: the path, the asset id,
 * an expiry, a signature and several cache-busting parameters. The column would either reject the
 * row outright — a sync that fails on the creatives it most needs — or, on a driver that truncates
 * quietly, store a link that 404s with no sign of where it broke.
 *
 * Widened to 2048 to match `asset_url` and `video_url`, which were added at that size for exactly
 * this reason. Found by seeding a self-contained data URI, which is longer still.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_creatives', function (Blueprint $table): void {
            $table->string('thumbnail_url', 2048)->nullable()->change();
            $table->string('preview_url', 2048)->nullable()->change();
            $table->string('destination_url', 2048)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('external_creatives', function (Blueprint $table): void {
            $table->string('thumbnail_url', 255)->nullable()->change();
            $table->string('preview_url', 255)->nullable()->change();
            $table->string('destination_url', 255)->nullable()->change();
        });
    }
};
