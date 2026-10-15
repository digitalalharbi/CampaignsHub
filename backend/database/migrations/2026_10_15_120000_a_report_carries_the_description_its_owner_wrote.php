<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REPORT-SETUP-001 — an optional description, written by the Owner or absent.
 *
 * The pasted-link card and the report header used to compose a sub-line from the client's name, the
 * period and «prepared by». Report setup is the single source of truth for what is visible, so the
 * only description a report shows is the one entered there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('reports', 'description')) {
            return;
        }

        Schema::table('reports', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->dropColumn('description');
        });
    }
};
