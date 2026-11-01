<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SUPERADMIN-LIMITS-001 — a workspace's own plan-limit cap, set from the console. One row per workspace and metric; a null
 * `limit_value` is unlimited; `expires_at` lets a temporary raise lapse on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_limit_overrides', function (Blueprint $table): void {
            $table->id();
            $table->uuid('tenant_id');
            $table->string('metric', 64);
            $table->unsignedInteger('limit_value')->nullable();
            $table->text('reason');
            $table->unsignedBigInteger('set_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'metric']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_limit_overrides');
    }
};
