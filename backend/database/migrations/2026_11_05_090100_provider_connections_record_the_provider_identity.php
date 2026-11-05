<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INTEG-PROVIDER-IDENTITY-001 — WHICH provider login a connection was authorised with.
 *
 * `created_by` is the CampaignsHub user who pressed connect; it says nothing about the Google / Meta /
 * Snapchat account that granted access, which is what an operator needs when two logins exist.
 *
 * Public profile facts only (a display name, an email the provider chose to return) — never a token.
 * Additive and nullable: NULL means «not recorded», and the interface says exactly that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_connections', function (Blueprint $table): void {
            $table->string('provider_identity_label', 190)->nullable();
            $table->string('provider_identity_email', 190)->nullable();
            $table->timestampTz('provider_identity_fetched_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('provider_connections', function (Blueprint $table): void {
            $table->dropColumn(['provider_identity_label', 'provider_identity_email', 'provider_identity_fetched_at']);
        });
    }
};
