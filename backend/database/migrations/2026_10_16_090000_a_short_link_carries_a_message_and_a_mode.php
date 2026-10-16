<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SHORT-LINK-COMPLIANCE-001 — a WhatsApp link may carry the first message, and may land on a page.
 *
 * `message` is the sanitised text pre-filled in the chat (`?text=`). `mode` is `redirect` (the
 * default and every existing link: one server-side 302 to the destination) or `landing` — a page
 * that states the destination and offers it as a button, for the ad platforms that want a web page
 * rather than a direct hop. Both modes show every visitor, crawler and review bot the SAME thing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('short_links', function (Blueprint $table): void {
            $table->string('message', 500)->nullable()->after('source_value');
            $table->string('mode', 16)->default('redirect')->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('short_links', function (Blueprint $table): void {
            $table->dropColumn(['message', 'mode']);
        });
    }
};
