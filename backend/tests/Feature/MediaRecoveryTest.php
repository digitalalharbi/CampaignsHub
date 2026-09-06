<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Actions\ImportExternalStructure;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativePresenter;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Support\AssetExpiry;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AD-MEDIA-RECOVERY-001 — RECOVERY, which is not the same claim as detection.
 *
 * ## What was already true, and why it was not enough
 *
 * `AssetExpiry::fromUrl` reads the expiry both providers state in the signed link — Meta's `oe` in
 * hex, Snapchat's `e` in decimal — so a stale asset now reaches the card as `expired` with a
 * sentence instead of as `available` with a URL that will be refused. That is DETECTION, and it is
 * where this requirement stopped: the product could say «this link has died».
 *
 * Saying it is not recovering from it. The requirement is that the asset comes BACK, and nothing
 * anywhere proved that a dead link is ever replaced by a live one — only that the upsert overwrites
 * the columns, which is an argument about code rather than a demonstration. A row could have gone
 * stale and stayed stale through every sweep and no test would have noticed.
 *
 * ## The chain held here, end to end
 *
 *   expired asset → the reader is told → a fresh sync arrives → the row carries the NEW link and a
 *   NEW expiry → the API says `available` again → the surface has a URL to draw.
 *
 * Driven through the real writer and read back over HTTP, because every link in that chain has been
 * the broken one at some point: the presenter, the importer's null-coalescing, and the API's own
 * payload. A model assertion would prove the column and none of the rest.
 */
final class MediaRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $operator;

    private ExternalAccount $account;

    private ExternalCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Recovery Co', 'slug' => 'rec-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());

        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->operator = User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@recovery.local',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);

        $ws = ClientWorkspace::create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'W', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $ws->getKey(),
            'name' => 'P', 'status' => 'active',
        ]);

        $credential = IntegrationCredential::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'provider' => 'snapchat',
            'credential_scope' => 'tenant', 'credential_type' => 'oauth',
            'encrypted_payload' => json_encode(['access_token' => 'tok']), 'status' => 'active',
        ]);
        $connection = ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'credential_id' => $credential->getKey(),
            'provider' => 'snapchat', 'connection_name' => 'snapchat — '.uniqid(),
            'scope' => 'project_only', 'status' => 'connected',
        ]);
        $this->account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'provider_connection_id' => $connection->getKey(),
            'provider' => 'snapchat', 'account_type' => 'ad_account',
            'external_id' => 'act-'.uniqid(), 'name' => 'An account', 'status' => 'active',
        ]);
        $this->campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'external_account_id' => $this->account->getKey(), 'provider' => 'snapchat',
            'external_id' => 'cmp-'.uniqid(), 'name' => 'A campaign', 'status' => 'active',
        ]);
    }

    /**
     * The whole chain, in one case, because the value is in it being unbroken.
     *
     * Splitting this into «the row updates» and «the API updates» would let either half pass while
     * the reader still saw a dead card, which is the exact failure mode this requirement is about.
     */
    public function test_an_expired_asset_recovers_to_a_usable_one_after_a_sync(): void
    {
        /* A Snapchat link that died an hour ago — `e` in decimal, exactly as the CDN writes it. */
        $dead = 'https://cf.snapchat.com/o/asset-1.jpg?e='.now()->subHour()->getTimestamp().'&s=sig';

        $this->sync($dead);

        $before = $this->previewFromTheApi();
        $this->assertSame('expired', $before['state'], 'A link that has died must not be served as available.');
        $this->assertNull($before['image_url'], 'A dead link must not reach the browser at all.');

        /* The next sweep resolves the asset again, and the platform signs a new grant for it. */
        $live = 'https://cf.snapchat.com/o/asset-1-v2.jpg?e='.now()->addWeek()->getTimestamp().'&s=sig2';

        $this->sync($live);

        $after = $this->previewFromTheApi();
        $this->assertSame('available', $after['state'], 'A resynced asset must come back, not stay dead.');
        $this->assertSame($live, $after['image_url'], 'The card must be handed the NEW link.');
        $this->assertNotNull($after['expires_at']);
        $this->assertTrue(
            now()->lt($after['expires_at']),
            'The renewed expiry must be in the future, or the row is stale the moment it is written.',
        );

        /* And the dead link is gone from the row entirely — nothing anywhere can still serve it. */
        $this->assertSame($live, $this->creative()->asset_url);
    }

    /**
     * The other half of recovery: a sync that resolves NOTHING must not resurrect the old link.
     *
     * The importer null-coalesces rather than omitting, so a provider that sends no asset clears the
     * column. Keeping the previous value would be the more «helpful» write and would hand the reader
     * a URL the platform has stopped standing behind — a broken rectangle presented as a picture,
     * which is the failure this requirement exists to prevent.
     */
    public function test_a_sync_that_resolves_nothing_does_not_bring_a_dead_link_back(): void
    {
        $this->sync('https://cf.snapchat.com/o/asset-2.jpg?e='.now()->addWeek()->getTimestamp().'&s=sig');
        $this->assertSame('available', $this->previewFromTheApi()['state']);

        $this->sync(null);

        $this->assertNull($this->creative()->asset_url, 'A cleared asset must not be kept alive by the previous sync.');
        $this->assertNotSame('available', $this->previewFromTheApi()['state']);
    }

    /** The presenter and the API must agree — a surface reading either one gets the same answer. */
    public function test_the_api_and_the_presenter_tell_the_same_story(): void
    {
        $this->sync('https://cf.snapchat.com/o/asset-3.jpg?e='.now()->subDay()->getTimestamp().'&s=sig');

        $this->assertSame(
            app(CreativePresenter::class)->preview($this->creative())['state'],
            $this->previewFromTheApi()['state'],
        );
    }

    /** The row as it stands now. */
    private function creative(): ExternalCreative
    {
        return ExternalCreative::withoutGlobalScopes()->where('external_creative_id', 'cr-recovery')->firstOrFail();
    }

    /**
     * One sweep of the real writer, with the asset the platform resolved this time.
     *
     * `asset_expires_at` is derived with `AssetExpiry::fromUrl`, which is what both connectors do —
     * so the expiry under test is the one the product would really store, not a value invented here.
     */
    private function sync(?string $asset): void
    {
        $creative = [
            'external_id' => 'cr-recovery',
            'name' => 'A story',
            'format' => 'image',
            'asset_url' => $asset,
            'asset_expires_at' => $asset === null ? null : AssetExpiry::fromUrl($asset)?->toIso8601String(),
        ];

        $counts = ['ad_sets' => 0, 'ads' => 0, 'creatives' => 0, 'skipped' => 0];

        $method = new \ReflectionMethod(ImportExternalStructure::class, 'creativeFor');
        $method->setAccessible(true);
        $method->invokeArgs(app(ImportExternalStructure::class), [$this->account, $this->campaign, $creative, 'ad-1', &$counts]);
    }

    /**
     * What the library endpoint actually hands the browser for this creative.
     *
     * @return array<string, mixed>
     */
    private function previewFromTheApi(): array
    {
        $body = $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->getKey()}/creatives")
            ->assertSuccessful()
            ->json();

        $row = collect($body['data']['creatives'] ?? [])->firstWhere('id', (string) $this->creative()->getKey());

        $this->assertNotNull($row, 'The creative did not appear in the library the owner opens.');

        return $row['preview'];
    }
}
