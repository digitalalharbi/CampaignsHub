<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * META-SAAS-APP-001 — the SaaS app's own test round trips sit in the same isolated table as the Candidate's.
 *
 * The table still never holds a Live run: the constraint widens from «candidate» to «candidate or saas» and no further.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE meta_candidate_connections DROP CONSTRAINT IF EXISTS meta_candidate_connections_profile_check');
            DB::statement("ALTER TABLE meta_candidate_connections ADD CONSTRAINT meta_candidate_connections_profile_check CHECK (profile IN ('candidate', 'saas'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE meta_candidate_connections DROP CONSTRAINT IF EXISTS meta_candidate_connections_profile_check');
            DB::statement("ALTER TABLE meta_candidate_connections ADD CONSTRAINT meta_candidate_connections_profile_check CHECK (profile = 'candidate')");
        }
    }
};
