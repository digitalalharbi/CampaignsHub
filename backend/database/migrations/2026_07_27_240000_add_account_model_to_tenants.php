<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The account model lives on the tenant (one system — no parallel personas). account_type decides the
 * workspace KIND (personal = full agency menu; company = simplified menu); enabled_modules gates paid-media
 * vs influencer-marketing features; onboarding_step drives a resumable setup wizard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // freelancer | agency | in_house_team | brand | self_serve_company (null until chosen in onboarding)
            $table->string('account_type')->nullable()->after('status');
            // JSON list of ['paid_media','influencer_marketing'] — combined = both.
            $table->jsonb('enabled_modules')->nullable()->after('account_type');
            // Reserved for plan-based limits (trial by default). Not billed here.
            $table->string('subscription_plan')->default('trial')->after('enabled_modules');
            // Resumable onboarding: register → verify_email → account_type → service → workspace → first_client
            //   → first_project → data_source → done.
            $table->string('onboarding_step')->default('verify_email')->after('subscription_plan');
            $table->timestampTz('onboarding_completed_at')->nullable()->after('onboarding_step');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['account_type', 'enabled_modules', 'subscription_plan', 'onboarding_step', 'onboarding_completed_at']);
        });
    }
};
