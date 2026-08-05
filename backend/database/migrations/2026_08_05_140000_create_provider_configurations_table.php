<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROVCFG-001 — the platform operator's provider configuration. ONE row per provider, for the whole
 * install.
 *
 * ## There is no `tenant_id` here, and that is the point
 *
 * These are the SYSTEM's keys — the OAuth app this platform registered with Snapchat, Meta, Salla and
 * the rest. A tenant never sees them, never enters them and never owns one. What a tenant owns is the
 * CONSENT they gave (`provider_connections`) and the accounts that consent discovered
 * (`external_accounts`), both of which are tenant-scoped already. Putting a client secret behind a
 * tenant scope would have meant every workspace registering its own developer app, which is not how
 * any of these platforms are meant to be used and would put an approval queue in front of every
 * customer.
 *
 * ## `credentials` is one encrypted blob, not a column per key
 *
 * Nine providers need eleven differently-named values between them, and a column per value would be a
 * migration every time a provider adds one. More importantly: a JSON column cast `encrypted` is
 * ciphertext as a whole, so a database dump leaks nothing — not even which fields are set. The shape
 * inside it is validated against `ProviderCatalogue`, which is where the per-provider truth lives.
 *
 * ## What is deliberately NOT encrypted
 *
 * `environment`, `enabled`, `last_test_*` and the timestamps. An operator has to be able to see the
 * state of an install without decrypting anything, and none of these is a secret. `last_test_message`
 * carries a provider's refusal text, which is why the service that writes it strips anything that
 * could echo a key back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_configurations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // One row per provider, enforced by the database rather than by whoever writes the next
            // controller — two rows for `meta` would make "which secret is live" a coin toss.
            $table->string('provider')->unique();

            // sandbox | production. Never inferred from APP_ENV: a staging install pointed at a
            // production ad account is a real and deliberate configuration, and guessing would either
            // block it or, far worse, silently promote a sandbox setup to production.
            $table->string('environment')->default('sandbox');

            $table->text('credentials')->nullable();      // encrypted JSON; never returned by any API
            $table->jsonb('scopes')->nullable();          // override for the catalogue's defaults
            $table->boolean('enabled')->default(true);

            // The evidence behind `production_ready`. A configuration that has never been tested is
            // never described as ready, however complete it looks.
            $table->timestampTz('last_tested_at')->nullable();
            $table->string('last_test_status')->nullable();   // passed | failed
            $table->text('last_test_message')->nullable();
            $table->timestampTz('last_rotated_at')->nullable();
            $table->timestampTz('configured_at')->nullable();

            $table->foreignId('configured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['enabled', 'environment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_configurations');
    }
};
