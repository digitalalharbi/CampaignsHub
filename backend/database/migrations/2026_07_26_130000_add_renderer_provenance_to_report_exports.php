<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Renderer provenance on each export so a download can prove the file was produced by the CURRENT
 * engine + template against the CURRENT snapshot — and refuse (regenerate) a stale legacy file.
 * This is what stops an old Dompdf/cached export from ever reaching a client again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_exports', function (Blueprint $table) {
            $table->string('renderer')->nullable()->after('format');            // chromium|dompdf
            $table->string('renderer_version')->nullable()->after('renderer');
            $table->string('template_version')->nullable()->after('renderer_version');
            $table->string('snapshot_checksum')->nullable()->after('template_version');
            $table->string('locale')->nullable()->after('snapshot_checksum');    // ar|en
            $table->string('layout_mode')->nullable()->after('locale');          // presentation|document
            $table->string('validation_status')->default('unknown')->after('layout_mode'); // passed|failed|unknown
        });
    }

    public function down(): void
    {
        Schema::table('report_exports', function (Blueprint $table) {
            $table->dropColumn([
                'renderer', 'renderer_version', 'template_version',
                'snapshot_checksum', 'locale', 'layout_mode', 'validation_status',
            ]);
        });
    }
};
