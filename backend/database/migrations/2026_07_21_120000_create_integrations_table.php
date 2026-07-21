<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-scoped advertising integration connections. Tokens/secrets are NEVER stored in this table
 * in plaintext — the `credentials` column is reserved for an encrypted payload once real OAuth is
 * wired; until then connections sit in `awaiting_credentials`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('connector_key');
            $table->string('status')->default('awaiting_credentials');
            $table->string('ad_account_id')->nullable();
            $table->binary('credentials')->nullable(); // encrypted blob, never plaintext
            $table->jsonb('meta')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->string('last_sync_error')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'connector_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
