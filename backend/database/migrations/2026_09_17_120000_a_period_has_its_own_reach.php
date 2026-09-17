<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REACH-PERIOD-001 — reach the provider deduplicated for a whole window, stored for that window.
 *
 * Reach is not additive: a person reached on Monday and Tuesday is one person, and a sum of daily
 * reach counts them twice. The only honest period reach is the one the platform computes over the
 * period itself, so it is asked for per window and stored against the exact window it answers —
 * never derived from `daily_metrics`, and never reused for a different window.
 *
 * `state` separates «the provider answered with a figure» from «the provider was asked and returned
 * nothing for this entity and window», so a window is not re-asked on every read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_reach', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            // Our ad account (external_accounts.id) — the connection the figure was asked through.
            $table->uuid('external_account_id')->index();
            $table->string('provider', 32);
            // 'account' | 'campaign': the grain the provider deduplicated over.
            $table->string('grain', 16);
            // The platform's own id for that entity, as the platform returned it.
            $table->string('external_entity_id');
            // Our campaign (external_campaigns.id) for a campaign-grain figure, so a scope can find it.
            $table->uuid('external_campaign_id')->nullable()->index();
            $table->date('date_from');
            $table->date('date_to');
            $table->decimal('reach', 20, 4)->nullable();
            // The provider's own frequency where it returns one; the reader derives impressions ÷ reach.
            $table->decimal('frequency', 12, 4)->nullable();
            // Impressions from the SAME response, so frequency can rest on one provider answer.
            $table->decimal('impressions', 20, 4)->nullable();
            $table->string('state', 16);
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['external_account_id', 'grain', 'external_entity_id', 'date_from', 'date_to'], 'period_reach_window_key');
            $table->index(['external_account_id', 'date_from', 'date_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_reach');
    }
};
