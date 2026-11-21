<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLIENT-CHAT-001 — read receipts on a request's conversation, the way a chat shows them.
 *
 * Each side stamps the other side's messages when it opens the request: a client message is «read» once the team has
 * opened it, a team message once the client has. Null is unread. Existing rows stay null (never read is the honest default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_comments', function (Blueprint $table): void {
            $table->timestampTz('read_by_team_at')->nullable();
            $table->timestampTz('read_by_client_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('request_comments', function (Blueprint $table): void {
            $table->dropColumn(['read_by_team_at', 'read_by_client_at']);
        });
    }
};
