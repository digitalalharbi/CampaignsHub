<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOTIF-BELL-001 — the bell names a row by what it really is, collapses a repeat, and remembers who read what.
 *
 * Additive and reversible: three nullable / defaulted columns and one new table. Nothing is rewritten.
 *
 * - `kind`         the finding itself (`no_results`, `budget_90`, …) — the type stays the preference category.
 * - `repeat_count` how many times the same finding (kind + object + day) was raised; a repeat UPDATES the row.
 * - `last_seen_at` when it was last raised.
 * - `notification_reads` one row per (notification, user). A team-wide notification (no recipient) is ONE row for
 *   many readers, so «read» cannot live on the notification: it was never possible to clear it for one person,
 *   and the unread count only ever went up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->string('kind', 40)->nullable()->after('type')->index();
            $table->unsignedInteger('repeat_count')->default(1)->after('dedup_key');
            $table->timestampTz('last_seen_at')->nullable()->after('repeat_count');
        });

        Schema::create('notification_reads', function (Blueprint $table) {
            $table->uuid('notification_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('tenant_id')->index();
            $table->timestampTz('read_at')->useCurrent();

            $table->primary(['notification_id', 'user_id']);
            $table->foreign('notification_id')->references('id')->on('app_notifications')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['user_id', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_reads');
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->dropIndex(['kind']);
            $table->dropColumn(['kind', 'repeat_count', 'last_seen_at']);
        });
    }
};
