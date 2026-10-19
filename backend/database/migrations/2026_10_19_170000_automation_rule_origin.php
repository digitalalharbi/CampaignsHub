<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AUTO-RULES-AGENTS-009 — who wrote a rule. A rule an agent drafted is marked as such for as long as it
 * exists, so the dashboard can say so and the audit can tell a person's rule from an agent's suggestion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_rules', function (Blueprint $table): void {
            $table->string('origin', 16)->default('person');
            $table->string('drafted_by_agent', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('automation_rules', function (Blueprint $table): void {
            $table->dropColumn(['origin', 'drafted_by_agent']);
        });
    }
};
