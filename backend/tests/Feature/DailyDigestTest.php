<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Notifications\Services\DailyDigest;
use App\Domains\Notifications\Services\DigestScope;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\MembershipScope;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * MAIL-001 — what a digest may say, and to whom.
 *
 * An email is the one surface a recipient cannot be re-authorised on: once a client's spend is in
 * somebody's inbox, no permission change takes it back. So the scope tests here are not «does the
 * query filter» — they are «does every failure mode end in sending LESS».
 */
final class DailyDigestTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $alpha;

    private Project $beta;

    private ClientWorkspace $clientA;

    private ClientWorkspace $clientB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency-digest', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->clientA = ClientWorkspace::create(['name' => 'Client A', 'slug' => 'client-a', 'mode' => 'managed']);
        $this->clientB = ClientWorkspace::create(['name' => 'Client B', 'slug' => 'client-b', 'mode' => 'managed']);

        $this->alpha = Project::create(['client_workspace_id' => $this->clientA->id, 'name' => 'Alpha', 'status' => 'active']);
        $this->beta = Project::create(['client_workspace_id' => $this->clientB->id, 'name' => 'Beta', 'status' => 'active']);
    }

    private function user(string $email, array $permissions = []): User
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => $email, 'slug' => 'r-'.md5($email)]);
        if ($permissions !== []) {
            $role->givePermissionTo(...$permissions);
        }

        $user = User::create(['name' => $email, 'email' => $email, 'password' => 'secret123']);
        Membership::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'portal' => 'agency',
            'status' => 'active',
        ]);
        $user->assignRole($role);

        return $user->fresh();
    }

    private function scopeTo(User $user, string $type, string $id): void
    {
        $membership = Membership::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        MembershipScope::create([
            'membership_id' => $membership->id,
            'scope_type' => $type,
            'scope_id' => $id,
        ]);
    }

    private function metric(string $projectId, string $key, float $value, string $date, array $over = []): NormalizedMetric
    {
        return new NormalizedMetric(
            tenantId: (string) $this->tenant->id,
            projectId: $projectId,
            provider: $over['provider'] ?? 'meta',
            externalAccountId: Uuid::uuid5(Uuid::NAMESPACE_URL, 'acct')->toString(),
            externalCampaignId: Uuid::uuid5(Uuid::NAMESPACE_URL, $over['camp'] ?? 'c1')->toString(),
            metricKey: $key,
            metricDate: Carbon::parse($date),
            value: $value,
            unifiedCampaignId: $over['unified'] ?? null,
        );
    }

    // ---- scope -----------------------------------------------------------------------------------

    /**
     * The inversion this whole class exists to prevent: no scope must mean NOTHING.
     *
     * A user with a membership, no named scope and no `clients.view_all` is not an administrator —
     * they are somebody whose grant has not been made yet, or has just been revoked. Treating that
     * as «everything» would email them every client in the agency.
     */
    public function test_a_membership_with_no_scope_and_no_grant_receives_nothing(): void
    {
        $user = $this->user('nobody@agency.test');

        $ids = app(DigestScope::class)->projectIdsFor($user, (string) $this->tenant->id);

        $this->assertSame([], $ids, 'no scope must mean no projects, never all of them');
    }

    /** A named client scope is a ceiling: that client's projects, and nothing beside them. */
    public function test_a_client_scope_confines_the_digest_to_that_clients_projects(): void
    {
        $user = $this->user('scoped@agency.test');
        $this->scopeTo($user, MembershipScope::TYPE_CLIENT, (string) $this->clientA->id);

        $ids = app(DigestScope::class)->projectIdsFor($user, (string) $this->tenant->id);

        $this->assertSame([(string) $this->alpha->id], $ids);
    }

    /**
     * A named scope OUTRANKS the permission — the same precedence the request path uses (REG-001).
     *
     * The opposite order is the tempting one and it is wrong: an account manager confined to one
     * client would receive every client's figures the moment their role happened to include
     * `clients.view_all`, and the confinement would do nothing.
     */
    public function test_a_named_scope_beats_the_all_clients_permission(): void
    {
        $user = $this->user('both@agency.test', ['clients.view_all']);
        $this->scopeTo($user, MembershipScope::TYPE_CLIENT, (string) $this->clientA->id);

        $ids = app(DigestScope::class)->projectIdsFor($user, (string) $this->tenant->id);

        $this->assertSame([(string) $this->alpha->id], $ids, 'the narrower statement must win');
    }

    /** Unrestricted access is a positive grant, and it does reach everything. */
    public function test_the_all_clients_permission_reaches_every_project(): void
    {
        $user = $this->user('owner@agency.test', ['clients.view_all']);

        $ids = app(DigestScope::class)->projectIdsFor($user, (string) $this->tenant->id);

        sort($ids);
        $expected = [(string) $this->alpha->id, (string) $this->beta->id];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    /**
     * A preference is an INPUT, not an authorisation.
     *
     * `project_ids` is written by the user through their own settings. Naming a project they cannot
     * reach must add nothing — this is the request an attacker would actually make.
     */
    public function test_a_preference_can_narrow_the_scope_but_never_widen_it(): void
    {
        $user = $this->user('picky@agency.test');
        $this->scopeTo($user, MembershipScope::TYPE_CLIENT, (string) $this->clientA->id);

        DB::table('notification_preferences')->insert([
            'id' => (string) Uuid::uuid4(),
            'tenant_id' => (string) $this->tenant->id,
            'user_id' => $user->id,
            'channels' => json_encode(['email' => true]),
            'categories' => json_encode([]),
            // Beta is another client's project — asking for it must not deliver it.
            'project_ids' => json_encode([(string) $this->beta->id]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ids = app(DigestScope::class)->projectIdsFor($user, (string) $this->tenant->id);

        $this->assertSame([], $ids, 'a preference naming an unreachable project must deliver nothing');
    }

    /** A user with no membership in this tenant gets nothing, whatever else they hold. */
    public function test_a_revoked_membership_receives_nothing(): void
    {
        $user = $this->user('gone@agency.test', ['clients.view_all']);
        Membership::query()->where('user_id', $user->id)->delete();

        $this->assertSame([], app(DigestScope::class)->projectIdsFor($user->fresh(), (string) $this->tenant->id));
    }

    // ---- the digest itself -----------------------------------------------------------------------

    /**
     * A metric no platform reported is null in the payload — never a zero.
     *
     * «Reach 0» in somebody's inbox over their morning coffee is a false alarm they cannot check
     * without opening the product the digest exists to save them from opening.
     */
    public function test_an_unreported_metric_is_absent_rather_than_zero(): void
    {
        $yesterday = Carbon::today()->subDay();

        app(UpsertDailyMetrics::class)->handle([
            $this->metric((string) $this->alpha->id, 'spend', 500, $yesterday->toDateString()),
            $this->metric((string) $this->alpha->id, 'impressions', 20000, $yesterday->toDateString()),
        ]);

        $digest = app(DailyDigest::class)->build(
            $this->user('reader@agency.test', ['clients.view_all']),
            (string) $this->tenant->id,
            [(string) $this->alpha->id],
            $yesterday,
        );

        $reported = $digest['projects'][0]['reported'];

        $this->assertTrue($reported['spend']);
        $this->assertTrue($reported['impressions']);
        $this->assertFalse($reported['reach'], 'nothing sent reach — the email must not print a zero for it');
        $this->assertFalse($reported['leads']);
    }

    /**
     * The roll-up carries no blended cost per result, and that absence is deliberate.
     *
     * Across projects it would divide one client's money by another client's orders. A type-level
     * absence is the only kind a template cannot accidentally render.
     */
    public function test_the_account_roll_up_never_carries_a_blended_cost_per_result(): void
    {
        $yesterday = Carbon::today()->subDay();

        app(UpsertDailyMetrics::class)->handle([
            $this->metric((string) $this->alpha->id, 'spend', 900, $yesterday->toDateString()),
            $this->metric((string) $this->alpha->id, 'conversions', 9, $yesterday->toDateString()),
            // A distinct external campaign: the natural key is (account, campaign, metric, date,
            // window), so two projects sharing one campaign id would collide in a single upsert.
            $this->metric((string) $this->beta->id, 'spend', 100, $yesterday->toDateString(), ['camp' => 'beta-1']),
        ]);

        $digest = app(DailyDigest::class)->build(
            $this->user('roll@agency.test', ['clients.view_all']),
            (string) $this->tenant->id,
            [(string) $this->alpha->id, (string) $this->beta->id],
            $yesterday,
        );

        $this->assertEquals(1000.0, $digest['totals']['spend']);
        $this->assertArrayNotHasKey('cpa', $digest['totals']);
        $this->assertArrayNotHasKey('roas', $digest['totals']);
    }

    /**
     * Awareness spend never acquires a cost per order — the split is per marketing path.
     *
     * One awareness campaign and one sales campaign, on the same day, in the same project. A single
     * blended figure would divide the brand budget by the shop's orders; the path split gives the
     * conversion path its cost and leaves awareness without one, which is the truth.
     */
    public function test_awareness_spend_is_never_given_a_cost_per_order(): void
    {
        $yesterday = Carbon::today()->subDay();

        app(TenantContext::class)->setTenantId($this->tenant->id);
        $brand = UnifiedCampaign::create(['project_id' => $this->alpha->id, 'name' => 'Brand', 'objective' => 'awareness', 'status' => 'active']);
        $sales = UnifiedCampaign::create(['project_id' => $this->alpha->id, 'name' => 'Sales', 'objective' => 'sales', 'status' => 'active']);

        app(UpsertDailyMetrics::class)->handle([
            $this->metric((string) $this->alpha->id, 'spend', 4000, $yesterday->toDateString(), ['unified' => $brand->id, 'camp' => 'b1']),
            $this->metric((string) $this->alpha->id, 'spend', 1000, $yesterday->toDateString(), ['unified' => $sales->id, 'camp' => 's1']),
            $this->metric((string) $this->alpha->id, 'conversions', 20, $yesterday->toDateString(), ['unified' => $sales->id, 'camp' => 's1']),
        ]);

        $digest = app(DailyDigest::class)->build(
            $this->user('paths@agency.test', ['clients.view_all']),
            (string) $this->tenant->id,
            [(string) $this->alpha->id],
            $yesterday,
        );

        $paths = $digest['projects'][0]['paths'];

        // The sales path owns the orders, and its cost per order is its OWN money divided by them.
        $this->assertEquals(50.0, $paths['conversion']['cost_per_result'], 'sales: 1000 / 20');

        // The awareness path has none, and must not borrow the other path's denominator.
        $this->assertNull($paths['awareness']['cost_per_result']);
        $this->assertNull($paths['awareness']['roas']);
        $this->assertEquals(4000.0, $paths['awareness']['spend']);
    }

    /**
     * A day with no spend anywhere is not sent.
     *
     * «0 SAR across 6 projects», every morning, is how a daily email becomes a filter rule — and a
     * digest nobody opens cannot do the job this feature exists for. The reason is recorded rather
     * than the send being silently skipped.
     */
    public function test_a_day_with_no_activity_is_not_sendable_and_says_why(): void
    {
        $digest = app(DailyDigest::class)->build(
            $this->user('quiet@agency.test', ['clients.view_all']),
            (string) $this->tenant->id,
            [(string) $this->alpha->id],
            Carbon::today()->subDay(),
        );

        $this->assertFalse($digest['sendable']);
        $this->assertSame('no_activity', $digest['reason']);
    }

    /** No reachable project → no email at all, with the reason named. */
    public function test_an_empty_scope_produces_no_digest(): void
    {
        $digest = app(DailyDigest::class)->build(
            $this->user('empty@agency.test'),
            (string) $this->tenant->id,
            [],
            Carbon::today()->subDay(),
        );

        $this->assertFalse($digest['sendable']);
        $this->assertSame('no_projects_in_scope', $digest['reason']);
    }
}
