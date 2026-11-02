<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_contact_verifications', function (Blueprint $table): void {
            $table->string('provider', 32)->nullable()->after('owner_key_hash');
        });
        Schema::table('registration_verifications', function (Blueprint $table): void {
            $table->string('provider', 32)->nullable()->after('channel');
        });
    }

    public function down(): void
    {
        Schema::table('request_contact_verifications', function (Blueprint $table): void {
            $table->dropColumn('provider');
        });
        Schema::table('registration_verifications', function (Blueprint $table): void {
            $table->dropColumn('provider');
        });
    }
};
