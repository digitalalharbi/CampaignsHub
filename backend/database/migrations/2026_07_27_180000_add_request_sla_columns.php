<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SLA lifecycle timestamps evaluated in the backend (never a client-side counter as source of truth),
 * plus archive metadata. `sla_due_at` already exists on external_requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_requests', function (Blueprint $table): void {
            $table->timestampTz('sla_started_at')->nullable()->after('sla_due_at');
            $table->timestampTz('sla_paused_at')->nullable()->after('sla_started_at');
            $table->timestampTz('sla_resumed_at')->nullable()->after('sla_paused_at');
            $table->timestampTz('sla_breached_at')->nullable()->after('sla_resumed_at');
            $table->unsignedInteger('sla_paused_seconds')->default(0)->after('sla_breached_at');
            $table->timestampTz('archived_at')->nullable()->after('is_demo');
            $table->timestampTz('last_activity_at')->nullable()->after('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('external_requests', function (Blueprint $table): void {
            $table->dropColumn([
                'sla_started_at', 'sla_paused_at', 'sla_resumed_at', 'sla_breached_at',
                'sla_paused_seconds', 'archived_at', 'last_activity_at',
            ]);
        });
    }
};
