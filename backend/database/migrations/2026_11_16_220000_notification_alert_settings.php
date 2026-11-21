<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOTIF-SOUND-001 — one person's alert-sound settings, kept on the server so a second device starts with the same choice.
 *
 * New table, nothing rewritten. A person without a row gets the defaults (sound on, a low volume); the quiet hours the sound
 * respects are the ones already in `notification_preferences` — this table does not keep a second copy of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_alert_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('sound_enabled')->default(true);
            $table->unsignedSmallInteger('sound_volume')->default(35); // 0–100
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_alert_settings');
    }
};
