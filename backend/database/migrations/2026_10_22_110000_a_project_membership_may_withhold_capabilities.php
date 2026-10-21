<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MEMBER-VISIBILITY-001 — a membership may WITHHOLD capabilities its role would grant.
 *
 * `permissions` has always been the extras a membership adds; `denied` is the mirror: capabilities this
 * one person must not have on this project, whatever the role preset says — `money.view` for a media
 * buyer who may optimise but not see the bill, `leads.pii.view` for an analyst. Enforced on the server
 * (ProjectAbilities subtracts it), never only by an unmounted control.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_memberships', function (Blueprint $table): void {
            $table->jsonb('denied')->nullable()->after('permissions');
        });
    }

    public function down(): void
    {
        Schema::table('project_memberships', function (Blueprint $table): void {
            $table->dropColumn('denied');
        });
    }
};
