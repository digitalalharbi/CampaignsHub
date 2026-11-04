<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EMAIL-GAPS (§17.4.5) — unsubscribe, the daily allowance, known sign-in devices, and the link from a
 * source row to its outcome in the mail ledger.
 *
 * `mail_unsubscribes` holds a hash and never the address, so the list of people who left is not itself a
 * mailing list. `mail_dedup_key` lets the mail ledger write each outcome back to the row a screen reads:
 * `report_deliveries` and `client_notifications` would otherwise stay `queued` for ever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_unsubscribes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->char('recipient_hash', 64);
            // A catalogue type, or `alerts` for the alert bundle.
            $table->string('scope', 40);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(['tenant_id', 'recipient_hash', 'scope']);
        });

        Schema::create('mail_daily_usage', function (Blueprint $table): void {
            $table->uuid('tenant_id');
            $table->date('day');
            $table->unsignedInteger('used')->default(0);

            $table->primary(['tenant_id', 'day']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('known_sign_in_devices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->char('fingerprint', 64);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'fingerprint']);
        });

        Schema::table('report_deliveries', function (Blueprint $table): void {
            $table->string('reason', 40)->nullable();
            $table->string('mail_dedup_key', 120)->nullable()->index();
        });

        Schema::table('client_notifications', function (Blueprint $table): void {
            $table->string('mail_dedup_key', 120)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('client_notifications', function (Blueprint $table): void {
            $table->dropColumn('mail_dedup_key');
        });
        Schema::table('report_deliveries', function (Blueprint $table): void {
            $table->dropColumn(['reason', 'mail_dedup_key']);
        });
        Schema::dropIfExists('known_sign_in_devices');
        Schema::dropIfExists('mail_daily_usage');
        Schema::dropIfExists('mail_unsubscribes');
    }
};
