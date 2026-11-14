<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LINKEDIN-SCOPES-001 — what a consent ASKED for, kept apart from what it GRANTED.
 *
 * `provider_connections.scopes` used to receive the requested list whenever the provider returned no `scope`, so the
 * column could describe a wish as a grant. From now on `scopes` holds only a returned grant (empty = unknown) and
 * `requested_scopes` holds the request. Additive and nullable: existing rows keep their values; no rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_connections', function (Blueprint $table): void {
            $table->jsonb('requested_scopes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('provider_connections', function (Blueprint $table): void {
            $table->dropColumn('requested_scopes');
        });
    }
};
