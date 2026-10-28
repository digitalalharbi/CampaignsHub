<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * META-SAAS-APP-001 — the Meta app that issued a connection's token, recorded on the connection.
 *
 * Additive and nullable: every existing row stays NULL, which reads as the Live app — exactly the app that issued every connection made
 * before this. No row is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_connections', function (Blueprint $table): void {
            $table->string('app_profile', 16)->nullable()->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('provider_connections', function (Blueprint $table): void {
            $table->dropColumn('app_profile');
        });
    }
};
