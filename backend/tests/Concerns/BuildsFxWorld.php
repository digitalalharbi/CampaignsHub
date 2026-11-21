<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * FX-ENGINE-001 fixtures: a workspace, a project and a Google ad account billed in EUR. Synthetic names only.
 */
trait BuildsFxWorld
{
    private ?Tenant $fxTenant = null;

    private ?Project $fxProject = null;

    protected function fxAdmin(string $email = 'fx-admin@example.test'): User
    {
        $user = User::create(['name' => 'FX Admin', 'email' => $email, 'password' => 'secret123']);
        $user->forceFill(['is_platform_admin' => true])->save();

        return $user;
    }

    protected function fxTenant(): Tenant
    {
        return $this->fxTenant ??= Tenant::create(['name' => 'FX Workspace', 'slug' => 'fx-world', 'status' => 'active']);
    }

    protected function fxProject(): Project
    {
        if ($this->fxProject !== null) {
            return $this->fxProject;
        }
        app(TenantContext::class)->setTenantId((string) $this->fxTenant()->id);
        $workspace = ClientWorkspace::create(['name' => 'Client A', 'slug' => 'fx-world-client', 'mode' => 'managed', 'default_currency' => 'SAR']);

        return $this->fxProject = Project::create(['client_workspace_id' => $workspace->id, 'name' => 'Project A', 'status' => 'active']);
    }

    /** A connected ad account in `$currency` (read-only fixture — nothing here talks to a platform). */
    protected function fxAdAccount(string $currency = 'EUR', string $provider = 'google'): ExternalAccount
    {
        $this->fxProject(); // sets the tenant context the credential and connection take their tenant from
        $credential = new IntegrationCredential(['provider' => $provider, 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('t');
        $credential->save();
        $connection = ProviderConnection::create(['credential_id' => $credential->id, 'provider' => $provider, 'connection_name' => 'fx-'.$currency.'-'.Uuid::uuid4(), 'scope' => 'project_only', 'status' => 'connected']);

        $account = new ExternalAccount;
        $account->forceFill([
            'id' => (string) Uuid::uuid4(), 'tenant_id' => $this->fxTenant()->id, 'provider_connection_id' => $connection->id,
            'provider' => $provider, 'account_type' => 'ad_account', 'external_id' => 'acc-'.Uuid::uuid4(), 'name' => 'Ad account '.$currency,
            'currency' => $currency, 'status' => 'active',
        ])->save();

        return $account;
    }

    /** A stored spend row as ingest writes it; `$rate` null = withheld (no rate existed at ingest). */
    protected function fxSpendRow(ExternalAccount $account, float $amount, string $date, ?float $rate = null): string
    {
        $id = (string) Uuid::uuid4();
        DB::table('daily_metrics')->insert([
            'id' => $id, 'tenant_id' => $this->fxTenant()->id, 'project_id' => $this->fxProject()->id,
            'external_account_id' => $account->id, 'external_campaign_id' => (string) Uuid::uuid4(), 'provider' => $account->provider,
            'metric_key' => 'spend', 'metric_date' => $date,
            'value' => $rate === null ? null : $amount * $rate, 'original_amount' => $amount, 'original_currency' => $account->currency,
            'project_currency' => 'USD', 'converted_amount' => $rate === null ? null : $amount * $rate, 'exchange_rate' => $rate,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
