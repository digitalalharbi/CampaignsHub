<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DIGESTS-VISUAL-V2-001 — the bell can speak Arabic.
 *
 * An alert stored one English headline and one English sentence that began with the campaign's name and quoted
 * provider keys; the Arabic wording existed only for the email. Two nullable columns carry the Arabic title and body
 * beside them, and the API answers with them for an Arabic reader. Additive: a row written before this has none and
 * reads exactly as it did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_notifications', function (Blueprint $table): void {
            $table->text('title_ar')->nullable();
            $table->text('message_ar')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('app_notifications', function (Blueprint $table): void {
            $table->dropColumn(['title_ar', 'message_ar']);
        });
    }
};
