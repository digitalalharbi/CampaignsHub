<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The second half of the influencers contract (INFL-003).
 *
 * Three things the roster and the collaboration could not express:
 *
 * 1. **Nominations.** Putting a creator forward is a decision somebody makes and somebody else
 *    answers, and the answer matters later — «why did we not use her?» is a question a brand asks
 *    six months on. A collaboration is what exists AFTER that decision; there was nowhere to record
 *    the decision itself, so a rejected creator left no trace and was suggested again next quarter.
 *
 * 2. **Attribution.** A tracking link and a discount code are how influencer work is measured, and
 *    they are per creator — the whole point is telling one creator's traffic from another's. There
 *    was no per-creator identifier of any kind.
 *
 * 3. **Results per deliverable.** A campaign-level number cannot answer «which post worked», which
 *    is the only question that changes what you commission next time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('influencer_nominations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('influencer_id');
            // A nomination belongs to a CAMPAIGN, and a campaign is optional: creators are also
            // shortlisted for work that has not been given a campaign yet.
            $table->uuid('campaign_id')->nullable();
            $table->uuid('client_workspace_id')->nullable();

            $table->string('status', 24)->default('proposed');
            $table->decimal('proposed_fee', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();

            // Why this creator, in the words of whoever put them forward. A shortlist with no
            // reasoning is a list somebody has to rebuild from memory before they can defend it.
            $table->text('rationale')->nullable();

            $table->unsignedBigInteger('proposed_by')->nullable();
            $table->timestampTz('proposed_at')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            // Set when an approved nomination becomes real work, so the trail runs from the idea to
            // the contract without either side having to be inferred.
            $table->uuid('collaboration_id')->nullable();

            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('influencer_id')->references('id')->on('influencers')->cascadeOnDelete();
            $table->foreign('collaboration_id')->references('id')->on('influencer_collaborations')->nullOnDelete();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'campaign_id']);
        });

        /*
         * One creator's traffic, told apart from another's.
         *
         * `code` is globally unique rather than unique per tenant: it is what appears in a public
         * URL, so two tenants minting the same code would send one brand's clicks to the other's
         * report. The redirect resolves it without a tenant in context, which is only safe because
         * the code itself is the whole key.
         */
        Schema::create('influencer_tracking_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('collaboration_id');
            // Null for a link that covers the whole collaboration; set when it belongs to one post.
            $table->uuid('deliverable_id')->nullable();

            // `link` is served by this platform and its clicks are real. `discount_code` is redeemed
            // in the brand's own store, which this platform cannot see — see `redemptions_source`.
            $table->string('kind', 16);
            $table->string('code', 64)->unique();
            $table->text('destination_url')->nullable();

            $table->string('discount_type', 16)->nullable();
            $table->decimal('discount_value', 12, 2)->nullable();

            $table->unsignedBigInteger('clicks')->default(0);
            $table->timestampTz('last_clicked_at')->nullable();

            $table->unsignedBigInteger('redemptions')->default(0);
            /*
             * Where the redemption count came from, never presented as though the platform measured
             * it. `manual` is a person typing what the store told them; `awaiting_credentials` means
             * nobody has connected the store at all and the zero means nothing.
             */
            $table->string('redemptions_source', 32)->default('awaiting_credentials');
            $table->timestampTz('redemptions_updated_at')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('collaboration_id')->references('id')->on('influencer_collaborations')->cascadeOnDelete();
            $table->foreign('deliverable_id')->references('id')->on('influencer_deliverables')->cascadeOnDelete();

            $table->index(['tenant_id', 'collaboration_id']);
        });

        /*
         * Results attached to the POST, not to the campaign.
         *
         * One row per deliverable per source, so a hand-entered number and a platform-synced one can
         * sit side by side and be told apart rather than one silently overwriting the other.
         */
        Schema::create('influencer_deliverable_results', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('deliverable_id');

            $table->string('source', 32)->default('manual');

            $table->unsignedBigInteger('impressions')->nullable();
            $table->unsignedBigInteger('reach')->nullable();
            $table->unsignedBigInteger('engagements')->nullable();
            $table->unsignedBigInteger('clicks')->nullable();
            $table->unsignedBigInteger('conversions')->nullable();
            $table->decimal('revenue', 14, 2)->nullable();
            $table->string('currency', 3)->nullable();

            $table->timestampTz('measured_at')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('deliverable_id')->references('id')->on('influencer_deliverables')->cascadeOnDelete();

            $table->index(['tenant_id', 'deliverable_id']);
        });

        /*
         * One live figure per deliverable per source.
         *
         * A partial unique index, because history is kept: a superseded row is soft-marked by
         * `measured_at` moving, and only the current one per source may exist. Laravel's schema
         * builder cannot express a partial index, so this is raw.
         */
        DB::statement('
            CREATE UNIQUE INDEX influencer_deliverable_results_current
                ON influencer_deliverable_results (deliverable_id, source)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('influencer_deliverable_results');
        Schema::dropIfExists('influencer_tracking_assets');
        Schema::dropIfExists('influencer_nominations');
    }
};
