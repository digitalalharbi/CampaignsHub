<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal profile fields for the account/user settings area. Purely per-user (never org-level):
 * display identity, contact, and personal locale/formatting preferences.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('job_title')->nullable()->after('last_name');
            $table->string('phone', 32)->nullable()->after('job_title');
            $table->string('avatar_path')->nullable()->after('phone');
            $table->string('bio', 500)->nullable()->after('avatar_path');
            // Personal preferences (distinct from tenant/workspace settings).
            $table->string('locale', 8)->default('ar')->after('bio');
            $table->string('timezone', 64)->default('Asia/Riyadh')->after('locale');
            $table->string('date_format', 32)->default('YYYY-MM-DD')->after('timezone');
            $table->string('number_format', 16)->default('latin')->after('date_format'); // latin|arabic
            $table->string('theme', 16)->default('system')->after('number_format'); // light|dark|system
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'first_name', 'last_name', 'job_title', 'phone', 'avatar_path', 'bio',
                'locale', 'timezone', 'date_format', 'number_format', 'theme',
            ]);
        });
    }
};
