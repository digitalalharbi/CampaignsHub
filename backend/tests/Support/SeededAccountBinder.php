<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Deny by default needs a selected account — and a fixture that writes figures for an account without ever selecting it is a world
 * the product cannot produce (a sync only runs for an assigned account). This is the one place a TEST world does what an operator
 * does: when a row naming (project, account) is inserted and that account holds no binding record ANYWHERE, it is created (if only
 * an id was seeded) and ACTIVELY bound to the project.
 *
 * It never touches an account that already has a binding of any state: a deselected account stays deselected and one bound to
 * another project stays there, which is exactly what the isolation tests seed. A test that asserts the NO-binding branch itself opts
 * out with `protected bool $selectsSeededAccounts = false;`. The product rule is not involved.
 */
final class SeededAccountBinder
{
    private const WATCHED = [
        'daily_metrics', 'entity_daily_metrics', 'external_campaigns', 'commerce_orders', 'commerce_products',
        'commerce_customers', 'commerce_abandoned_carts', 'metric_sync_runs',
    ];

    private static bool $busy = false;

    public static function listen(): void
    {
        /*
         * A test that states a binding itself — a deselected account, one selected for ANOTHER project — has said what it means, and
         * that supersedes the implicit selection made when its rows were written first.
         */
        ProjectIntegrationBinding::creating(static function (ProjectIntegrationBinding $binding): void {
            DB::table('project_integration_bindings')
                ->where('external_account_id', $binding->external_account_id)
                ->whereRaw("(settings->>'fixture') = 'true'")
                ->delete();
        });

        DB::listen(static function (QueryExecuted $query): void {
            if (self::$busy || ! str_starts_with($query->sql, 'insert into "')) {
                return;
            }

            if (preg_match('/^insert into "(\w+)" \((.*?)\) values (.*?)(?: on conflict.*)?$/s', $query->sql, $m) !== 1 || ! in_array($m[1], self::WATCHED, true)) {
                return;
            }

            preg_match_all('/"(\w+)"/', $m[2], $cols);
            $columns = $cols[1];
            $width = count($columns);
            $account = array_search('external_account_id', $columns, true);
            $project = array_search('project_id', $columns, true);
            $provider = array_search('provider', $columns, true);

            if ($account === false || $project === false || $width === 0) {
                return;
            }

            $rows = intdiv(substr_count($m[3], '?'), $width);

            self::$busy = true;
            try {
                for ($i = 0; $i < $rows; $i++) {
                    $row = array_slice($query->bindings, $i * $width, $width);
                    if (is_string($row[$account] ?? null) && is_string($row[$project] ?? null) && Str::isUuid($row[$account]) && Str::isUuid($row[$project])) {
                        self::ensure($row[$project], $row[$account], is_string($row[$provider] ?? null) ? $row[$provider] : 'meta', onlyIfUnbound: true);
                    }
                }
            } finally {
                self::$busy = false;
            }
        });
    }

    public static function ensure(string $projectId, string $accountId, string $provider = 'meta', bool $onlyIfUnbound = false): string
    {
        if ($onlyIfUnbound && DB::table('project_integration_bindings')->where('external_account_id', $accountId)->exists()) {
            return $accountId;
        }

        $project = DB::table('projects')->where('id', $projectId)->first(['tenant_id', 'client_workspace_id']);

        if ($project === null) {
            return $accountId;
        }

        $account = DB::table('external_accounts')->where('id', $accountId)->first(['provider']);

        if ($account === null) {
            $connection = DB::table('provider_connections')->where('tenant_id', $project->tenant_id)->where('provider', $provider)->value('id');

            if ($connection === null) {
                $credential = new IntegrationCredential([
                    'tenant_id' => $project->tenant_id, 'provider' => $provider, 'credential_scope' => 'project_only',
                    'credential_type' => 'oauth', 'status' => 'active',
                ]);
                $credential->setPayload('token');
                $credential->save();

                $connection = ProviderConnection::withoutGlobalScopes()->create([
                    'tenant_id' => $project->tenant_id, 'credential_id' => $credential->id, 'provider' => $provider,
                    'connection_name' => $provider.'-'.Str::random(6), 'scope' => 'project_only', 'status' => 'connected',
                ])->id;
            }

            (new ExternalAccount)->forceFill([
                'id' => $accountId, 'tenant_id' => $project->tenant_id, 'provider_connection_id' => $connection, 'provider' => $provider,
                'account_type' => 'ad_account', 'external_id' => 'act-'.Str::random(8), 'name' => 'seeded', 'status' => 'active',
            ])->save();
        } else {
            $provider = (string) $account->provider;
        }

        $exists = DB::table('project_integration_bindings')
            ->where('project_id', $projectId)->where('external_account_id', $accountId)->where('purpose', 'advertising')->exists();

        if (! $exists) {
            DB::table('project_integration_bindings')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $project->tenant_id, 'client_workspace_id' => $project->client_workspace_id,
                'project_id' => $projectId, 'external_account_id' => $accountId, 'provider' => $provider, 'purpose' => 'advertising',
                'is_active' => true, 'sync_enabled' => true, 'settings' => json_encode(['fixture' => true]), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $accountId;
    }
}
