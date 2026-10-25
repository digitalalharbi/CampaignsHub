<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DELIVERY-NOTES-001 — hand-written notes beside the generated ones.
 *
 * `audience` says who may read a note: `client` (once approved) or `internal` (never leaves the agency). `position` is the order the
 * author chose; null keeps the generated order. Existing rows are all client-audience, unordered — exactly what they were.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_annotations', function (Blueprint $table) {
            $table->string('audience', 16)->default('client')->after('status');
            $table->unsignedInteger('position')->nullable()->after('audience');
        });
    }

    public function down(): void
    {
        Schema::table('report_annotations', function (Blueprint $table) {
            $table->dropColumn(['audience', 'position']);
        });
    }
};
