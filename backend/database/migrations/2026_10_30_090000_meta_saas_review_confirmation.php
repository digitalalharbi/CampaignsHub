<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * META-SAAS-APP-001 — who confirmed, and when, that Meta approved the SaaS app (Business Verification and `ads_read` Advanced Access).
 *
 * Meta exposes neither fact to the app, so it is the Owner's recorded statement, and the SaaS app cannot take new customer connections
 * without it. Additive and nullable: every existing row stays NULL, which reads as «not confirmed».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_configurations', function (Blueprint $table): void {
            $table->timestamp('review_confirmed_at')->nullable();
            $table->foreignId('review_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('provider_configurations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('review_confirmed_by');
            $table->dropColumn('review_confirmed_at');
        });
    }
};
