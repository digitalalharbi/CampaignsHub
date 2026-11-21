<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CONS-SCHEDULE-ONE — one active schedule per report.
 *
 * Additive only. `schedule_key` is left NULL on every existing row: duplicates already in production must not fail this
 * migration, and `reports:dedupe-schedules` is what keys them (disabling the extra copies first). A disabled copy keeps
 * every setting; it gains a reason, a timestamp and the schedule it duplicates, and nothing is deleted.
 *
 * The partial unique index only covers ACTIVE, keyed rows, so a paused or disabled copy never blocks anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_schedules', function (Blueprint $table): void {
            $table->string('schedule_key', 64)->nullable();
            $table->string('disabled_reason', 40)->nullable();
            $table->timestampTz('disabled_at')->nullable();
            $table->uuid('duplicate_of')->nullable();
        });

        DB::statement('CREATE UNIQUE INDEX report_schedules_active_key_unique ON report_schedules (schedule_key) WHERE active = true AND schedule_key IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS report_schedules_active_key_unique');

        Schema::table('report_schedules', function (Blueprint $table): void {
            $table->dropColumn(['schedule_key', 'disabled_reason', 'disabled_at', 'duplicate_of']);
        });
    }
};
