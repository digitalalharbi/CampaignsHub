<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A social identity linked to an account (LOGIN-004).
 *
 * This table is what makes "sign in with Google" a LINK rather than a lookup. Without it the only
 * way to match a returning Google user to an account is by email address — and an email address
 * from a provider is a claim, not proof, so matching on it silently hands over whichever local
 * account happens to share the string. That is account takeover with extra steps, and it is why
 * `provider_user_id` is the key here and email is only ever recorded alongside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_identities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('provider', 32);
            // The provider's OWN stable identifier for this person — `sub` in an OIDC token. Not the
            // email: an email can be changed, reassigned, or claimed by a provider that never
            // verified it, and none of those should move an account to someone else.
            $table->string('provider_user_id');

            // Recorded for display and for audit, never for matching.
            $table->string('email')->nullable();
            $table->boolean('email_verified')->default(false);
            $table->string('name')->nullable();
            $table->string('avatar_url', 2048)->nullable();

            $table->timestamp('linked_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });

        /*
         * One provider account links to at most one local account, and one local account holds at
         * most one identity per provider. Both directions matter: without the first, two people
         * could attach the same Google account and each sign in as the other.
         */
        DB::statement('CREATE UNIQUE INDEX oauth_identities_provider_subject_unique
            ON oauth_identities (provider, provider_user_id)');
        DB::statement('CREATE UNIQUE INDEX oauth_identities_user_provider_unique
            ON oauth_identities (user_id, provider)');
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_identities');
    }
};
