<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIN-WALLET-001 (consistency pass, builder H) — an invoice of type «شحن محفظة إعلانية» (`purpose = wallet_top_up`)
 * names the project whose approved budget a confirmed payment of it tops up. Additive and reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('purpose', 32)->nullable();
            $table->uuid('project_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropIndex(['project_id']);
            $table->dropColumn(['purpose', 'project_id']);
        });
    }
};
