<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Normalize legacy Task values to the canonical vocabulary the domain validates against
 * (status: backlog|todo|in_progress|waiting_client|blocked|review|completed|cancelled;
 *  priority: low|normal|high|urgent). Earlier request→task conversion wrote status 'open' and
 * priority 'medium', which are outside that set. Map them to the closest canonical value.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tasks')->where('status', 'open')->update(['status' => 'todo']);
        DB::table('tasks')->where('priority', 'medium')->update(['priority' => 'normal']);
    }

    public function down(): void
    {
        // Irreversible normalization — 'todo'/'normal' also arise legitimately, so we do not guess a rollback.
    }
};
