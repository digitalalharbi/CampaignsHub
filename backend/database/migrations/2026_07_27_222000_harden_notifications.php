<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification hardening: a dedup key on in-app notifications (collapse repeats within a window), a
 * per-channel delivery log with explicit states, and an optional per-client preference scope.
 * Email delivery stays "awaiting_credentials" until a real mail provider is wired — we never record "sent".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            // Stable identity for de-duplication (tenant+user+type+entity+time-bucket, hashed).
            $table->string('dedup_key')->nullable()->after('status')->index();
        });

        // Per-channel delivery ledger — one row per (notification, channel) attempt lifecycle.
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('notification_id')->index();
            $table->string('channel'); // in_app|email
            // queued|awaiting_credentials|sent|failed|retrying|suppressed_by_preference|suppressed_by_quiet_hours
            $table->string('status');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('dedup_key')->nullable()->index();
            $table->text('error')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('last_attempt_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('notification_id')->references('id')->on('app_notifications')->cascadeOnDelete();
            $table->index(['tenant_id', 'channel', 'status']);
        });

        // Optional per-client override of notification preferences (null client = the user's global default).
        if (Schema::hasTable('notification_preferences')) {
            Schema::table('notification_preferences', function (Blueprint $table) {
                if (! Schema::hasColumn('notification_preferences', 'client_workspace_id')) {
                    $table->uuid('client_workspace_id')->nullable()->after('user_id')->index();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->dropColumn('dedup_key');
        });
        if (Schema::hasTable('notification_preferences') && Schema::hasColumn('notification_preferences', 'client_workspace_id')) {
            Schema::table('notification_preferences', function (Blueprint $table) {
                $table->dropColumn('client_workspace_id');
            });
        }
    }
};
