<?php

declare(strict_types=1);

use App\Domains\Integrations\Models\ProviderConfiguration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * X-OAUTH1-001 — what becomes of anything created under the OAuth 2.0 model X Ads never accepted.
 *
 * ## The platform's X configuration
 *
 * The form asked for an OAuth 2.0 Client ID and Client Secret. Those are not the OAuth 1.0a API Key
 * and API Key Secret — X issues them separately — so a stored pair signs nothing and cannot be carried
 * across. They are removed rather than left as unreachable ciphertext, along with any operator scope
 * override (OAuth 1.0a has no scopes) and the last test verdict, which was about a configuration
 * that no longer exists. With the four new values absent, the provider reads «not configured»: the
 * truth, not a regression.
 *
 * ## Workspace connections
 *
 * An X connection opened under OAuth 2.0 holds a bearer token and no token secret, so it cannot sign a
 * single Ads API request. Leaving it `connected` would keep a green light over a connection that has
 * never been able to return one figure. It is marked `error` with the one instruction that fixes it.
 * Nothing is deleted: its accounts, bindings and any history stay, and connecting X again under
 * OAuth 1.0a re-credentials the SAME connection (`TokenVault::open`), so no account is duplicated.
 *
 * The rule is written for whatever a given install actually holds, whether that is none or several.
 */
return new class extends Migration
{
    private const OBSOLETE_KEYS = ['client_id', 'client_secret'];

    public function up(): void
    {
        ProviderConfiguration::query()->where('provider', 'x')->each(function (ProviderConfiguration $row): void {
            $credentials = $row->credentials ?? [];
            $kept = array_diff_key($credentials, array_flip(self::OBSOLETE_KEYS));

            if ($kept === $credentials && ($row->scopes ?? []) === []) {
                return;
            }

            $row->forceFill([
                'credentials' => $kept,
                'scopes' => null,
                'last_test_status' => null,
                'last_test_message' => null,
                'last_tested_at' => null,
            ])->save();
        });

        DB::table('provider_connections')
            ->where('provider', 'x')
            ->whereNotIn('credential_id', DB::table('integration_credentials')
                ->select('id')
                ->where('credential_type', 'oauth1'))
            ->update([
                'status' => 'error',
                'last_error' => 'This X connection was authorised under OAuth 2.0, which the X Ads API does not accept. Connect X again.',
                'updated_at' => Carbon::now(),
            ]);
    }

    /**
     * Not reversed. The removed values were the wrong kind of credential and cannot sign a request, and
     * restoring `connected` onto a connection that cannot authenticate would put back the false state
     * this migration exists to remove.
     */
    public function down(): void {}
};
