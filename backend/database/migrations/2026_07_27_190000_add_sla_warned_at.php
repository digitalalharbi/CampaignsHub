<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Idempotency marker so the scheduled SLA evaluator warns/breaches each request at most once. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_requests', function (Blueprint $table): void {
            $table->timestampTz('sla_warned_at')->nullable()->after('sla_breached_at');
        });
    }

    public function down(): void
    {
        Schema::table('external_requests', function (Blueprint $table): void {
            $table->dropColumn('sla_warned_at');
        });
    }
};
