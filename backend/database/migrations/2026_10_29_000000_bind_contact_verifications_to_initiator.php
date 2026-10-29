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
            // Hash of the initiating user/session identity. It is not an account identifier at rest.
            $table->char('owner_key_hash', 64)->nullable()->index()->after('purpose');
        });
    }

    public function down(): void
    {
        Schema::table('request_contact_verifications', function (Blueprint $table): void {
            $table->dropColumn('owner_key_hash');
        });
    }
};
