<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SUPERADMIN-FEATURES-001 — the platform-wide switch for a capability, set from the console. It joins `capabilities.disabled` (the
 * environment's kill switch), which keeps working: a module off in either place is off for every workspace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table): void {
            $table->json('disabled_capabilities')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table): void {
            $table->dropColumn('disabled_capabilities');
        });
    }
};
