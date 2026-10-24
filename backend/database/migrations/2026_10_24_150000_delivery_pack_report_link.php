<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DELIVERY-LINK-001 — the report link a pack sends.
 *
 * The WhatsApp message could name a link only if the browser passed one in, and nothing in the product did, so the message the
 * Owner copied never carried the report. The link is minted ONCE, when the pack is approved, and remembered here so the message,
 * the email and a later copy all say the same address. The raw token is a bearer secret meant to be sent, so it is stored encrypted
 * and never serialised; `share_id` lets the pack tell whether that link is still alive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_packs', function (Blueprint $table) {
            $table->uuid('share_id')->nullable()->after('report_id');
            $table->text('share_token')->nullable()->after('share_id');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_packs', function (Blueprint $table) {
            $table->dropColumn(['share_id', 'share_token']);
        });
    }
};
