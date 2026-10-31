<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SUPERADMIN-SUPPORT-ACCESS-001 — a platform admin's time-boxed, read-only visit to ONE workspace.
 *
 * A row per visit, kept after it ends: who, which workspace, why, when it started, when it was due to end and when it did. The row
 * is the authority — a browser holding a session key with no live row here gets nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('admin_user_id')->index();
            $table->uuid('tenant_id')->index();
            $table->string('mode', 16)->default('read');
            $table->text('reason');
            $table->string('ip', 64)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_reason', 32)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_sessions');
    }
};
