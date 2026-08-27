<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEAD-PROVENANCE-001 — a lead that cannot say which ad produced it.
 *
 * The CRM already models a lead, an activity timeline, a pipeline and an opportunity. What it has no
 * way to record is where the lead CAME FROM: `source` is an enum whose widest value is «paid», which
 * cannot answer «which campaign», let alone «which creative». For a paid lead-generation client that
 * is the only question worth asking — the whole point of the spend is to learn which ad produced a
 * qualified buyer, and «paid» is the one answer that cannot be acted on.
 *
 * ## Provenance is immutable, and separate from the editable record
 *
 * Everything added here describes the ACQUISITION EVENT, not the person. The team will edit the name,
 * fix the phone number, move the status; none of that may rewrite which ad was clicked. So these
 * columns are written once at ingestion and never by the CRM's own update path.
 *
 * ## Why `project_id` is nullable
 *
 * A lead can arrive from a webhook before the account it belongs to is bound to a project — that is
 * a real state the integrations layer already models for accounts, and dropping the lead would lose
 * a real acquisition event to a configuration gap. It arrives unassigned and becomes assignable when
 * the binding exists, which is visible rather than silent.
 *
 * ## The unique index IS the ingestion guarantee
 *
 * `(tenant_id, provider, provider_lead_id)` is unique. Providers retry deliveries, and a webhook and
 * a backfill will both see the same lead — so ingestion inserts and lets the database refuse the
 * second copy, rather than asking «does this exist?» and racing itself between the two.
 *
 * Deduplication of PEOPLE is a different question and is deliberately not this index's job: two
 * submissions from one person across two campaigns are one person and two acquisition events, and
 * collapsing them here would erase the attribution the client is paying to measure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            // Where it belongs. Nullable: see the note above.
            $table->uuid('project_id')->nullable()->after('tenant_id');

            // The acquisition event — written at ingestion, never by an edit.
            $table->string('provider', 32)->nullable()->after('source');
            $table->uuid('external_account_id')->nullable()->after('provider');
            $table->string('provider_lead_id')->nullable()->after('external_account_id');
            $table->timestampTz('provider_created_at')->nullable()->after('provider_lead_id');
            $table->timestampTz('received_at')->nullable()->after('provider_created_at');

            // The hierarchy, by the provider's own ids plus the name as it read at ingestion. The
            // name is denormalised on purpose: a campaign can be renamed, and a report about last
            // quarter must still say what it was called when the lead arrived.
            $table->string('external_campaign_id')->nullable()->after('received_at');
            $table->string('campaign_name')->nullable()->after('external_campaign_id');
            $table->string('external_adset_id')->nullable()->after('campaign_name');
            $table->string('adset_name')->nullable()->after('external_adset_id');
            $table->string('external_ad_id')->nullable()->after('adset_name');
            $table->string('ad_name')->nullable()->after('external_ad_id');
            $table->string('external_creative_id')->nullable()->after('ad_name');
            $table->string('creative_name')->nullable()->after('external_creative_id');
            $table->string('form_id')->nullable()->after('creative_name');
            $table->string('form_name')->nullable()->after('form_id');

            // Web-side attribution, only ever what the provider or the landing page actually sent.
            $table->string('landing_page', 2048)->nullable()->after('form_name');
            $table->string('utm_source')->nullable()->after('landing_page');
            $table->string('utm_medium')->nullable()->after('utm_source');
            $table->string('utm_campaign')->nullable()->after('utm_medium');
            $table->string('utm_content')->nullable()->after('utm_campaign');
            $table->string('utm_term')->nullable()->after('utm_content');
            $table->string('click_id')->nullable()->after('utm_term');

            /*
             * The form's own answers, and the qualification the team records.
             *
             * Two JSON columns rather than columns per vertical. A real-estate client asks about
             * budget, city and property type; a clinic asks about a procedure. Modelling either as
             * real columns makes the module that client's module, and the next client needs a
             * migration to be sold to. The canonical fields above stay typed; what differs by
             * customer stays configurable.
             */
            $table->jsonb('form_answers')->nullable()->after('click_id');
            $table->jsonb('qualification')->nullable()->after('form_answers');

            /*
             * SLA — the timestamps a lead-generation client actually judges the work by.
             *
             * Kept as instants rather than computed durations, because a duration is an answer to
             * one question and the instants answer all of them: time to assign, time to first
             * attempt, time to a real conversation, time to a verdict. `first_attempt_at` and
             * `first_contact_at` are deliberately different columns — an attempt that rang out is
             * work done and is not contact made, and conflating them lets a team look responsive
             * while nobody has spoken to anyone.
             */
            $table->timestampTz('assigned_at')->nullable()->after('qualification');
            $table->timestampTz('first_attempt_at')->nullable()->after('assigned_at');
            $table->timestampTz('first_contact_at')->nullable()->after('first_attempt_at');
            $table->timestampTz('qualified_at')->nullable()->after('first_contact_at');

            /*
             * Duplicates are related, never deleted.
             *
             * `canonical_lead_id` points at the lead this one duplicates; `duplicate_reason` records
             * WHICH signal matched, because «same phone» and «same provider id» are different claims
             * with different confidence. The duplicate keeps its own provenance row, so the second
             * campaign still gets credit for the acquisition event it paid for.
             */
            $table->uuid('canonical_lead_id')->nullable()->after('qualified_at');
            $table->string('duplicate_reason', 32)->nullable()->after('canonical_lead_id');

            // Normalised contact keys, for dedup and for matching without scanning raw PII.
            $table->string('phone_normalized', 32)->nullable()->after('duplicate_reason');
            $table->string('email_normalized')->nullable()->after('phone_normalized');
        });

        Schema::table('leads', function (Blueprint $table): void {
            // Ingestion idempotency, enforced by the database rather than by a check-then-insert.
            $table->unique(['tenant_id', 'provider', 'provider_lead_id'], 'leads_provider_identity_unique');

            $table->index(['tenant_id', 'project_id', 'received_at'], 'leads_project_received_index');
            $table->index(['tenant_id', 'external_campaign_id'], 'leads_campaign_index');
            $table->index(['tenant_id', 'external_ad_id'], 'leads_ad_index');
            $table->index(['tenant_id', 'external_creative_id'], 'leads_creative_index');
            // The SLA queries: unassigned, and assigned-but-never-attempted.
            $table->index(['tenant_id', 'assigned_at'], 'leads_assigned_index');
            $table->index(['tenant_id', 'first_attempt_at'], 'leads_first_attempt_index');
            $table->index(['tenant_id', 'phone_normalized'], 'leads_phone_index');
            $table->index(['tenant_id', 'email_normalized'], 'leads_email_index');
            $table->index('canonical_lead_id', 'leads_canonical_index');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            foreach ([
                'leads_provider_identity_unique', 'leads_project_received_index', 'leads_campaign_index',
                'leads_ad_index', 'leads_creative_index', 'leads_assigned_index', 'leads_first_attempt_index',
                'leads_phone_index', 'leads_email_index', 'leads_canonical_index',
            ] as $i) {
                $table->dropIndex($i);
            }

            $table->dropColumn([
                'project_id', 'provider', 'external_account_id', 'provider_lead_id', 'provider_created_at',
                'received_at', 'external_campaign_id', 'campaign_name', 'external_adset_id', 'adset_name',
                'external_ad_id', 'ad_name', 'external_creative_id', 'creative_name', 'form_id', 'form_name',
                'landing_page', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
                'click_id', 'form_answers', 'qualification', 'assigned_at', 'first_attempt_at',
                'first_contact_at', 'qualified_at', 'canonical_lead_id', 'duplicate_reason',
                'phone_normalized', 'email_normalized',
            ]);
        });
    }
};
