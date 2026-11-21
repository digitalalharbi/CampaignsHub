<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FX-APPLY-001 (builder G) — a rule's money limits are stated in the RULE's currency.
 *
 * Additive and reversible: one nullable column. NULL means «the workspace's display currency» (what every existing rule's
 * limits were read in), so no existing row changes meaning. Every object's money figure is converted INTO this currency by
 * FxEngine before it is compared with the limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('automation_rules', 'currency')) {
            Schema::table('automation_rules', function (Blueprint $table): void {
                $table->string('currency', 3)->nullable()->after('min_spend');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('automation_rules', 'currency')) {
            Schema::table('automation_rules', function (Blueprint $table): void {
                $table->dropColumn('currency');
            });
        }
    }
};
