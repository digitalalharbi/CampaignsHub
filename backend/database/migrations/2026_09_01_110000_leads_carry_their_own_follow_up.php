<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEAD-OPERATIONS-001 — the three columns the follow-up work still had nowhere to go.
 *
 * `assigned_at`, `first_attempt_at`, `first_contact_at` and `qualified_at` already exist: the
 * provenance work added them, correctly, and then nothing ever wrote one. They answer «how fast did
 * we respond», which is the client's question. They cannot answer the TEAM's question — who do I
 * call today — because that needs the LAST conversation rather than the first, the promise somebody
 * made about the next one, and how many times this person has already been rung.
 *
 * Nullable and no backfill: a lead that arrived before this genuinely has no last contact time, and
 * deriving one from `updated_at` would put a fabricated figure into the first report anybody runs.
 * `contact_attempts` defaults to 0 because it IS a measured zero — the product writes it on every
 * attempt rather than receiving it from a platform, so «nobody has tried» is a fact we hold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->timestampTz('last_contact_at')->nullable()->after('first_contact_at');
            /* What the agent promised. The one date the team is measured against, and the one nobody remembers. */
            $table->timestampTz('next_follow_up_at')->nullable()->after('last_contact_at');
            $table->unsignedSmallInteger('contact_attempts')->default(0)->after('next_follow_up_at');

            /*
             * «Who is overdue» is the query this table will answer most often, and it reads the date
             * with the project. The second index is the inbox itself: one agent's leads, by stage.
             */
            $table->index(['tenant_id', 'project_id', 'next_follow_up_at'], 'leads_follow_up_idx');
            $table->index(['tenant_id', 'project_id', 'owner_id', 'status'], 'leads_pipeline_idx');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropIndex('leads_follow_up_idx');
            $table->dropIndex('leads_pipeline_idx');
            $table->dropColumn(['last_contact_at', 'next_follow_up_at', 'contact_attempts']);
        });
    }
};
