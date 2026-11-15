<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOTIF-V2-EXTERNAL-001 — the external pipeline (Owner spec §7–§13). Additive and reversible: two new tables and one
 * nullable platform column; nothing existing is altered or rewritten.
 *
 * - `notification_external_reviews` (workspace): the review-before-send hold of ONE external delivery. The delivery's
 *   own outcome is the immutable log row `held_for_review`; this row is the mutable decision state («أرسل» /
 *   «تخطَّ هذه المرة» / no decision in the window → skipped). Releasing it queues a NEW delivery that names this row.
 * - `notification_footprint_clicks` (platform): one row per click on the platform footprint link of an external
 *   message (admin statistics, spec §12). No FK: statistics outlive a deleted workspace; nothing personal is stored.
 * - `platform_settings.notifications_footprint` (platform): the admin-editable invitation line and public-site link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_external_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('delivery_id');                       // the held outbox row
            $table->string('reference', 32);                   // the held delivery's reference (log + message)
            $table->string('event_key', 80);
            $table->uuid('subscription_id')->nullable();
            $table->uuid('recipient_id')->nullable();
            $table->uuid('project_id')->nullable();
            $table->string('reason', 80);                      // why it was held (review_mode, first_messages, check:*)
            $table->string('status', 12)->default('pending');  // pending | sent | skipped
            $table->string('outcome_reason', 40)->nullable();  // decided | window_expired
            $table->timestampTz('expires_at');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->uuid('released_delivery_id')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique('delivery_id');
            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('notification_footprint_clicks', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('tenant_id')->nullable();
            $table->string('message_type', 60);
            $table->string('reference', 32)->nullable();
            $table->timestampTz('clicked_at');

            $table->index(['clicked_at']);
            $table->index(['tenant_id', 'clicked_at']);
        });

        if (! Schema::hasColumn('platform_settings', 'notifications_footprint')) {
            Schema::table('platform_settings', function (Blueprint $table): void {
                $table->jsonb('notifications_footprint')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('platform_settings', 'notifications_footprint')) {
            Schema::table('platform_settings', function (Blueprint $table): void {
                $table->dropColumn('notifications_footprint');
            });
        }
        Schema::dropIfExists('notification_footprint_clicks');
        Schema::dropIfExists('notification_external_reviews');
    }
};
