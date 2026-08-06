<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §15 — a carousel is more than one picture, and the schema had room for exactly one.
 *
 * `asset_url`, `headline`, `body`, `cta` and `destination_url` are singular columns, so a carousel
 * synced into them keeps its FIRST card and silently discards the rest. Every surface then rendered
 * a five-card creative as one image with one headline — not a missing feature but a wrong answer,
 * because the reader is looking at a fifth of what ran and nothing on the screen says so.
 *
 * ## Why a column and not a table
 *
 * Cards are never queried across creatives, never aggregated and never joined — `creative_daily_metrics`
 * reports at the creative level because that is the level every platform reports at. They are read
 * only with the creative they belong to. A `creative_cards` table would add a join to every preview
 * for a list nothing else ever asks a question about.
 *
 * ## `null` is not `[]`
 *
 * Nullable deliberately, and the distinction is the point: `null` means the provider sent no card
 * breakdown, `[]` means it sent one and it was empty. A `NOT NULL DEFAULT '[]'` would make «this
 * platform does not expose the cards» and «this carousel has no cards» the same row — the same defect
 * the video columns had before `1687b27`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_creatives', function (Blueprint $table): void {
            $table->jsonb('cards')->nullable()->after('raw');
        });
    }

    public function down(): void
    {
        Schema::table('external_creatives', function (Blueprint $table): void {
            $table->dropColumn('cards');
        });
    }
};
