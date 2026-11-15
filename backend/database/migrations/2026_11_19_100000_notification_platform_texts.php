<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOTIF-V2-SETTINGS-UI-001 — the platform layer of the notification texts (Owner spec §0 layer 1, §15): the phrasing
 * dictionary, the default disclaimer, the footprint invitation line and the default templates per workspace type.
 *
 * Platform table, no tenant data, written only from the admin console. Additive: one table, dropped by down().
 * Keys (see docs/notifications-v2/R-notes.md): `phrasing.dictionary`, `disclaimer.default`, `footprint.invitation`,
 * `templates.<workspace_type>`. A workspace overrides each in its own `workspace_notification_settings.settings`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_platform_texts', function (Blueprint $table): void {
            $table->string('key', 80)->primary();
            $table->jsonb('value');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_platform_texts');
    }
};
