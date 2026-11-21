<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CREATIVE-VIDEO-FRAME-COVER-001 — where a creative's own cover copy came from.
 *
 * `provider` (a still the platform sent, copied) or `video_frame` (one frame taken from the film itself with
 * ffmpeg, because the platform sent no still or its link was dead). Additive and nullable: every existing copy
 * reads as «not recorded» and is treated as the provider's own still.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_creatives', function (Blueprint $table): void {
            $table->string('cover_source', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('external_creatives', function (Blueprint $table): void {
            $table->dropColumn('cover_source');
        });
    }
};
