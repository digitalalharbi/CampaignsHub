<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * META-CANDIDATE-001 — the Candidate Meta app's connection lives in a table of its own.
 *
 * Not a `provider_connections` row with a flag. Every surface that syncs, binds, lists or refreshes a
 * customer's accounts reads that table (and `external_accounts`, which hangs off it). A candidate row
 * there would be one missed `where profile = live` away from a test token syncing into a client's
 * project. Here it is structurally unreachable: no binding can point at it and no sweep reads it.
 *
 * Additive only. Nothing existing is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_candidate_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Always `candidate`, enforced below — the marking the isolation tests read.
            $table->string('profile')->default('candidate');
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            // started | succeeded | failed
            $table->string('status')->default('started');
            // HMAC of the exact credentials the run used; a promotion must match it.
            $table->string('credential_fingerprint')->nullable();
            $table->string('app_id_hint', 8)->nullable();
            $table->text('encrypted_token')->nullable();          // encrypted; never serialised
            $table->timestampTz('token_expires_at')->nullable();
            $table->jsonb('granted_scopes')->nullable();
            // id / name / status / currency only. NOT external_accounts, and never bindable.
            $table->jsonb('discovered_accounts')->nullable();
            $table->jsonb('steps')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'finished_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE meta_candidate_connections ADD CONSTRAINT meta_candidate_connections_profile_check CHECK (profile = 'candidate')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_candidate_connections');
    }
};
