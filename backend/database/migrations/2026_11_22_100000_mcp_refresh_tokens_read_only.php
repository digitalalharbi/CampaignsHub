<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MCP-ANALYTICS-001 — a grant remembers its own scope, so a refresh never changes it.
 * Existing grants default to false (read + propose, exactly what they had); only new connections are written true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_oauth_refresh_tokens', function (Blueprint $table): void {
            $table->boolean('read_only')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('mcp_oauth_refresh_tokens', function (Blueprint $table): void {
            $table->dropColumn('read_only');
        });
    }
};
