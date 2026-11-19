<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ANALYTICS-DATA-001 — two additive tables for the analytics restructure. Nothing existing is altered.
 *
 * `influencer_project_links` — ONE dedicated tracking link per influencer per project. The link itself is a
 * row of the existing short-link library (`short_links`, the «روابط التتبع»); this row is what makes it an
 * influencer's: the FIXED UTM it was minted with (source, medium, campaign — the campaign is unique inside the
 * project, so two influencers can never share a value an order could be tied by), an optional coupon, and an
 * optional collaboration the cost is read from. The roster link is optional: a project can track an influencer
 * who is not on the agency roster, by name.
 *
 * `analytics_snapshots` — a heavy read computed once, off the request path, and stored per tenant AND project
 * for one named window. The request reads the row when it is fresh; it never merges rows of two projects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('influencer_project_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->uuid('influencer_id')->nullable()->index();
            $table->uuid('collaboration_id')->nullable()->index();
            $table->uuid('short_link_id')->index();
            $table->string('name', 120);
            // The name folded to a slug: the unit of «one link per influencer» when there is no roster row.
            $table->string('handle_slug', 80);
            $table->string('utm_source', 100);
            $table->string('utm_medium', 100);
            $table->string('utm_campaign', 120);
            $table->string('coupon_code', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'project_id', 'handle_slug'], 'influencer_project_links_one_per_influencer');
            $table->unique(['tenant_id', 'project_id', 'utm_campaign'], 'influencer_project_links_utm_unique');
            $table->unique('short_link_id', 'influencer_project_links_short_link_unique');
        });

        Schema::create('analytics_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            // attribution | influencers | site | store
            $table->string('section', 24);
            $table->date('period_from');
            $table->date('period_to');
            $table->jsonb('payload');
            $table->timestampTz('computed_at');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'project_id', 'section', 'period_from', 'period_to'], 'analytics_snapshots_one_per_window');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_snapshots');
        Schema::dropIfExists('influencer_project_links');
    }
};
