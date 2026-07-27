<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow tenant-level notifications (no specific recipient) — e.g. an SLA breach on an UNASSIGNED request
 * is a workspace notification, not a per-user one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_notifications', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Left nullable — reverting could orphan existing tenant-level notifications.
    }
};
