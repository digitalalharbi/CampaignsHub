<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * PRICING-CATALOGUE-001 — catalogue editions, and the launch catalogue withdrawn from sale WITHOUT touching anyone on it.
 *
 * Every plan that exists when this runs is the launch catalogue: it becomes edition 1 (retired) and `is_public = false`. Its
 * prices, currency and limits are left exactly as they are, and so is every subscription — `subscriptions.unit_amount` and
 * `currency` were captured at purchase and keep being what renews. The 2026-11 SAR plans are created by `platform:provision`
 * (SubscriptionPlanSeeder), which the deploy runs right after migrating.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_catalogue_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedInteger('number')->unique();
            $table->string('label', 120);
            $table->string('currency', 3);
            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('subscription_plans', function (Blueprint $table): void {
            $table->uuid('catalogue_version_id')->nullable()->index();
        });

        if (! DB::table('subscription_plans')->exists()) {
            return;
        }

        $legacy = (string) Str::uuid();
        DB::table('plan_catalogue_versions')->insert([
            'id' => $legacy, 'number' => 1, 'label' => 'Launch catalogue (USD)', 'currency' => 'USD', 'status' => 'retired',
            'notes' => 'Withdrawn from sale on 2026-11-01. Existing subscribers keep their plan, price, currency and renewal date.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('subscription_plans')->update(['catalogue_version_id' => $legacy, 'is_public' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table): void {
            $table->dropColumn('catalogue_version_id');
        });
        Schema::dropIfExists('plan_catalogue_versions');
    }
};
