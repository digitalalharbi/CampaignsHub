<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fail-closed public-exposure flag on taxonomy options. Default FALSE so nothing is ever public unless a seeder
 * explicitly publishes it — the public paid-media catalog endpoint serves ONLY options with is_public=true
 * (plus platform scope + active + the request.paid_service definition). is_public is a FILTER: it is never
 * returned in any public payload. Additive + nullable-safe; existing rows default to non-public.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taxonomy_options', function (Blueprint $table): void {
            if (! Schema::hasColumn('taxonomy_options', 'is_public')) {
                $table->boolean('is_public')->default(false)->after('is_active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('taxonomy_options', function (Blueprint $table): void {
            if (Schema::hasColumn('taxonomy_options', 'is_public')) {
                $table->dropColumn('is_public');
            }
        });
    }
};
