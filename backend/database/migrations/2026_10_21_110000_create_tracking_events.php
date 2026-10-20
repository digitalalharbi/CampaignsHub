<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TRACKING-EVENTS-001 — the one place a measured interaction is written down.
 *
 * Short-link opens, report views, campaign and influencer clicks, bio-page buttons, WhatsApp outbound clicks
 * and conversion events all land here, each as a row that says WHAT happened, TO WHAT, WHEN, WHERE it was
 * heading, whether the person agreed to be recognised, and whether a person did it at all.
 *
 * ## What is deliberately not stored
 *
 * No IP address, no user agent string, no full destination URL. The user agent is read once, to decide
 * `is_bot`, and a coarse family is kept; the IP is read once, to make a hash that is salted with the DAY, so
 * it can tell two opens on one day apart from one visitor and can never be followed from one day to the
 * next. A person is recognised across days only by `visitor_key`, which exists only with `consent_state =
 * granted`.
 *
 * ## "Unique visitors" is a claim with a basis
 *
 * `visitor_confidence` says what recognised the visitor: `consented_id` (exact, across days),
 * `daily_hash` (approximate, within one day), or `none`. A reader of the numbers can therefore be told which
 * they are looking at, instead of being handed a count that sounds exact.
 *
 * ## Replays are the same event
 *
 * `dedupe_key`, when the source has a natural one, is unique per tenant, so a retried delivery is one row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->nullable()->index();

            // short_link_open | short_link_redirect | report_view | report_download | campaign_click |
            // influencer_link_click | bio_button_click | whatsapp_click | conversion_event
            $table->string('event_type', 32);
            // What the event was about: short_link | report_share | influencer_asset | bio_button | campaign
            $table->string('subject_type', 32);
            $table->uuid('subject_id');
            // Which system produced it, so a row's lineage can be followed back.
            $table->string('source', 32);

            // whatsapp | external_url | internal_report | store | none
            $table->string('destination_type', 24)->nullable();
            // The host only. A full URL can carry a phone number, a token or a name.
            $table->string('destination_host', 190)->nullable();

            $table->timestampTz('occurred_at')->index();
            $table->timestampTz('recorded_at');

            // granted | not_required | denied | unknown
            $table->string('consent_state', 16);
            $table->string('visitor_key', 64)->nullable();
            $table->string('visitor_hash', 40)->nullable();
            // consented_id | daily_hash | none
            $table->string('visitor_confidence', 16);

            $table->boolean('is_bot')->default(false);
            $table->string('bot_reason', 32)->nullable();
            $table->string('ua_family', 24)->nullable();

            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_content')->nullable();

            $table->string('dedupe_key', 128)->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestampsTz();

            $table->index(['tenant_id', 'subject_type', 'subject_id', 'occurred_at'], 'tracking_events_subject');
            $table->unique(['tenant_id', 'dedupe_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_events');
    }
};
