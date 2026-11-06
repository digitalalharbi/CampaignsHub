<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOTIFICATION-CHANNELS-001 — Owner, 2026-11-06: «لاحقًا على الواتساب».
 *
 * Where a person's WhatsApp number and their consent live, so that the channel can be switched on
 * later without a data migration then. Additive and nullable: nothing reads these until a provider
 * reports configured, and an empty column means «no number, no opt-in».
 *
 * ## Why not `users.phone`
 *
 * `phone` is a contact detail and — once `phone_verified_at` is set — a sign-in credential. A WhatsApp
 * number is a different promise: consent to be MESSAGED, revocable by replying STOP, and not always the
 * number somebody signs in with. Reusing one column would let withdrawing marketing consent un-verify a
 * login, or proving a login number imply consent to be messaged.
 *
 * - `whatsapp_number` — E.164, normalised by `PhoneNumber::normalise()`.
 * - `whatsapp_opt_in_at` — set only when the number was PROVED with a code AND the person ticked the
 *   consent box; business-initiated WhatsApp needs both.
 * - `whatsapp_opt_in_source` — where the consent was given (`settings_checkbox`).
 * - `whatsapp_opted_out_at` — set by the person or by a STOP reply. Later than the opt-in means «no».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('whatsapp_number', 20)->nullable();
            $table->timestampTz('whatsapp_opt_in_at')->nullable();
            $table->string('whatsapp_opt_in_source', 40)->nullable();
            $table->timestampTz('whatsapp_opted_out_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['whatsapp_number', 'whatsapp_opt_in_at', 'whatsapp_opt_in_source', 'whatsapp_opted_out_at']);
        });
    }
};
