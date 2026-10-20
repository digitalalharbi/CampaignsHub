<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TOUCHPOINT-EVIDENCE-001 — a marketing interaction that happened BEFORE an order, and the link that proves
 * it belongs to that order.
 *
 * ## What a touchpoint is, and what it is not
 *
 * A touchpoint is something a person did in response to our marketing that we recorded ourselves: a click on
 * a smart link, a click on an influencer's tracked link, a platform click carrying a click id. It is NOT an
 * earlier order. A customer's order history says how often they buy; it says nothing about which advertising
 * they met, and a model that credits channels for it is describing repeat purchases and calling it a journey.
 *
 * ## Consent is part of the row
 *
 * `visitor_key` is a pseudonymous first-party identifier and exists only when `consent_state = granted`. A
 * click recorded without consent keeps what the click itself carries (the link, the campaign, a click id the
 * ad platform put in the URL) and no identifier of the person. The recorder enforces that; the schema makes
 * the state explicit so a reader can tell the two apart.
 *
 * ## Lineage and reproducibility
 *
 * `source_type` + `source_ref` say where the row came from and which row there; `dedupe_key` makes a replayed
 * event the same touchpoint instead of a second one; `recorded_at` (distinct from `occurred_at`) lets a past
 * report be rebuilt from only what was known at the time.
 *
 * ## The link is evidence of its own kind
 *
 * `order_touchpoint_links.grade` is `verified` only when the order itself carries something we minted or the
 * platform issued and that matches exactly: a click id, or a tracking token we put in the destination URL.
 * Anything softer is `declared` and never enters a model unless it is asked for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_touchpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();

            // smart_link_click | influencer_link_click | ad_click_id | first_party_event
            $table->string('source_type', 32);
            // The row in the source system this came from (a click log id, a link id); never a person.
            $table->string('source_ref', 128);
            // Replaying the same event must not create a second touchpoint.
            $table->string('dedupe_key', 128);

            // The channel as the product names it: a canonical ad platform, `influencer`, or the link's own channel.
            $table->string('channel', 48)->index();
            $table->boolean('is_paid')->default(false);

            $table->uuid('external_campaign_id')->nullable()->index();
            $table->uuid('smart_link_id')->nullable()->index();
            $table->uuid('influencer_asset_id')->nullable();

            $table->string('click_id', 255)->nullable()->index();
            $table->string('click_id_provider', 32)->nullable();
            // A token WE minted and put in the destination URL, so the order can prove it came through this click.
            $table->string('tracking_token', 64)->nullable()->unique();

            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_content')->nullable();

            // granted | not_required | denied | unknown. Only granted rows may carry a visitor key.
            $table->string('consent_state', 16);
            $table->string('visitor_key', 64)->nullable()->index();

            $table->timestampTz('occurred_at')->index();
            $table->timestampTz('recorded_at');

            $table->boolean('is_demo')->default(false);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'dedupe_key']);
        });

        Schema::create('order_touchpoint_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->uuid('commerce_order_id')->index();
            $table->uuid('touchpoint_id')->index();

            // click_id_match | tracking_token_match | visitor_key_match | utm_campaign_declared
            $table->string('method', 32);
            // verified | declared
            $table->string('grade', 12);
            // Which rule set produced the link, so a later rule change is a new version and not a rewrite.
            $table->string('linker_version', 24);
            $table->timestampTz('linked_at');
            $table->timestampsTz();

            $table->unique(['commerce_order_id', 'touchpoint_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_touchpoint_links');
        Schema::dropIfExists('marketing_touchpoints');
    }
};
