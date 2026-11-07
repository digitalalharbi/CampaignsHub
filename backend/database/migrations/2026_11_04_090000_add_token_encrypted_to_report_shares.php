<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REPORT-LIFECYCLE-MENU-001 — «copy the current link» for a link that already exists.
 *
 * Only the sha256 of a link's token was ever stored, so the URL of an existing link could not be shown again and the
 * only way to «copy» it was to make a new one. New links also keep their token encrypted with the application key, which
 * lets an owner who may share copy the SAME link. Links made before this have no value here and stay exactly as they are:
 * the interface says so rather than minting a replacement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_shares', function (Blueprint $table): void {
            $table->text('token_encrypted')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('report_shares', function (Blueprint $table): void {
            $table->dropColumn('token_encrypted');
        });
    }
};
