<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Conflicts the client-portal backfill refuses to guess at (PORTAL-AUTH-001).
 *
 * Skipping a contact keeps the migration safe, but a skip that leaves no trace leaves a person
 * stranded: their portal keeps working on the old engine, and on the day it is retired they simply
 * cannot get in, with nobody having been told. Each refusal is therefore recorded here as a row a
 * human resolves.
 *
 * Resolving is deliberately NOT automatic. The one conflict that actually occurs — a contact email
 * that is already a staff account — has two legitimate answers (the same person wearing two hats, or
 * two different people sharing an address), and choosing wrong grants an agency employee a client's
 * view or the reverse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_identity_conflicts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('reason');                       // email_belongs_to_staff | phone_only_no_email | …
            $table->json('client_ids');                     // what they WOULD have been granted

            // How a human settled it, and who. Null while open.
            $table->string('resolution')->nullable();       // linked | separated | dismissed
            $table->text('note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'resolution']);
        });

        // One OPEN conflict per contact per tenant: re-running the backfill must not pile up
        // duplicates of the same unresolved problem, which is how a register becomes unreadable and
        // then ignored.
        DB::statement(
            'CREATE UNIQUE INDEX portal_identity_conflicts_open_unique
             ON portal_identity_conflicts (tenant_id, lower(contact_email), reason)
             WHERE resolution IS NULL AND contact_email IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_identity_conflicts');
    }
};
