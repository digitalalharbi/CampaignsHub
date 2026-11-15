<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOTIF-PUSH-001 — one row per device a person allowed mobile notifications on, and the pushes held for quiet hours.
 *
 * Additive: three new tables and one nullable column. A person may hold several subscriptions (phone and laptop) and revoke each one.
 *
 * - `push_subscriptions`  the browser's push endpoint and the two public values needed to encrypt to it. `auth` is a secret shared
 *   with that one browser, so it is encrypted at rest (cast on the model); the endpoint is stored whole because it must be called,
 *   and hashed for the unique index because it is longer than an index key may be.
 * - `push_deferred`       a push that arrived in quiet hours (or beyond the hourly ceiling): one row per person and notification,
 *   sent afterwards as ONE summary and then deleted.
 * - `push_ledger`         one row per push a person was sent, for the hourly ceiling.
 * - `notification_alert_settings.push_types`  which of the five push types a person wants; null = all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('p256dh', 255);
            $table->text('auth'); // encrypted by the model cast
            $table->string('content_encoding', 20)->default('aes128gcm');
            $table->string('label', 120)->nullable(); // «Chrome · Android» — what the person recognises in the list
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('last_failure_at')->nullable();
            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->timestampsTz();

            $table->index('user_id');
        });

        Schema::create('push_deferred', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('notification_id');
            $table->string('reason', 20); // quiet_hours | ceiling
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['user_id', 'notification_id']);
            $table->index(['user_id', 'tenant_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('notification_id')->references('id')->on('app_notifications')->cascadeOnDelete();
        });

        // One row per push message a person was sent: the hourly ceiling counts these. Pruned daily by the flush command.
        Schema::create('push_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('sent_at')->useCurrent();

            $table->index(['user_id', 'sent_at']);
        });

        Schema::table('notification_alert_settings', function (Blueprint $table) {
            $table->jsonb('push_types')->nullable()->after('sound_volume');
        });
    }

    public function down(): void
    {
        Schema::table('notification_alert_settings', function (Blueprint $table) {
            $table->dropColumn('push_types');
        });
        Schema::dropIfExists('push_ledger');
        Schema::dropIfExists('push_deferred');
        Schema::dropIfExists('push_subscriptions');
    }
};
