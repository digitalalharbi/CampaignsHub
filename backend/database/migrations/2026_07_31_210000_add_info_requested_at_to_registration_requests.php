<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "We need something more from you before we can decide" (SIGNUP-003).
 *
 * Not a thirteenth account state: the application is still exactly where it was — pending approval —
 * and inventing a state for it would put a transition in the machine that leads nowhere and comes
 * back. What changed is only WHO the queue is waiting on, and this timestamp is that fact. It flips
 * the applicant's status screen from "there is nothing for you to do" to the reviewer's note, which
 * is the whole visible difference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registration_requests', function (Blueprint $table): void {
            $table->timestamp('info_requested_at')->nullable()->after('review_note');
        });
    }

    public function down(): void
    {
        Schema::table('registration_requests', function (Blueprint $table): void {
            $table->dropColumn('info_requested_at');
        });
    }
};
