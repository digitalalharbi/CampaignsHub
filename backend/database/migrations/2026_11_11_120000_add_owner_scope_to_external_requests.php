<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PLATFORM-SERVICE-INBOX-001 — who a service request belongs to, written down.
 *
 * Until now the owner was implied: every request carried a `tenant_id`, so a service ordered from the
 * official CampaignsHub site could only be filed under a subscriber's workspace. `owner_scope` says it
 * outright — `tenant` (a subscriber's own intake, the only kind that existed) or `platform` (a service the
 * platform owner sells, with no tenant at all).
 *
 * Additive and reversible. Every existing row keeps its `tenant_id` and reads `tenant`, which is what it
 * always was; nothing is reassigned, and `down()` only removes the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_requests', function (Blueprint $table): void {
            $table->string('owner_scope', 16)->default('tenant');
            $table->index(['owner_scope', 'status_id']);
        });
    }

    public function down(): void
    {
        Schema::table('external_requests', function (Blueprint $table): void {
            $table->dropIndex(['owner_scope', 'status_id']);
            $table->dropColumn('owner_scope');
        });
    }
};
