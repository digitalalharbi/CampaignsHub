<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FORMS-FUNNEL-EVENTS-001 — what a funnel event needs that a link open did not.
 *
 *  - `session_id`: a random id the page makes in memory when it loads. It joins one visit's events to each
 *    other (view → start → step → submit) and to nothing else: it is not stored in a cookie, so the next visit
 *    is a different session and a person is not followed. This is what makes «drop-off by step» answerable.
 *  - `lead_id`: set on the events the Leads Centre writes itself (created, contacted, qualified …), so a
 *    lead's journey reads in time order from one table.
 *  - `step`: the 1-based step of a multi-step form the event is about.
 *  - `click_id_type`: WHICH platform's click id the visit arrived with (`gclid`, `fbclid` …) — never the value,
 *    which stays on the submission's evidence where a conversion delivery can read it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracking_events', function (Blueprint $table): void {
            $table->string('session_id', 64)->nullable()->after('visitor_confidence');
            $table->uuid('lead_id')->nullable()->after('session_id');
            $table->unsignedSmallInteger('step')->nullable()->after('lead_id');
            $table->string('click_id_type', 16)->nullable()->after('step');

            $table->index(['tenant_id', 'subject_type', 'subject_id', 'session_id'], 'tracking_events_session');
            $table->index(['tenant_id', 'lead_id'], 'tracking_events_lead');
        });
    }

    public function down(): void
    {
        Schema::table('tracking_events', function (Blueprint $table): void {
            $table->dropIndex('tracking_events_session');
            $table->dropIndex('tracking_events_lead');
            $table->dropColumn(['session_id', 'lead_id', 'step', 'click_id_type']);
        });
    }
};
