<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * META-APP-SWITCH-GUARD-001 — the App ID that issued (or last successfully refreshed) a connection's token.
 *
 * Meta tokens are app-scoped: only the app that issued a token can refresh it or sign calls with it. Switching the Live app's identity
 * (promote or rollback) away from that app silently stops every such connection syncing. Recording the issuer lets the switch be
 * refused while connections still depend on the current app. An App ID is public, never a secret.
 *
 * Additive and nullable. Existing rows stay NULL, which the guard reads as «issued by the current Live app» — the conservative answer,
 * since that is the only app that could have refreshed them since the last switch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_connections', function (Blueprint $table): void {
            $table->string('issuing_app_id', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('provider_connections', function (Blueprint $table): void {
            $table->dropColumn('issuing_app_id');
        });
    }
};
