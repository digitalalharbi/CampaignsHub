<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ShortLinks\Models\ShortLink;
use App\Domains\ShortLinks\Models\ShortLinkHop;
use App\Domains\ShortLinks\Services\ShortLinkHops;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * SHORT-LINK-HOPS-001 — a counter says how many; these rows say when, and say where they begin.
 *
 * `short_links.clicks` is one integer that only goes up. It cannot distinguish a link followed
 * three hundred times last spring from one followed three hundred times this week, which is the
 * distinction an operator needs from a link they put in a live campaign.
 *
 * The thing most worth asserting is not the curve — it is the BOUNDARY. Every link that existed
 * before this table has a real total and no history, and the easy failure is to serve a zero for
 * each unmeasured day and let a reader take a flat line for a quiet month. That substitution is the
 * one this product refuses everywhere else, and these tests hold it here.
 */
final class ShortLinkHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'H', 'slug' => 'h-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->getKey());

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->operator = User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@h.test',
            'password' => Hash::make('secret1234'), 'email_verified_at' => now(),
        ]);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);
    }

    private function link(string $slug = 'abc1234'): ShortLink
    {
        return ShortLink::create([
            'tenant_id' => $this->tenant->id,
            'slug' => $slug,
            'kind' => ShortLink::KIND_LINK,
            'destination' => 'https://example.test/offer',
            'source_value' => 'https://example.test/offer',
            'clicks' => 0,
            'is_active' => true,
            'created_by' => $this->operator->id,
        ]);
    }

    public function test_following_a_link_records_the_moment_as_well_as_the_count(): void
    {
        $link = $this->link('hop0001');

        app(ShortLinkHops::class)->resolveAndCount('hop0001');

        $this->assertSame(1, (int) $link->refresh()->clicks, 'the counter still counts');
        $this->assertSame(
            1,
            ShortLinkHop::query()->where('short_link_id', $link->getKey())->count(),
            'and the moment is written down beside it',
        );
    }

    /** The hop carries the LINK's tenant: a stranger following a link brings no tenant of their own. */
    public function test_a_recorded_follow_belongs_to_the_links_tenant(): void
    {
        $link = $this->link('hop0002');

        app(ShortLinkHops::class)->resolveAndCount('hop0002');

        $hop = ShortLinkHop::query()->where('short_link_id', $link->getKey())->firstOrFail();

        $this->assertSame((string) $this->tenant->getKey(), (string) $hop->tenant_id);
    }

    /** A disabled link neither counts nor records — it does not resolve at all. */
    public function test_a_disabled_link_records_nothing(): void
    {
        $link = $this->link('hop0003');
        $link->update(['is_active' => false]);

        $this->assertNull(app(ShortLinkHops::class)->resolveAndCount('hop0003'));
        $this->assertSame(0, ShortLinkHop::query()->count());
    }

    /**
     * The series starts where RECORDING starts, not where the window starts.
     *
     * This is the whole point of the unit. A link followed 85 times before recording began must not
     * produce 85 days of zeroes: those days were not quiet, they were unmeasured, and a line drawn
     * through them is a claim nobody can support.
     */
    public function test_days_before_recording_began_are_absent_rather_than_zero(): void
    {
        $link = $this->link('hop0004');
        /* A real total from a life nobody timed. */
        $link->update(['clicks' => 85]);

        /* Recording begins today, with a single follow. */
        $today = Carbon::now()->startOfDay();
        ShortLinkHop::create([
            'tenant_id' => $this->tenant->id,
            'short_link_id' => $link->getKey(),
            'occurred_at' => $today->copy()->addHours(9),
        ]);

        $body = $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/short-links/'.$link->getKey().'/history?from='
                .$today->copy()->subDays(10)->toDateString().'&to='.$today->toDateString())
            ->assertOk()
            ->json();

        $days = array_column($body['data']['series'], 'day');

        $this->assertSame([$today->toDateString()], $days, 'only the recorded day is reported');
        $this->assertSame(85, $body['data']['clicks_all_time'], 'the counter is unchanged and still shown');
        $this->assertSame(1, $body['data']['recorded_in_window'], 'and only one follow was ever placed in time');
        $this->assertNotNull($body['meta']['recording_since']);
    }

    /**
     * A quiet day INSIDE the recorded period is a zero, and should be.
     *
     * The distinction this test exists for: unmeasured is absent, measured-and-empty is zero. Both
     * states are real and they are not the same state.
     */
    public function test_a_quiet_day_inside_the_recorded_period_is_a_zero(): void
    {
        $link = $this->link('hop0005');
        $today = Carbon::now()->startOfDay();

        foreach ([2, 0] as $daysAgo) {
            ShortLinkHop::create([
                'tenant_id' => $this->tenant->id,
                'short_link_id' => $link->getKey(),
                'occurred_at' => $today->copy()->subDays($daysAgo)->addHours(10),
            ]);
        }

        $series = $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/short-links/'.$link->getKey().'/history?from='
                .$today->copy()->subDays(2)->toDateString().'&to='.$today->toDateString())
            ->assertOk()
            ->json('data.series');

        $this->assertCount(3, $series);
        $this->assertSame(1, $series[0]['follows']);
        $this->assertSame(0, $series[1]['follows'], 'the middle day was measured and nobody came');
        $this->assertSame(1, $series[2]['follows']);
    }

    /** The list states the counter and the recorded total separately, never one as the other. */
    public function test_the_list_separates_the_counter_from_what_was_recorded(): void
    {
        $link = $this->link('hop0006');
        $link->update(['clicks' => 40]);

        ShortLinkHop::create([
            'tenant_id' => $this->tenant->id,
            'short_link_id' => $link->getKey(),
            'occurred_at' => Carbon::now(),
        ]);

        $body = $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/short-links')->assertOk()->json();

        $row = collect($body['data'])->firstWhere('id', (string) $link->getKey());

        $this->assertSame(40, $row['clicks']);
        $this->assertSame(1, $row['recorded_follows']);
        $this->assertNotNull($body['meta']['recording_since']);
    }

    /** Nothing recorded anywhere: the boundary is null and the surfaces are told so. */
    public function test_an_installation_that_has_recorded_nothing_says_so(): void
    {
        $this->link('hop0007')->update(['clicks' => 12]);

        $body = $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/short-links')->assertOk()->json();

        $this->assertNull($body['meta']['recording_since']);
        $this->assertSame(0, $body['data'][0]['recorded_follows']);
        $this->assertSame(12, $body['data'][0]['clicks'], 'the counter is still the truth about totals');
    }

    /**
     * The list carries the workspace's own curve, and it stops at the recording boundary too.
     *
     * The page draws its trend from this, so the same rule has to hold here as in the per-link
     * endpoint — otherwise the headline chart would be the one place a flat line gets invented.
     */
    public function test_the_list_carries_a_curve_that_starts_where_recording_did(): void
    {
        $link = $this->link('hop0009');
        $link->update(['clicks' => 300]);

        $today = Carbon::now()->startOfDay();

        foreach ([1, 1, 0] as $daysAgo) {
            ShortLinkHop::create([
                'tenant_id' => $this->tenant->id,
                'short_link_id' => $link->getKey(),
                'occurred_at' => $today->copy()->subDays($daysAgo)->addHours(8),
            ]);
        }

        $meta = $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/short-links')->assertOk()->json('meta');

        $days = array_column($meta['daily'], 'day');

        $this->assertSame(
            [$today->copy()->subDay()->toDateString(), $today->toDateString()],
            $days,
            'the curve covers the recorded period only, not the whole 30-day window',
        );
        $this->assertSame(2, $meta['daily'][0]['follows']);
        $this->assertSame(1, $meta['daily'][1]['follows']);
    }

    /** Nothing recorded means NO curve — not a flat line at zero across a month nobody measured. */
    public function test_an_installation_with_no_recordings_draws_no_curve(): void
    {
        $this->link('hop0010')->update(['clicks' => 77]);

        $meta = $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/short-links')->assertOk()->json('meta');

        $this->assertSame([], $meta['daily']);
        $this->assertNull($meta['recording_since']);
    }

    /** A workspace sees only its OWN follows in that curve. */
    public function test_the_curve_does_not_count_another_tenants_follows(): void
    {
        $mine = $this->link('hop0011');
        $other = Tenant::create(['name' => 'O2', 'slug' => 'o2-'.uniqid(), 'status' => 'active']);
        $theirs = ShortLink::withoutGlobalScopes()->create([
            'tenant_id' => $other->id, 'slug' => 'hop0012', 'kind' => ShortLink::KIND_LINK,
            'destination' => 'https://example.test/x', 'clicks' => 0, 'is_active' => true,
            'created_by' => $this->operator->id,
        ]);

        $at = Carbon::now();

        ShortLinkHop::create(['tenant_id' => $this->tenant->id, 'short_link_id' => $mine->getKey(), 'occurred_at' => $at]);
        ShortLinkHop::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'short_link_id' => $theirs->getKey(), 'occurred_at' => $at]);

        $meta = $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/short-links')->assertOk()->json('meta');

        $this->assertSame(1, $meta['daily'][0]['follows'], 'the other workspace\'s follow is not in this curve');
    }

    /** Another tenant's link is a 404 here, as everywhere: the id is never confirmed by a refusal. */
    public function test_another_tenants_link_has_no_history_here(): void
    {
        $other = Tenant::create(['name' => 'O', 'slug' => 'o-'.uniqid(), 'status' => 'active']);
        $theirs = ShortLink::withoutGlobalScopes()->create([
            'tenant_id' => $other->id,
            'slug' => 'hop0008',
            'kind' => ShortLink::KIND_LINK,
            'destination' => 'https://example.test/other',
            'clicks' => 0,
            'is_active' => true,
            'created_by' => $this->operator->id,
        ]);

        $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/short-links/'.$theirs->getKey().'/history')
            ->assertNotFound();
    }
}
