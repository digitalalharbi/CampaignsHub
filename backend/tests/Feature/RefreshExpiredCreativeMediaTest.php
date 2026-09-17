<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativePresenter;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Support\AssetExpiry;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AD-MEDIA-RECOVERY-002 — an expired platform link is re-signed by creative id, for the selected
 * account only, and a refusal leaves the truthful «expired» state rather than a blank.
 */
final class RefreshExpiredCreativeMediaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Media', 'slug' => 'media-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $workspace = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active',
        ]);
    }

    /**
     * Two Snapchat accounts on one connection, only one selected. The selected account's expired
     * still is re-signed; the unselected account's is never asked for and stays expired.
     */
    public function test_only_the_selected_accounts_expired_media_is_refreshed(): void
    {
        $this->configure('snapchat');
        [$selected, $unselected] = $this->twoAccountsOneConnection('snapchat');

        $dead = 'https://cf.snapchat.com/o/old.jpg?e='.now()->subHour()->getTimestamp().'&s=a';
        $mine = $this->creativeUnder($selected, 'cr-mine', $dead);
        $theirs = $this->creativeUnder($unselected, 'cr-theirs', $dead);

        $fresh = 'https://cf.snapchat.com/o/new.jpg?e='.now()->addWeek()->getTimestamp().'&s=b';
        Http::fake([
            '*adaccounts/act-selected/creatives*' => Http::response(['creatives' => [
                ['creative' => ['id' => 'cr-mine', 'name' => 'Mine', 'type' => 'WEB_VIEW', 'top_snap_media_id' => 'me-1']],
            ]], 200),
            '*get_media_by_ids*' => Http::response(['media' => [
                ['media' => ['id' => 'me-1', 'type' => 'IMAGE', 'download_link' => $fresh]],
            ]], 200),
            '*' => Http::response(['request_status' => 'ERROR'], 404),
        ]);

        Artisan::call('integrations:refresh-expired-media');

        $this->assertSame($fresh, $mine->fresh()->asset_url, 'the selected account\'s expired still was not re-signed');
        $this->assertTrue($mine->fresh()->asset_expires_at->isFuture());
        $this->assertSame($dead, $theirs->fresh()->asset_url, 'an unselected account\'s creative was touched');
        Http::assertNotSent(static fn ($r): bool => str_contains((string) $r->url(), 'act-unselected'));
    }

    /**
     * Meta refuses the account (`#200 … ads_read`): nothing is written, the creative stays `expired`
     * (a truthful, non-blank state), and the refusal is recorded on a failed `media_refresh` run.
     */
    public function test_a_refusal_leaves_the_creative_expired_and_records_why(): void
    {
        $this->configure('meta');
        [$selected] = $this->twoAccountsOneConnection('meta');

        $dead = 'https://scontent.xx.fbcdn.net/v/t45/old.jpg?oh=x&oe='.dechex(now()->subHour()->getTimestamp());
        $creative = $this->creativeUnder($selected, '120001', $dead);

        Http::fake(['*' => Http::response(['error' => [
            'message' => '(#200) Ad account owner has NOT grant ads_management or ads_read permission', 'code' => 200,
        ]], 403)]);

        Artisan::call('integrations:refresh-expired-media');

        $this->assertSame($dead, $creative->fresh()->asset_url);
        $this->assertSame('expired', app(CreativePresenter::class)->preview($creative->fresh())['state']);

        $run = IntegrationSyncRun::withoutGlobalScopes()->where('type', 'media_refresh')->firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('ads_read', (string) $run->error);
    }

    /** Meta re-signs by creative id — a creative whose ad is no longer listed still recovers. */
    public function test_meta_media_is_refreshed_by_creative_id(): void
    {
        $this->configure('meta');
        [$selected] = $this->twoAccountsOneConnection('meta');

        $dead = 'https://scontent.xx.fbcdn.net/v/t45/old.jpg?oh=x&oe='.dechex(now()->subHour()->getTimestamp());
        $creative = $this->creativeUnder($selected, '120002', $dead);

        $fresh = 'https://scontent.xx.fbcdn.net/v/t45/new.jpg?oh=y&oe='.dechex(now()->addDays(3)->getTimestamp());
        Http::fake(['*' => Http::response([
            '120002' => ['id' => '120002', 'name' => 'Still', 'object_type' => 'PHOTO', 'image_url' => $fresh],
        ], 200)]);

        Artisan::call('integrations:refresh-expired-media');

        $this->assertSame($fresh, $creative->fresh()->asset_url);
        $this->assertSame('available', app(CreativePresenter::class)->preview($creative->fresh())['state']);
        Http::assertSent(static fn ($r): bool => str_contains((string) $r->url(), 'ids=120002'));
    }

    private function configure(string $platform): void
    {
        foreach (PlatformCredentials::for($platform)->requires() as $key) {
            config()->set("ad_platforms.platforms.{$platform}.{$key}", "test-{$key}");
        }
    }

    /** @return array{0: ExternalAccount, 1: ExternalAccount} the selected account, then the unselected one */
    private function twoAccountsOneConnection(string $provider): array
    {
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: $provider,
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)),
            connectionName: $provider,
        );

        $make = fn (string $id): ExternalAccount => ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(), 'provider' => $provider,
            'account_type' => 'ad_account', 'external_id' => $id, 'name' => $id, 'status' => 'active', 'discovered_at' => Carbon::now(),
        ]);

        $selected = $make('act-selected');
        $unselected = $make('act-unselected');

        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->project->client_workspace_id,
            'project_id' => $this->project->id, 'external_account_id' => $selected->id, 'provider' => $provider,
            'purpose' => 'advertising', 'is_active' => true, 'campaign_management_enabled' => true,
        ]);

        return [$selected, $unselected];
    }

    private function creativeUnder(ExternalAccount $account, string $creativeId, string $link): ExternalCreative
    {
        $campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id,
            'provider' => $account->provider, 'external_id' => 'cmp-'.$creativeId, 'name' => 'C', 'status' => 'active',
        ]);

        return ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_campaign_id' => $campaign->id,
            'provider' => $account->provider, 'external_creative_id' => $creativeId, 'name' => 'Creative',
            'format' => 'image', 'asset_url' => $link, 'asset_expires_at' => AssetExpiry::fromUrl($link),
            'source_type' => 'api', 'is_demo' => false,
        ]);
    }
}
