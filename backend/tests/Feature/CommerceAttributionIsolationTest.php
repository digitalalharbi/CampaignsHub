<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Services\OrderAttributionResolver;
use App\Domains\Influencers\Models\Influencer;
use App\Domains\Influencers\Models\InfluencerCollaboration;
use App\Domains\Influencers\Models\InfluencerTrackingAsset;
use App\Domains\Influencers\Services\InfluencerAttribution;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * SEC-3 — a creator's discount code attributes only the orders of the project (client) it was issued for,
 * and a code is unique where it is read: per tenant for a discount code, globally for a served link.
 */
final class CommerceAttributionIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $pa;

    private Project $pb;

    private UnifiedCampaign $campaignA;

    private UnifiedCampaign $campaignB;

    private ExternalAccount $storeA;

    private ExternalAccount $storeB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withInfluencersEnabled();
        $this->tenant = Tenant::create(['name' => 'Ag', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant($this->tenant->id);

        [$this->pa, $this->campaignA] = $this->projectWithCampaign('A');
        [$this->pb, $this->campaignB] = $this->projectWithCampaign('B');
        $this->storeA = $this->store('a');
        $this->storeB = $this->store('b');
    }

    public function test_a_code_issued_for_client_b_does_not_attribute_client_a_orders_nor_leak_a_count(): void
    {
        $codeOfB = $this->asset('SAVE10', $this->campaignB);
        $resolver = app(OrderAttributionResolver::class);

        $order = $resolver->apply($this->order($this->pa, $this->storeA, 'SAVE10'), collect());

        $this->assertNotSame('influencer_coupon', $order->attribution_method);
        $this->assertNull($order->unified_campaign_id, 'client B\'s campaign is never written on client A\'s order');
        $this->assertSame(0, $codeOfB->refresh()->redemptions, 'and A\'s order is not B\'s redemption');
    }

    public function test_the_same_common_code_in_two_tenants_attributes_each_to_its_own_campaign_and_counts_its_own(): void
    {
        $a = $this->asset('SAVE10', $this->campaignA);
        $resolver = app(OrderAttributionResolver::class);

        $other = Tenant::create(['name' => 'Other', 'slug' => 'o-'.uniqid(), 'status' => 'active']);
        $oc = ClientWorkspace::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'name' => 'OC', 'slug' => 'oc-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $op = Project::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'client_workspace_id' => $oc->id, 'name' => 'OP', 'status' => 'active']);
        $ocamp = UnifiedCampaign::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'project_id' => $op->id, 'name' => 'OCamp', 'objective' => 'sales', 'status' => 'active', 'total_budget' => 1, 'budget_currency' => 'SAR']);
        $ocollab = $this->collaborationFor($other, $ocamp);
        $b = InfluencerTrackingAsset::withoutGlobalScopes()->create([
            'tenant_id' => $other->id, 'collaboration_id' => $ocollab->id, 'kind' => 'discount_code', 'code' => 'SAVE10',
            'is_active' => true, 'redemptions_source' => 'awaiting_credentials',
        ]);

        $resolver->apply($this->order($this->pa, $this->storeA, 'SAVE10'), collect());
        $resolver->apply($this->order($this->pa, $this->storeA, 'SAVE10'), collect());

        app(TenantContext::class)->setTenantId($other->id);
        $ob = CommerceOrder::withoutGlobalScopes()->create([
            'tenant_id' => $other->id, 'project_id' => $op->id, 'external_account_id' => $this->storeB->getKey(),
            'provider' => 'salla', 'external_id' => 'o-'.uniqid(), 'status' => 'completed', 'placed_at' => Carbon::today()->subDay(),
            'currency' => 'SAR', 'total' => 250, 'coupon_code' => 'SAVE10',
        ]);
        $ob = $resolver->apply($ob, collect());
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->assertSame((string) $ocamp->id, (string) $ob->unified_campaign_id);
        $this->assertSame(2, $a->refresh()->redemptions);
        $this->assertSame(1, InfluencerTrackingAsset::withoutGlobalScopes()->findOrFail($b->id)->redemptions);
    }

    public function test_a_code_with_neither_a_campaign_nor_a_client_cannot_prove_which_project_it_is_for(): void
    {
        $this->asset('LOOSE5', null);

        $order = app(OrderAttributionResolver::class)->apply($this->order($this->pa, $this->storeA, 'LOOSE5'), collect());

        $this->assertNotSame('influencer_coupon', $order->attribution_method);
    }

    public function test_a_code_bound_only_to_a_client_attributes_that_clients_orders(): void
    {
        $asset = $this->asset('CLIENTONLY', null, $this->pa->client_workspace_id);

        $order = app(OrderAttributionResolver::class)->apply($this->order($this->pa, $this->storeA, 'CLIENTONLY'), collect());
        $this->assertSame('influencer_coupon', $order->attribution_method);
        $this->assertSame(1, $asset->refresh()->redemptions);

        $other = app(OrderAttributionResolver::class)->apply($this->order($this->pb, $this->storeB, 'CLIENTONLY'), collect());
        $this->assertNotSame('influencer_coupon', $other->attribution_method);
        $this->assertSame(1, $asset->refresh()->redemptions);
    }

    public function test_a_collaboration_cannot_name_a_campaign_of_another_tenant(): void
    {
        $this->seed(PermissionSeeder::class);
        $manager = $this->manager();
        $influencer = Influencer::create(['name' => 'L', 'handle' => '@l', 'primary_platform' => 'instagram', 'followers' => 10, 'tier' => 'mid', 'status' => 'active']);
        $other = Tenant::create(['name' => 'Other', 'slug' => 'o-'.uniqid(), 'status' => 'active']);
        $oc = ClientWorkspace::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'name' => 'OC', 'slug' => 'oc-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $op = Project::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'client_workspace_id' => $oc->id, 'name' => 'OP', 'status' => 'active']);
        $theirs = UnifiedCampaign::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'project_id' => $op->id, 'name' => 'T', 'objective' => 'sales', 'status' => 'active', 'total_budget' => 1, 'budget_currency' => 'SAR']);

        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/influencers/collaborations', [
            'influencer_id' => $influencer->id, 'title' => 'x', 'campaign_id' => $theirs->id,
        ])->assertStatus(422);

        $this->assertSame(0, InfluencerCollaboration::query()->count());
    }

    public function test_a_collaboration_cannot_pair_a_client_with_another_clients_campaign(): void
    {
        $this->seed(PermissionSeeder::class);
        $manager = $this->manager();
        $influencer = Influencer::create(['name' => 'L', 'handle' => '@l', 'primary_platform' => 'instagram', 'followers' => 10, 'tier' => 'mid', 'status' => 'active']);

        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/influencers/collaborations', [
            'influencer_id' => $influencer->id, 'title' => 'x',
            'client_workspace_id' => $this->pa->client_workspace_id, 'campaign_id' => $this->campaignB->id,
        ])->assertStatus(422);

        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/influencers/collaborations', [
            'influencer_id' => $influencer->id, 'title' => 'x',
            'client_workspace_id' => $this->pa->client_workspace_id, 'campaign_id' => $this->campaignA->id,
        ])->assertCreated();
    }

    // ── Code uniqueness ────────────────────────────────────────────────────────────────────────

    public function test_another_tenant_holding_a_discount_code_does_not_block_or_reveal_it(): void
    {
        $this->asset('SAVE10', $this->campaignA);

        $other = Tenant::create(['name' => 'Other', 'slug' => 'o-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($other->id);
        $collab = $this->collaborationFor($other);

        $asset = app(InfluencerAttribution::class)->issue($collab, 'discount_code', ['code' => 'SAVE10'], null);

        $this->assertSame('SAVE10', $asset->code);
    }

    public function test_the_same_discount_code_twice_in_one_tenant_is_still_refused(): void
    {
        $this->asset('SAVE10', $this->campaignA);
        $collab = $this->collaborationFor($this->tenant);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already in use');
        app(InfluencerAttribution::class)->issue($collab, 'discount_code', ['code' => 'save10'], null);
    }

    public function test_a_served_link_code_stays_globally_unique(): void
    {
        $this->asset('SPRING', $this->campaignA, null, 'link');

        $other = Tenant::create(['name' => 'Other', 'slug' => 'o-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($other->id);
        $collab = $this->collaborationFor($other);

        $this->expectException(RuntimeException::class);
        app(InfluencerAttribution::class)->issue($collab, 'link', ['code' => 'SPRING', 'destination_url' => 'https://x.example'], null);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────────────────────

    /** @return array{0: Project, 1: UnifiedCampaign} */
    private function projectWithCampaign(string $name): array
    {
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'slug' => 'w-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $p = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => $name, 'status' => 'active']);
        $c = UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $p->id, 'name' => 'Camp '.$name, 'objective' => 'sales', 'status' => 'active', 'total_budget' => 1000, 'budget_currency' => 'SAR']);

        return [$p, $c];
    }

    private function store(string $id): ExternalAccount
    {
        $credential = new IntegrationCredential(['provider' => 'salla', 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('token');
        $credential->save();
        $connection = ProviderConnection::create(['tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => 'salla', 'connection_name' => 'salla-'.$id, 'scope' => 'project_only', 'status' => 'connected']);

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => 'salla',
            'account_type' => 'store', 'external_id' => 'store-'.$id, 'name' => 'Shop '.$id, 'status' => 'active', 'discovered_at' => now(),
        ]);
    }

    private function collaborationFor(Tenant $tenant, ?UnifiedCampaign $campaign = null, ?string $client = null): InfluencerCollaboration
    {
        $influencer = Influencer::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Sara', 'handle' => 'sara-'.uniqid(), 'primary_platform' => 'snapchat', 'status' => 'active']);

        return InfluencerCollaboration::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'influencer_id' => $influencer->id, 'campaign_id' => $campaign?->id, 'client_workspace_id' => $client,
            'title' => 'Push', 'status' => 'active', 'currency' => 'SAR', 'agreed_fee' => '5000.00',
        ]);
    }

    private function asset(string $code, ?UnifiedCampaign $campaign, ?string $client = null, string $kind = 'discount_code'): InfluencerTrackingAsset
    {
        $collab = $this->collaborationFor($this->tenant, $campaign, $client);

        return InfluencerTrackingAsset::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'collaboration_id' => $collab->id, 'kind' => $kind, 'code' => $code,
            'destination_url' => $kind === 'link' ? 'https://shop.example/?ref='.$code : null, 'is_active' => true,
            'redemptions_source' => 'awaiting_credentials',
        ]);
    }

    private function order(Project $project, ExternalAccount $store, string $coupon): CommerceOrder
    {
        return CommerceOrder::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $store->getKey(),
            'provider' => 'salla', 'external_id' => 'o-'.uniqid(), 'status' => 'completed', 'placed_at' => Carbon::today()->subDay(),
            'currency' => 'SAR', 'total' => 250, 'coupon_code' => $coupon,
        ]);
    }

    private function manager(): User
    {
        $user = User::create(['name' => 'M', 'email' => 'm@ag.test', 'password' => 'secret1234', 'email_verified_at' => now()]);
        $this->grantMembership($user, $this->tenant, Portal::Influencers, 'member');
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'all', 'slug' => 'all-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user->assignRole($role);

        return $user->refresh();
    }
}
