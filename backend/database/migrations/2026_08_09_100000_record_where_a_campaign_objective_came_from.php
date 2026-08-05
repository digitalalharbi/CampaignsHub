<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a campaign's objective came from (REPORT-OBJECTIVE-002).
 *
 * `objective` alone cannot be trusted to mean what it says. A row reading `sales` might be what the
 * platform reported, what an operator corrected it to after seeing the report, or the column's own
 * default sitting untouched since import — and the three deserve very different confidence, because
 * this one column decides whether a campaign's spend lands in a client's cost-per-order.
 *
 * `unset` is the default and is the honest state of an imported campaign nobody has looked at. It is
 * NOT a synonym for wrong: `CampaignObjective::path()` still classifies it, and an unrecognised
 * objective is treated as not-a-sales-campaign, so the failure mode of never reviewing it is a
 * cost-per-order that is too LOW rather than a brand campaign quietly inflating it.
 *
 * `corrected_by` / `corrected_at` are here as well as in the audit log so the interface can say
 * «reviewed» beside the figure without joining to it. The audit log stays the record of what changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unified_campaigns', function (Blueprint $table): void {
            $table->string('objective_source', 20)->default('unset')->after('objective');
            $table->foreignId('objective_corrected_by')->nullable()->after('objective_source')->constrained('users')->nullOnDelete();
            $table->timestampTz('objective_corrected_at')->nullable()->after('objective_corrected_by');
        });
    }

    public function down(): void
    {
        Schema::table('unified_campaigns', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('objective_corrected_by');
            $table->dropColumn(['objective_source', 'objective_corrected_at']);
        });
    }
};
