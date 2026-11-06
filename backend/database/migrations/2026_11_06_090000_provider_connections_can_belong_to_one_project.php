<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INTEG-PROJECT-LINK-001 (Owner 2026-11-06) — a connection opened from a project belongs to THAT project.
 *
 * Additive and nullable. NULL is every connection that existed before this rule: workspace-wide, read and
 * synced exactly as it was. A value names the one project whose link journey opened the connection — the
 * vault re-credentials it only for that project, and the engine refuses to bind one of its accounts anywhere
 * else. No row is rewritten and no account changes its id.
 *
 * No foreign key on purpose: projects are soft-deleted, and a connection must never silently turn
 * workspace-wide because the project it belonged to went away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_connections', function (Blueprint $table): void {
            $table->uuid('project_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('provider_connections', function (Blueprint $table): void {
            $table->dropIndex(['project_id']);
            $table->dropColumn('project_id');
        });
    }
};
