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

    /**
     * Deliberately nothing. A widening is not reversible, and pretending otherwise is worse.
     *
     * This used to narrow the three columns back to 255, and on any database that had actually
     * stored a long URL Postgres refused it outright:
     *
     * ```
     * SQLSTATE[22001]: value too long for type character varying(255)
     * ```
     *
     * Found by rolling the whole migration set back on a seeded database (2026-08-11) — the demo
     * thumbnails are data URIs, so every one of them is longer than 255.
     *
     * The only ways to make that statement succeed are to truncate the values or to null them, and
     * both discard a customer's data to satisfy a schema change nobody asked for. So this reverses
     * what it can: the previous release runs against a 2048 column exactly as it ran against a 255
     * one — the column is wider than its code will ever write — and the rollback completes instead
     * of aborting half way through a batch, which is the state an operator least wants to be in
     * during an incident.
     *
     * `DEPLOYMENT_CHECKLIST.md` §8 says not to roll production migrations back at all; this is what
     * happens if somebody does anyway.
     */
    public function down(): void
    {
        // Intentionally empty — see the docblock.
    }
};
