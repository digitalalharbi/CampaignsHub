<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CP-USERS-001 — an invitation can be into a CLIENT'S portal, not only into the agency's workspace.
 *
 * The same table and the same token mechanics (secure, hashed, expiring, accepted once); three nullable
 * columns say that this one grants a client-portal membership confined to one client workspace, with a role.
 * Rows that predate this have `portal = null` and behave exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_invitations', function (Blueprint $table) {
            $table->string('portal')->nullable()->after('role_slug');
            $table->uuid('client_workspace_id')->nullable()->after('portal');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_invitations', function (Blueprint $table) {
            $table->dropColumn(['portal', 'client_workspace_id']);
        });
    }
};
