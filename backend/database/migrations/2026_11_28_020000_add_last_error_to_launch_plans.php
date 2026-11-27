<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-CLAIM-RELEASE-001 — why the last publish of a plan stopped, so a failed launch names its cause instead of leaving the
 * plan claimed («launch_in_progress») with the reason lost. Add-only, nullable: no existing row is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('launch_plans', function (Blueprint $table): void {
            $table->text('last_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('launch_plans', function (Blueprint $table): void {
            $table->dropColumn('last_error');
        });
    }
};
