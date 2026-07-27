<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Richer per-client team access on the existing client_workspace_user pivot: an operational access_role
 * (distinct from the legacy client_role portal role), optional per-project restriction (null = all the
 * client's projects), and a grant audit trail. Access is enforced at the API, not just hidden in the UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_workspace_user', function (Blueprint $table) {
            // client_owner|media_buyer|analyst|reporter|viewer|custom
            $table->string('access_role')->default('viewer')->after('client_role');
            // null = access to every project in the client; otherwise a restricted allowlist of project ids.
            $table->jsonb('project_ids')->nullable()->after('access_role');
            $table->foreignId('granted_by')->nullable()->after('project_ids')->constrained('users')->nullOnDelete();
            $table->timestampTz('granted_at')->nullable()->after('granted_by');
        });
    }

    public function down(): void
    {
        Schema::table('client_workspace_user', function (Blueprint $table) {
            $table->dropConstrainedForeignId('granted_by');
            $table->dropColumn(['access_role', 'project_ids', 'granted_at']);
        });
    }
};
