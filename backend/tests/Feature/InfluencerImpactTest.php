<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Influencers\Models\Influencer;
use App\Domains\Influencers\Models\InfluencerCollaboration;
use App\Domains\Influencers\Models\InfluencerDeliverable;
use App\Domains\Influencers\Models\InfluencerTrackingAsset;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * INFLUENCER-IMPACT-001 — each creator's figures with the evidence behind them, closed while INFL-OFF-001 holds.
 */
final class InfluencerImpactTest extends TestCase
{
    use RefreshDatabase;

    protected bool $selectsSeededAccounts = false;

    private Tenant $tenant;

    private User $manager;

    private ClientWorkspace $client;

    private Project $project;

    private ExternalAccount $store;

    private Influencer $layla;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'I', 'slug' => 'i-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);
        $this->manager = $this->staff('m@i.test', Permission::pluck('key')->all(), 'all');
        $this->client = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $this->client->id, 'name' => 'P', 'status' => 'active']);
        $this->store = $this->storeFor($this->project);
        $this->layla = Influencer::create(['name' => 'Layla', 'handle' => '@layla', 'primary_platform' => 'instagram', 'status' => 'active']);
    }

    public function test_it_answers_nothing_while_the_service_is_withdrawn(): void
    {
        $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/influencers/impact')->assertForbidden();
        $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/influencers/impact/export')->assertForbidden();
    }

    public function test_orders_are_the_stores_tied_by_coupon_and_clicks_prove_no_sale(): void
    {
        $this->withInfluencersEnabled();
        $collab = $this->collaboration($this->layla, 500);
        $this->asset($collab, 'link', 'LINK-1', 40);
        $this->asset($collab, 'discount_code', 'LAYLA10');
        $this->order('a', 300, ['coupon_code' => 'layla10', 'attribution_method' => 'influencer_coupon']);
        $this->order('b', 200, ['coupon_code' => 'LAYLA10', 'attribution_method' => 'influencer_coupon', 'status' => 'pending']);
        $this->order('c', 100, ['coupon_code' => 'LAYLA10', 'attribution_method' => 'click_id_platform_only', 'click_id_provider' => 'meta']);
        $this->order('d', 900, ['coupon_code' => 'LAYLA10', 'attribution_method' => 'influencer_coupon', 'cancelled_at' => '2026-10-06 10:00:00']);
        $this->order('e', 700, ['attribution_method' => 'none']);
        $this->order('f', 800, ['coupon_code' => 'LAYLA10', 'attribution_method' => 'influencer_coupon', 'placed_at' => '2026-08-01 10:00:00']);

        $row = $this->impact()['rows'][0];
        $this->assertSame('Layla', $row['name']);
        $this->assertSame(40, $row['clicks']);
        $this->assertSame('since_issued', $row['clicks_basis']);
        $this->assertSame(2, $row['orders']);
        $this->assertSame(1, $row['confirmed']);
        $this->assertSame(1, $row['overlap_orders'], 'carried the coupon, placed on a platform: stated, not credited');
        $this->assertEquals(500.0, $row['net_revenue']);
        $this->assertNull($row['visits'], 'no source measures it per creator');
        $this->assertNull($row['add_to_cart']);
        $this->assertNotSame('', $this->impact()['notes']['clicks']['en']);
    }

    public function test_cpa_roas_and_roi_appear_only_where_the_formula_is_valid(): void
    {
        $this->withInfluencersEnabled();
        $this->asset($this->collaboration($this->layla, 500), 'discount_code', 'LAYLA10');
        $this->order('a', 1000, ['coupon_code' => 'LAYLA10', 'attribution_method' => 'influencer_coupon']);
        $row = $this->impact()['rows'][0];
        $this->assertEquals(500.0, $row['cost']);
        $this->assertEquals(500.0, $row['cpa']);
        $this->assertEquals(2.0, $row['roas']);
        $this->assertEquals(1.0, $row['roi']);

        $noCost = Influencer::create(['name' => 'Noor', 'handle' => '@noor', 'status' => 'active']);
        $this->asset($this->collaboration($noCost, null), 'discount_code', 'NOOR10');
        $this->order('b', 1000, ['coupon_code' => 'NOOR10', 'attribution_method' => 'influencer_coupon']);
        $noor = collect($this->impact()['rows'])->firstWhere('name', 'Noor');
        $this->assertNull($noor['cpa']);
        $this->assertNull($noor['roas']);
        $this->assertNull($noor['roi']);

        $otherCurrency = Influencer::create(['name' => 'Sara', 'handle' => '@sara', 'status' => 'active']);
        $this->asset($this->collaboration($otherCurrency, 500, 'USD'), 'discount_code', 'SARA10');
        $this->order('c', 1000, ['coupon_code' => 'SARA10', 'attribution_method' => 'influencer_coupon']);
        $sara = collect($this->impact()['rows'])->firstWhere('name', 'Sara');
        $this->assertNull($sara['roas'], 'SAR over USD is not a ratio');
        $this->assertNull($sara['roi']);
        $this->assertNull($sara['cpa']);
    }

    public function test_a_reader_who_may_not_see_costs_gets_no_cost_and_nothing_derived_from_it(): void
    {
        $this->withInfluencersEnabled();
        $this->asset($this->collaboration($this->layla, 500), 'discount_code', 'LAYLA10');
        $this->order('a', 1000, ['coupon_code' => 'LAYLA10', 'attribution_method' => 'influencer_coupon']);
        $viewer = $this->staff('v@i.test', ['influencers.view', 'clients.view_all'], 'viewer');

        $out = $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/influencers/impact?from=2026-10-01&to=2026-10-31')->assertOk()->json('data');
        $this->assertFalse($out['can_see_costs']);
        foreach (['cost', 'cpa', 'roas', 'roi'] as $key) {
            $this->assertNull($out['rows'][0][$key], $key);
        }
        $this->assertSame([], $out['rows'][0]['cost_by_currency']);
    }

    public function test_it_ranks_pages_and_exports_on_the_server(): void
    {
        $this->withInfluencersEnabled();
        foreach (range(1, 12) as $i) {
            $c = Influencer::create(['name' => "Creator {$i}", 'handle' => "@c{$i}", 'status' => 'active']);
            $this->asset($this->collaboration($c, null), 'discount_code', "CODE{$i}");
            foreach (range(1, $i) as $n) {
                $this->order("c{$i}-{$n}", 10, ['coupon_code' => "CODE{$i}", 'attribution_method' => 'influencer_coupon']);
            }
        }
        $first = $this->impact('sort=orders&dir=desc&per_page=10');
        $this->assertSame('Creator 12', $first['rows'][0]['name']);
        $this->assertCount(10, $first['rows']);
        $this->assertSame(12, $first['total']);
        $this->assertSame('Creator 1', $this->impact('sort=orders&dir=asc')['rows'][0]['name'], 'ranked up and down');
        $this->assertCount(2, $this->impact('sort=orders&dir=desc&per_page=10&page=2')['rows']);
        $this->assertSame(1, $this->impact('q=Creator 7')['total']);
        $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/influencers/impact?sort=password')->assertStatus(422);

        $response = $this->actingAs($this->manager, 'sanctum')->get('/api/v1/influencers/impact/export?from=2026-10-01&to=2026-10-31&format=csv')->assertOk();
        $this->assertInstanceOf(StreamedResponse::class, $response->baseResponse);
        $this->assertSame(13, substr_count($response->streamedContent(), "\n"), 'every creator, not a page');
    }

    public function test_it_compares_creators_ugc_and_platforms_within_the_one_ledger(): void
    {
        $this->withInfluencersEnabled();
        $this->asset($this->collaboration($this->layla, null), 'discount_code', 'LAYLA10');
        $maker = Influencer::create(['name' => 'Maker', 'handle' => '@maker', 'status' => 'active']);
        $ugc = $this->collaboration($maker, null);
        InfluencerDeliverable::create(['collaboration_id' => $ugc->id, 'type' => 'ugc_video', 'status' => 'pending']);
        $this->asset($ugc, 'discount_code', 'MAKER10');
        $this->order('a', 10, ['coupon_code' => 'LAYLA10', 'attribution_method' => 'influencer_coupon']);
        $this->order('b', 10, ['coupon_code' => 'MAKER10', 'attribution_method' => 'influencer_coupon']);
        $this->order('c', 10, ['click_id_provider' => 'meta', 'attribution_method' => 'click_id_platform_only']);
        $this->order('d', 10, ['attribution_method' => 'none']);

        $groups = collect($this->impact()['summary']['comparison'])->keyBy('group');
        $this->assertSame(1, $groups['influencers']['orders']);
        $this->assertSame(1, $groups['ugc']['orders']);
        $this->assertSame(1, $groups['platforms']['orders']);
        $this->assertSame(1, $groups['other']['orders']);
    }

    public function test_a_reader_scoped_to_one_client_sees_only_that_clients_creators(): void
    {
        $this->withInfluencersEnabled();
        $this->asset($this->collaboration($this->layla, null), 'discount_code', 'LAYLA10');
        $otherClient = ClientWorkspace::create(['name' => 'D', 'slug' => 'd-'.uniqid(), 'mode' => 'managed']);
        $other = Influencer::create(['name' => 'Hidden', 'handle' => '@hidden', 'status' => 'active']);
        InfluencerCollaboration::create(['influencer_id' => $other->id, 'client_workspace_id' => $otherClient->id, 'title' => 'T', 'status' => 'active', 'currency' => 'SAR']);

        $scoped = User::create(['name' => 'S', 'email' => 's@i.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        app(GrantMembership::class)->execute(new MembershipGrant(user: $scoped, tenant: $this->tenant, portal: Portal::Influencers, role: 'member', clientScopeIds: [(string) $this->client->id]));
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'scoped', 'slug' => 'scoped']);
        $role->givePermissionTo('influencers.view');
        $scoped->assignRole($role);

        $names = array_column($this->actingAs($scoped, 'sanctum')->getJson('/api/v1/influencers/impact')->assertOk()->json('data.rows'), 'name');
        $this->assertContains('Layla', $names);
        $this->assertNotContains('Hidden', $names);
    }

    public function test_another_tenant_and_a_deselected_store_stay_out(): void
    {
        $this->withInfluencersEnabled();
        $this->asset($this->collaboration($this->layla, null), 'discount_code', 'LAYLA10');
        ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $this->store->id)->update(['is_active' => false]);
        $this->order('a', 100, ['coupon_code' => 'LAYLA10', 'attribution_method' => 'influencer_coupon']);
        $this->assertSame(0, $this->impact()['rows'][0]['orders'], 'a deselected store is not this project');

        $tenant = Tenant::create(['name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $tenant->id);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'A', 'slug' => 'a2']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $stranger = User::create(['name' => 'X', 'email' => 'x@i.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($stranger, $tenant, Portal::Influencers, 'member');
        $stranger->assignRole($role);

        $rows = $this->actingAs($stranger, 'sanctum')->getJson('/api/v1/influencers/impact')->assertOk()->json('data.rows');
        $this->assertSame([], $rows);
    }

    /** @return array<string, mixed> */
    private function impact(string $query = ''): array
    {
        return $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/influencers/impact?from=2026-10-01&to=2026-10-31&'.$query)->assertOk()->json('data');
    }

    /** @param list<string> $permissions */
    private function staff(string $email, array $permissions, string $slug): User
    {
        $user = User::create(['name' => 'Staff', 'email' => $email, 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($user, $this->tenant, Portal::Influencers, 'member');
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => $slug, 'slug' => $slug]);
        $role->givePermissionTo(...$permissions);
        $user->assignRole($role);

        return $user->refresh();
    }

    private function collaboration(Influencer $influencer, ?float $fee, string $currency = 'SAR'): InfluencerCollaboration
    {
        return InfluencerCollaboration::create([
            'influencer_id' => $influencer->id, 'client_workspace_id' => $this->client->id, 'title' => 'Launch',
            'status' => 'active', 'currency' => $currency, 'influencer_fee' => $fee,
        ]);
    }

    private function asset(InfluencerCollaboration $collaboration, string $kind, string $code, int $clicks = 0): void
    {
        InfluencerTrackingAsset::create([
            'collaboration_id' => $collaboration->id, 'kind' => $kind, 'code' => $code, 'clicks' => $clicks, 'is_active' => true,
            'destination_url' => $kind === 'link' ? 'https://shop.test/' : null, 'redemptions_source' => $kind === 'link' ? 'manual' : 'platform',
        ]);
    }

    private function storeFor(Project $project): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(tenantId: $this->tenant->id, provider: 'salla', tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'Salla');
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'provider_connection_id' => $connection->getKey(),
            'provider' => 'salla', 'account_type' => 'store', 'external_id' => 'shop', 'name' => 'Shop', 'status' => 'active', 'discovered_at' => Carbon::now(),
        ]);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => 'salla', 'purpose' => 'ecommerce', 'is_active' => true,
        ]);

        return $account;
    }

    /** @param array<string, mixed> $over */
    private function order(string $reference, float $total, array $over = []): CommerceOrder
    {
        return CommerceOrder::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $this->store->id, 'provider' => 'salla',
            'external_id' => 'ext-'.$reference, 'reference' => $reference, 'status' => 'completed', 'placed_at' => '2026-10-05 10:00:00', 'currency' => 'SAR', 'total' => $total, ...$over,
        ]);
    }
}
