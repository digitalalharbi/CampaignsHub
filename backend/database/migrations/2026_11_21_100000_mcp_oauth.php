<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MCP-OAUTH-001 — «Add custom connector» in Claude or ChatGPT: the app registers itself, the person signs in to CampaignsHub and
 * presses «سماح», and the app receives a token that acts as that person. Codes and refresh tokens are stored only as hashes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_oauth_clients', function (Blueprint $table): void {
            $table->id();
            $table->string('client_id', 64)->unique();
            $table->string('name', 120);
            $table->json('redirect_uris');
            $table->timestamps();
        });

        Schema::create('mcp_oauth_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code_hash', 64)->unique();
            $table->string('client_id', 64)->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('redirect_uri');
            $table->string('code_challenge', 128);
            // MCP-SCOPE-001 — the workspace the person was in when they pressed «سماح»; the token answers from it alone.
            $table->uuid('membership_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mcp_oauth_refresh_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->string('client_id', 64)->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('access_token_id')->nullable()->index();
            $table->uuid('membership_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_oauth_refresh_tokens');
        Schema::dropIfExists('mcp_oauth_codes');
        Schema::dropIfExists('mcp_oauth_clients');
    }
};
