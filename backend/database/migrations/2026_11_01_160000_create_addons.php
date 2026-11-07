<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRICING-ADDONS-001 — the add-ons catalogue and what each workspace holds.
 *
 * `readiness` is what the shop may say: `available` is sold, `coming_soon` is shown without a price and cannot be requested — a
 * service that is not ready is never charged for. `grants` is what an active add-on adds: capacity (`limits`, added to the plan's
 * cap through the same path that enforces it) or modules. `tenant_addons.unit_price`/`currency` are captured when activated, so
 * re-pricing an add-on never re-prices a workspace that holds it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addons', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('kind', 16);
            $table->string('name', 120);
            $table->string('name_ar', 120);
            $table->text('summary_en')->nullable();
            $table->text('summary_ar')->nullable();
            $table->decimal('price_monthly', 15, 2)->nullable();
            $table->string('currency', 3)->default('SAR');
            $table->jsonb('grants')->nullable();
            $table->string('readiness', 16)->default('coming_soon');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('tenant_addons', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('addon_id')->index();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('status', 16);
            $table->decimal('unit_price', 15, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('source', 16);
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('addon_id')->references('id')->on('addons')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_addons');
        Schema::dropIfExists('addons');
    }
};
