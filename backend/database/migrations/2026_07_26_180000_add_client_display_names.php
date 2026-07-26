<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real client-facing names, so client reports never depend on regex sanitisation alone. When set, the
 * display name wins over the (sanitised) internal name in every client surface.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unified_campaigns', function (Blueprint $table) {
            $table->string('client_display_name')->nullable()->after('name');
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->string('client_display_name')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('unified_campaigns', fn (Blueprint $t) => $t->dropColumn('client_display_name'));
        Schema::table('projects', fn (Blueprint $t) => $t->dropColumn('client_display_name'));
    }
};
