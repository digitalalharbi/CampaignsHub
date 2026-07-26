<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings support: team member lifecycle (disable + last-login) and two-factor auth on users, plus a
 * per-user notification preferences table. All tenant-scoped through the owning user/row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('disabled_at')->nullable()->after('email_verified_at');
            $table->timestampTz('last_login_at')->nullable()->after('disabled_at');
            $table->text('two_factor_secret')->nullable()->after('password'); // encrypted
            $table->boolean('two_factor_enabled')->default(false)->after('two_factor_secret');
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->jsonb('channels');    // { in_app: bool, email: bool }
            $table->jsonb('categories');  // { budget: {...}, performance: {...}, sync: {...}, ... }
            $table->jsonb('quiet_hours')->nullable(); // { enabled, start, end }
            $table->string('frequency')->default('realtime'); // realtime|hourly|daily
            $table->jsonb('project_ids')->nullable(); // null = all projects
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['disabled_at', 'last_login_at', 'two_factor_secret', 'two_factor_enabled']);
        });
    }
};
