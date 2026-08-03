<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Support\RequestLabels;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RequestCatalogSeeder;
use Database\Seeders\TaxonomyEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * REQ-LABELS-001 — a request's status and priority have NAMES, and the API serves both languages.
 *
 * The defect these lock down was visible on the busiest screen in the product: an Arabic inbox showing
 * «Under Review» and «medium». `request_statuses` has carried `name_ar` since it was created and every
 * one of four endpoints read `name_en`; priority had no label at all and the raw key went straight into
 * the table.
 *
 * Asserted on the API rather than on a helper, because the helper was never the problem — the reading
 * was. A test of `RequestLabels::priority()` alone would have passed against the broken product.
 */
final class RequestLabelLanguageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RequestCatalogSeeder::class);
        $this->seed(TaxonomyEngineSeeder::class);
        RequestLabels::forget();

        $this->tenant = $tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active', 'is_default_portal' => true, 'portal_enabled' => true]);
        app(TenantContext::class)->setTenantId($tenant->id);

        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'o@a.test', 'password' => 'secret123']);
        // `app/requests` is behind `portal:agency` — an App membership is refused there.
        $this->grantMembership($this->owner, $tenant, Portal::Agency);
        $this->owner->assignRole($role);

        ExternalRequest::create([
            'tenant_id' => $tenant->id,
            'reference' => 'REQ-LABEL-1',
            'module' => 'paid_media',
            'source' => 'public_portal',
            'type_id' => DB::table('request_types')->value('id'),
            'status_id' => DB::table('request_statuses')->where('key', 'under_review')->value('id'),
            'priority' => 'medium',
            'contact_name' => 'Guest',
            'contact_email' => 'g@example.test',
        ]);
    }

    public function test_the_inbox_serves_the_status_name_in_both_languages(): void
    {
        $res = $this->actingAs($this->owner)->getJson('/api/v1/app/requests');

        $res->assertOk();
        $row = $res->json('data.0');

        $this->assertSame('تحت المراجعة', $row['status_label'], 'the Arabic status name is not served');
        $this->assertSame('Under Review', $row['status_label_en']);
    }

    /**
     * Priority is the one that had NO label anywhere — «medium» was rendered raw into an Arabic table.
     */
    public function test_the_inbox_serves_the_priority_name_in_both_languages(): void
    {
        $res = $this->actingAs($this->owner)->getJson('/api/v1/app/requests');

        $res->assertOk();
        $row = $res->json('data.0');

        $this->assertSame('متوسطة', $row['priority_label'], 'priority is still served as a raw key');
        $this->assertSame('Medium', $row['priority_label_en']);
        // The key itself is still there — the board and the filters match on it, not on the label.
        $this->assertSame('medium', $row['priority']);
    }

    public function test_the_detail_carries_the_same_labels_as_the_list(): void
    {
        $id = ExternalRequest::first()->id;

        $res = $this->actingAs($this->owner)->getJson("/api/v1/app/requests/{$id}");

        $res->assertOk();
        $this->assertSame('تحت المراجعة', $res->json('data.status_label'));
        $this->assertSame('متوسطة', $res->json('data.priority_label'));
    }

    /**
     * REQ-SUMMARY-001 — the header counts describe the whole set, not the loaded page.
     *
     * Seeds more requests than fit on one page and asks for a small page: if the counts were still
     * derived from what was returned, `new` would equal the page size rather than the real total.
     */
    public function test_the_summary_counts_the_whole_set_not_the_page(): void
    {
        $newStatus = DB::table('request_statuses')->where('key', 'new')->value('id');
        for ($i = 0; $i < 7; $i++) {
            ExternalRequest::create([
                'tenant_id' => $this->tenant->id,
                'reference' => "REQ-SUM-{$i}",
                'module' => 'paid_media',
                'source' => 'public_portal',
                'type_id' => DB::table('request_types')->value('id'),
                'status_id' => $newStatus,
                'priority' => 'medium',
                'contact_name' => 'Guest',
                'contact_email' => "g{$i}@example.test",
            ]);
        }

        $res = $this->actingAs($this->owner)->getJson('/api/v1/app/requests?per_page=2');

        $res->assertOk();
        $this->assertCount(2, $res->json('data'), 'the page size was not honoured — the test proves nothing');
        $this->assertSame(8, $res->json('meta.summary.total'));
        $this->assertSame(7, $res->json('meta.summary.new'), 'the summary is still counting only the loaded page');
        $this->assertSame(1, $res->json('meta.summary.review'));
    }

    /** «Needs attention» is actionable: a breached SLA, or nobody assigned. */
    public function test_needs_attention_counts_unassigned_and_breached(): void
    {
        $res = $this->actingAs($this->owner)->getJson('/api/v1/app/requests');

        $res->assertOk();
        // The one seeded request has no assignee, so it needs somebody.
        $this->assertSame(1, $res->json('meta.summary.needs_attention'));
    }

    /** The summary follows the FILTER — a count that ignored it would be a different lie. */
    public function test_the_summary_respects_the_active_filter(): void
    {
        $res = $this->actingAs($this->owner)->getJson('/api/v1/app/requests?status=new');

        $res->assertOk();
        $this->assertSame(0, $res->json('meta.summary.review'), 'the summary ignored the status filter');
    }

    /**
     * An unknown priority shows as itself rather than as an empty cell.
     *
     * Ugly and visible gets fixed; blank looks like missing data and gets ignored.
     */
    public function test_an_untranslated_priority_falls_back_to_its_key(): void
    {
        $this->assertSame('urgent-ish', RequestLabels::priority('urgent-ish'));
        $this->assertSame('', RequestLabels::priority(null));
    }
}
