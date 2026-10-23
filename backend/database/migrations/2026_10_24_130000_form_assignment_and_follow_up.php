<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FORMS-ASSIGNMENT-001 — who gets a form's lead, and when somebody is expected to have called.
 *
 * `default_owner_id` stays the single-owner answer. `assignment_mode = round_robin` spreads leads over `assignment_owner_ids`
 * (the person with the fewest of THIS form's leads goes next, so a returning agent is not buried and a new one is not starved).
 * `follow_up_minutes` stamps `next_follow_up_at` on arrival, which is what the follow-up workspace and its overdue alerts already
 * read: the form does not invent a second reminder system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_forms', function (Blueprint $table): void {
            // fixed | round_robin
            $table->string('assignment_mode', 12)->default('fixed')->after('default_owner_id');
            $table->json('assignment_owner_ids')->nullable()->after('assignment_mode');
            $table->unsignedSmallInteger('follow_up_minutes')->nullable()->after('assignment_owner_ids');
        });
    }

    public function down(): void
    {
        Schema::table('lead_forms', function (Blueprint $table): void {
            $table->dropColumn(['assignment_mode', 'assignment_owner_ids', 'follow_up_minutes']);
        });
    }
};
