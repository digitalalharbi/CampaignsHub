<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PAY-GATEWAY-CONSOLE-001 — payment gateways configured from the platform console.
 *
 * `tested_fingerprint` ties a passed connection test to the exact credentials and mode it was run with, so a test passed on the old
 * keys never enables new ones. The platform's chosen primary gateway lives with the other platform-wide settings; absent, the
 * environment's SUBSCRIPTION_PROVIDER still decides, exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_configurations', function (Blueprint $table): void {
            $table->string('tested_fingerprint', 64)->nullable();
        });

        Schema::table('platform_settings', function (Blueprint $table): void {
            $table->string('payment_primary_gateway', 32)->nullable();
            $table->timestamp('payment_primary_set_at')->nullable();
            $table->unsignedBigInteger('payment_primary_set_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('provider_configurations', function (Blueprint $table): void {
            $table->dropColumn('tested_fingerprint');
        });

        Schema::table('platform_settings', function (Blueprint $table): void {
            $table->dropColumn(['payment_primary_gateway', 'payment_primary_set_at', 'payment_primary_set_by']);
        });
    }
};
