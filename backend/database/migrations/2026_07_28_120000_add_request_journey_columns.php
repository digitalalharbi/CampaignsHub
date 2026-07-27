<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive columns for the professional Request Journey (state machine + hierarchical taxonomy).
 *
 * STRICTLY ADDITIVE: every column is guarded by hasColumn so this migration is safe on a schema that
 * already carries some of them (module, priority, source, objective, archived_at already exist from the
 * core + SLA migrations and are intentionally NOT touched here). Nothing is dropped or renamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_requests', function (Blueprint $table): void {
            // Persistent current stage of the journey state machine (RequestStage::value). Null → draft.
            if (! Schema::hasColumn('external_requests', 'journey_stage')) {
                $table->string('journey_stage')->nullable()->index()->after('module');
            }
            // Hierarchical taxonomy: Module → Service → Category → Request Type.
            if (! Schema::hasColumn('external_requests', 'service')) {
                $table->string('service')->nullable()->after('journey_stage');
            }
            if (! Schema::hasColumn('external_requests', 'category')) {
                $table->string('category')->nullable()->after('service');
            }
            if (! Schema::hasColumn('external_requests', 'request_type')) {
                $table->string('request_type')->nullable()->after('category');
            }
            // objective already exists (text) on external_requests — only add if a fresh schema lacks it.
            if (! Schema::hasColumn('external_requests', 'objective')) {
                $table->string('objective')->nullable()->after('request_type');
            }
            // priority already exists — guard only.
            if (! Schema::hasColumn('external_requests', 'priority')) {
                $table->string('priority')->default('medium')->after('objective');
            }
            // Payment coupling (conceptually driven by the Billing domain; Billing itself is untouched).
            if (! Schema::hasColumn('external_requests', 'payment_status')) {
                $table->string('payment_status')->nullable()->after('priority'); // none|pending|paid|failed|refunded
            }
            // source already exists — guard only.
            if (! Schema::hasColumn('external_requests', 'source')) {
                $table->string('source')->nullable()->after('payment_status');
            }
            // Reason captured when a request is placed on hold; cleared on resume.
            if (! Schema::hasColumn('external_requests', 'on_hold_reason')) {
                $table->string('on_hold_reason', 500)->nullable()->after('source');
            }
            // archived_at already exists (SLA migration) — guard only.
            if (! Schema::hasColumn('external_requests', 'archived_at')) {
                $table->timestampTz('archived_at')->nullable()->after('on_hold_reason');
            }
        });
    }

    public function down(): void
    {
        // Only drop the columns THIS migration is responsible for introducing — never the pre-existing ones
        // (module, priority, source, objective, archived_at) owned by earlier migrations.
        Schema::table('external_requests', function (Blueprint $table): void {
            foreach (['journey_stage', 'service', 'category', 'request_type', 'payment_status', 'on_hold_reason'] as $column) {
                if (Schema::hasColumn('external_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
