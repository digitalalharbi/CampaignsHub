<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIO-PAGE-001 — a link-in-bio page is a short link of kind `bio`: served on the same `/l/{slug}` hop
 * (the only path the edge routes to the backend), opening a page whose buttons are other short links
 * of the same tenant — so every tap is counted where short links are counted.
 *
 * `bio` holds the page: `{title, subtitle, buttons: [{short_link_id, label}]}`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('short_links', function (Blueprint $table): void {
            $table->jsonb('bio')->nullable()->after('custom_slug');
        });
    }

    public function down(): void
    {
        Schema::table('short_links', function (Blueprint $table): void {
            $table->dropColumn('bio');
        });
    }
};
