<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §15.12 — a link's FORM and its MODE are two facts, and one of them was being read off the other.
 *
 * `mode` (live / snapshot) says where a link's numbers come from. `form` (executive summary /
 * detailed) says how much of the report it is. They were entangled twice over:
 *
 *   - `form` was read from the REPORT row, so two links to one report could not differ. An operator
 *     who wanted the board to get five pages and the performance manager to get thirty had to
 *     generate the report twice.
 *   - `mode` was DERIVED from whether a scope had been set, so choosing which creatives a link may
 *     show would have silently turned a snapshot into a live link.
 *
 * A nullable column, because a link that names no form still means «whatever the report is» — every
 * existing row keeps behaving exactly as it does today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_shares', function (Blueprint $table): void {
            $table->string('form', 32)->nullable()->after('mode');
        });
    }

    public function down(): void
    {
        Schema::table('report_shares', function (Blueprint $table): void {
            $table->dropColumn('form');
        });
    }
};
