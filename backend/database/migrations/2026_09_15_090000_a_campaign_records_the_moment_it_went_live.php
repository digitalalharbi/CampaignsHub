<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-SUCCESS-001 — the moment a campaign went live is a fact about the campaign.
 *
 * Until now `status = active` was the only trace of a launch, and a status carries no time. Anything
 * wanting to say *when* a campaign went live had to either read the audit log or invent a clock of
 * its own — and a browser's clock is not evidence that a server did anything.
 *
 * `updated_at` was not an answer either: it moves every time anyone renames the campaign, so it
 * would quietly drift away from the launch it was standing in for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unified_campaigns', function (Blueprint $table): void {
            $table->timestampTz('activated_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('unified_campaigns', function (Blueprint $table): void {
            $table->dropColumn('activated_at');
        });
    }
};
