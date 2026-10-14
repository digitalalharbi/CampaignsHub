<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SALLA-INSTALL-001 — a store installed from Salla's side, before anybody in CampaignsHub owns it.
 *
 * A merchant who installs the app from the Salla App Store or the partner portal arrives at the
 * callback with no state of ours: no tenant, no user, no workspace. The authorisation is real (Salla
 * issued the code for this app), so the tokens are kept — encrypted, keyed by the merchant — until a
 * signed-in user claims the store into their workspace with the one-time claim code the merchant
 * was handed. Unclaimed installs expire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_store_installs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 32);
            $table->string('merchant_id', 64);
            $table->string('store_name', 1024)->nullable();
            $table->string('store_domain', 2048)->nullable();
            $table->string('store_currency', 8)->nullable();
            $table->text('tokens');
            $table->string('claim_code_hash', 64)->nullable()->unique();
            $table->timestamp('expires_at');
            $table->timestamp('claimed_at')->nullable();
            $table->uuid('claimed_tenant_id')->nullable();
            $table->uuid('provider_connection_id')->nullable();
            $table->timestamps();
            $table->index(['provider', 'merchant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_store_installs');
    }
};
