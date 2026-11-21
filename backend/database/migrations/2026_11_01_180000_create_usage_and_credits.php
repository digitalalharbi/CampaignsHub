<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * PRICING-USAGE-CREDITS-001 — metered usage and the credits that pay for it, both as append-only ledgers.
 *
 * - `usage_meters` is what can be metered, who pays for each unit (`funded_by`: the platform, or the customer's own key) and,
 *   for platform-funded meters, the ACTUAL cost per unit the operator enters. A meter with no cost, or with `charges_credits`
 *   off, is recorded and shown but never charged.
 * - `usage_events` is one row per metered act, unique on `idempotency_key`, so a retried job records nothing twice. The cost
 *   is captured on the row: re-pricing a meter never re-prices what already happened.
 * - `credit_accounts` holds one balance per workspace (never negative); `credit_transactions` is every movement with the
 *   balance after it, unique on `idempotency_key`.
 * - `usage_alerts` is the 75/90/100% notice, unique per workspace, meter, scope, month and threshold — sent once.
 *
 * Seeded meters: AI tokens on the customer's own key (recorded for visibility and the key's own cap; never charged) and SMS
 * verification codes the platform sends through Twilio (platform-funded; unpriced and not charging until the operator sets it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_meters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('name', 120);
            $table->string('name_ar', 120);
            $table->string('unit', 32);
            $table->string('funded_by', 16);
            $table->decimal('unit_cost', 15, 6)->nullable();
            $table->string('currency', 3)->default('SAR');
            $table->boolean('charges_credits')->default(false);
            $table->jsonb('included_per_plan')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('usage_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('tenant_id');
            $table->string('meter_code', 64);
            $table->string('scope', 64)->nullable();
            $table->unsignedBigInteger('quantity');
            $table->string('funded_by', 16);
            $table->decimal('unit_cost', 15, 6)->nullable();
            $table->decimal('cost', 15, 4)->default(0);
            $table->decimal('credits_charged', 15, 4)->default(0);
            $table->decimal('uncovered', 15, 4)->default(0);
            $table->string('currency', 3);
            $table->string('idempotency_key', 191)->unique();
            $table->string('reference_type', 64)->nullable();
            $table->string('reference_id', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'meter_code', 'occurred_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('credit_accounts', function (Blueprint $table): void {
            $table->uuid('tenant_id')->primary();
            $table->decimal('balance', 15, 4)->default(0);
            $table->string('currency', 3)->default('SAR');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('credit_transactions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('tenant_id')->index();
            $table->string('kind', 16);
            $table->decimal('amount', 15, 4);
            $table->decimal('balance_after', 15, 4);
            $table->string('currency', 3);
            $table->string('idempotency_key', 191)->unique();
            $table->unsignedBigInteger('usage_event_id')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('usage_alerts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('tenant_id');
            $table->string('meter_code', 64);
            $table->string('scope', 64)->default('');
            $table->string('period', 7);
            $table->unsignedSmallInteger('threshold');
            $table->unsignedBigInteger('used');
            $table->unsignedBigInteger('cap');
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'meter_code', 'scope', 'period', 'threshold']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        $now = now();
        DB::table('usage_meters')->insert([
            [
                'id' => (string) Str::uuid(), 'code' => 'ai_tokens_own_key', 'name' => 'AI tokens on your own key',
                'name_ar' => 'رموز الذكاء الاصطناعي على مفتاحك', 'unit' => 'token', 'funded_by' => 'customer', 'unit_cost' => null,
                'currency' => 'SAR', 'charges_credits' => false, 'included_per_plan' => null, 'is_active' => true, 'sort_order' => 10,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'id' => (string) Str::uuid(), 'code' => 'sms_verifications', 'name' => 'SMS verification codes',
                'name_ar' => 'رموز التحقق عبر SMS', 'unit' => 'message', 'funded_by' => 'platform', 'unit_cost' => null,
                'currency' => 'SAR', 'charges_credits' => false, 'included_per_plan' => null, 'is_active' => true, 'sort_order' => 20,
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_alerts');
        Schema::dropIfExists('credit_transactions');
        Schema::dropIfExists('credit_accounts');
        Schema::dropIfExists('usage_events');
        Schema::dropIfExists('usage_meters');
    }
};
