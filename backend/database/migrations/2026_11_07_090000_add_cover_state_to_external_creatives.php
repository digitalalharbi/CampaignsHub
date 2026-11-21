<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CREATIVE-PREVIEWS-001 — the honest state of a creative's cover, per creative.
 *
 * `cover_path` said «we have one» and nothing else: a capture that failed left no trace, so 890 TikTok
 * creatives with a provider link and no cover could not say why, and the nightly pass asked again for
 * every one of them every night. The state names the four answers a card can give — cached, pending,
 * the provider gives none, failed with a reason — and the next attempt time keeps a refusal from
 * turning into a retry storm. Additive and nullable: every existing row reads as «not yet asked».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_creatives', function (Blueprint $table): void {
            $table->string('cover_state', 16)->nullable();
            $table->string('cover_error', 64)->nullable();
            $table->unsignedSmallInteger('cover_attempts')->default(0);
            $table->timestampTz('cover_next_attempt_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('external_creatives', function (Blueprint $table): void {
            $table->dropColumn(['cover_state', 'cover_error', 'cover_attempts', 'cover_next_attempt_at']);
        });
    }
};
