<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AUTH-CODE-SWITCHES-001 — the platform's switches for one-time codes at sign-in and recovery.
 *
 * Purely additive and nullable, and it writes no row. Null reads as every switch ON, which is what those doors do today, so
 * deploying this changes nothing until a Super Admin flips one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table): void {
            $table->json('auth_code_switches')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table): void {
            $table->dropColumn('auth_code_switches');
        });
    }
};
