<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client classification is now MANAGEABLE (not just default columns): follow-up priority, default currency,
 * timezone, language, week-start, plus a client-level `settings` bag (report identity, report prefs, client
 * alert prefs) and an explicit archive timestamp separate from soft-delete (archive = pause, not delete).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_workspaces', function (Blueprint $table) {
            // Follow-up / attention priority (attention STATE is carried by client_status='needs_attention').
            $table->string('priority')->default('normal')->after('client_status'); // low|normal|high
            $table->string('default_currency', 3)->nullable()->after('priority');
            $table->string('timezone')->nullable()->after('default_currency');
            $table->string('language', 8)->default('ar')->after('timezone');
            $table->string('week_start', 10)->default('sunday')->after('language'); // sunday|monday
            // Report identity, report prefs, client alert prefs, logo override — flexible per-client bag.
            $table->jsonb('settings')->nullable()->after('limits');
            // Archive is a lifecycle pause, distinct from soft-delete; restore clears it.
            $table->timestampTz('archived_at')->nullable()->after('week_start');
            $table->foreignId('archived_by')->nullable()->after('archived_at')->constrained('users')->nullOnDelete();

            $table->index(['tenant_id', 'client_status']);
            $table->index(['tenant_id', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::table('client_workspaces', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'client_status']);
            $table->dropIndex(['tenant_id', 'owner_id']);
            $table->dropConstrainedForeignId('archived_by');
            $table->dropColumn(['priority', 'default_currency', 'timezone', 'language', 'week_start', 'settings', 'archived_at']);
        });
    }
};
