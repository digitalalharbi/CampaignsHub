<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * VIZ-REQUESTS-001 — «on track» was also counting the requests nobody ever promised anything about.
 *
 * The bucket was `sla_breached_at IS NULL AND (sla_due_at IS NULL OR sla_due_at > NOW() + 24h)`. The
 * middle clause is the problem: a request with NO due date at all — nothing was promised, because the
 * service type carries no SLA or one was never set — landed in «on track» beside requests that have a
 * deadline and are comfortably inside it.
 *
 * That is a promise being reported as kept where no promise exists, and it inflates the one figure a
 * team uses to decide whether they are coping. Worse, it inflates it in the direction of comfort: the
 * requests we know least about are counted as the ones going best.
 *
 * The buckets stay exhaustive — every request lands in exactly one, so they still sum to the total
 * and a composition drawn over them has a denominator that is genuinely the whole — and a fourth is
 * named for what it is.
 */
final class RequestSlaShapeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertingAcrossTenants();
        $this->seed(PermissionSeeder::class);
        $this->seed(RequestCatalogSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'sla-'.uniqid(), 'status' => 'active', 'is_default_portal' => true, 'portal_enabled' => true]);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'Owner', 'email' => 'sla-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant, Portal::Agency);
        $this->owner->assignRole($role);
    }

    public function test_a_request_nobody_promised_anything_about_is_not_on_track(): void
    {
        $this->request(dueAt: null);

        $sla = $this->breakdown()['sla'];

        $this->assertSame(0, $sla['on_track']);
        $this->assertSame(1, $sla['no_sla']);
    }

    public function test_a_request_with_a_comfortable_deadline_is_on_track(): void
    {
        $this->request(dueAt: Carbon::now()->addDays(5));

        $sla = $this->breakdown()['sla'];

        $this->assertSame(1, $sla['on_track']);
        $this->assertSame(0, $sla['no_sla']);
    }

    public function test_a_deadline_inside_a_day_is_due_soon_rather_than_on_track(): void
    {
        $this->request(dueAt: Carbon::now()->addHours(3));

        $this->assertSame(1, $this->breakdown()['sla']['due_soon']);
    }

    public function test_a_missed_promise_is_breached_whatever_its_due_date_says(): void
    {
        $this->request(dueAt: Carbon::now()->addDays(5), breachedAt: Carbon::now()->subDay());

        $sla = $this->breakdown()['sla'];

        $this->assertSame(1, $sla['breached']);
        $this->assertSame(0, $sla['on_track']);
    }

    /**
     * The four buckets partition the set — which is what lets a composition be drawn over them.
     *
     * A divided bar needs its parts to be parts OF something. If these overlapped, the shares would
     * add past 100%; if they left rows out, every share would be too large over a denominator that
     * nothing measured.
     */
    public function test_every_request_lands_in_exactly_one_bucket(): void
    {
        $this->request(dueAt: null);
        $this->request(dueAt: Carbon::now()->addDays(5));
        $this->request(dueAt: Carbon::now()->addHours(3));
        $this->request(dueAt: Carbon::now()->addDays(2), breachedAt: Carbon::now()->subHour());

        $body = $this->breakdown();
        $sla = $body['sla'];

        $this->assertSame(
            4,
            $sla['breached'] + $sla['due_soon'] + $sla['on_track'] + $sla['no_sla'],
        );
    }

    /** @return array<string,mixed> */
    private function breakdown(): array
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/app/requests?per_page=50')
            ->assertOk()
            ->json('meta.breakdown');
    }

    private function request(?Carbon $dueAt, ?Carbon $breachedAt = null): ExternalRequest
    {
        $type = \DB::table('request_types')->first();
        $status = \DB::table('request_statuses')->first();

        $req = new ExternalRequest;
        $req->forceFill([
            'tenant_id' => $this->tenant->id,
            'reference' => 'REQ-'.strtoupper(substr(uniqid(), -8)),
            'type_id' => $type->id,
            'status_id' => $status->id,
            'contact_name' => 'C',
            'contact_email' => 'c@co.test',
            'sla_due_at' => $dueAt,
            'sla_breached_at' => $breachedAt,
        ])->save();

        return $req;
    }
}
