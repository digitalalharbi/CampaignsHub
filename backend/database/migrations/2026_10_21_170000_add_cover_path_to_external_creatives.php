<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TIKTOK-MEDIA-002 — a creative's cover, kept in our own storage.
 *
 * Provider poster links are signed and expire (TikTok's within hours); a library that reads them live shows
 * nothing between one expiry and the next sync. The cover is fetched once and served from here, so the image
 * the reader sees never depends on a link that has since died.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_creatives', function (Blueprint $table): void {
            $table->string('cover_path', 255)->nullable();
            $table->timestampTz('cover_cached_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('external_creatives', function (Blueprint $table): void {
            $table->dropColumn(['cover_path', 'cover_cached_at']);
        });
    }
};
