<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Quote;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Tenancy\Context\TenantContext;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoClientPortalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ONE client's portal, filled — and only that one (DEMO-PORTAL-001).
 *
 * `client@demo-portal.local` could sign in and then found eight empty sections. An empty list and a
 * broken query are indistinguishable from the outside, so a portal in that state cannot be reviewed
 * or demonstrated, and every acceptance test written against it passes vacuously.
 *
 * The claim under test is therefore not «rows exist». It is that the demo account, through the real
 * endpoints, reaches a coherent client world — requests at four stages, a quote awaiting its answer,
 * an unpaid invoice, an unread message, a file whose bytes are really there, campaigns on three
 * objectives and a report that was actually shared — and that ALL of it stops at the boundary of the
 * single client space that account is scoped to.
 */
final class DemoClientPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        // Seeded AS A LOCAL INSTALL: `DatabaseSeeder` gates the demo chain on `local|demo`, so seeding
        // from `testing` would assert against a world containing none of this and pass for the wrong
        // reason. The environment is what changes, once, here.
        app()->detectEnvironment(fn () => 'local');
        $this->seed(DatabaseSeeder::class);
        app()->detectEnvironment(fn () => 'testing');
        $this->assertingAcrossTenants();

        $this->client = User::where('email', DemoClientPortalSeeder::CONTACT_EMAIL)->firstOrFail();
    }

    /** @return array<string,mixed> the `data` block of a portal endpoint, as the demo client sees it */
    private function portal(string $section): array
    {
        return (array) $this->actingAs($this->client, 'sanctum')
            ->getJson('/api/v1/client/'.$section)
            ->assertOk()
            ->json('data');
    }

    /** The client space the demo account is scoped to. */
    private function space(): ClientWorkspace
    {
        return ClientWorkspace::query()->withoutGlobalScopes()
            ->where('slug', DemoClientPortalSeeder::WORKSPACE_SLUG)
            ->firstOrFail();
    }

    /** Every section answers with something. This is the defect, stated directly. */
    public function test_the_demo_client_portal_opens_with_something_in_every_section(): void
    {
        $sections = [
            'requests' => 'requests',
            'quotes' => 'quotes',
            'invoices' => 'invoices',
            'messages' => 'threads',
            'files' => 'files',
            'campaigns' => 'campaigns',
            'reports' => 'reports',
        ];

        foreach ($sections as $section => $key) {
            $rows = $this->portal($section)[$key] ?? [];

            $this->assertNotEmpty($rows, "/portal/{$section} is empty — the demo client has nothing to show there");
        }
    }

    /**
     * The three states that make the portal's own counters move.
     *
     * A demo where every quote is settled and every message is read renders the same page as a demo
     * with no data at all, and neither proves the counters work.
     */
    public function test_the_demo_carries_a_quote_awaiting_an_answer_an_unpaid_invoice_and_an_unread_message(): void
    {
        $quotes = collect($this->portal('quotes')['quotes']);
        $invoices = collect($this->portal('invoices')['invoices']);
        $threads = collect($this->portal('messages')['threads']);

        $this->assertSame(1, $quotes->where('status', 'sent')->count(), 'no quote is awaiting the client');
        $this->assertTrue($quotes->contains('status', 'approved') && $quotes->contains('status', 'rejected'));

        $this->assertTrue($invoices->contains('payment_status', 'paid'));
        $this->assertTrue($invoices->contains('payment_status', 'partially_paid'));
        $this->assertGreaterThan(0, $invoices->whereNotIn('payment_status', ['paid'])->count(), 'nothing is outstanding');

        $this->assertGreaterThan(0, $threads->sum('unread'), 'no conversation has an unread message for the client');
    }

    /**
     * A file the client can see is a file the client can open.
     *
     * A `request_files` row whose bytes were never written is a download button that 500s — a dead
     * control, which is the same failure as a placeholder wearing a different coat.
     */
    public function test_a_client_visible_file_downloads_its_real_bytes(): void
    {
        $file = collect($this->portal('files')['files'])->firstWhere('source', 'request');

        $this->assertNotNull($file, 'the demo client has no request attachment');

        $response = $this->actingAs($this->client, 'sanctum')
            ->get("/api/v1/client/requests/{$file['request_reference']}/files/{$file['id']}");

        $response->assertOk();
        $this->assertNotSame('', $response->streamedContent(), 'the attachment downloaded as an empty file');
    }

    /**
     * The portal names the client on every page, not only the one that happens to carry a slug.
     *
     * A contact who reaches ONE space is deliberately never asked to choose, so they live on the
     * spaceless `/client/*` routes — where branding used to read "no slug" as "no client" and answer
     * with the platform's own name. The portal greeted the client by name on its home page and called
     * itself «CampaignsHub» everywhere else.
     */
    public function test_the_portal_names_the_single_client_space_with_no_slug_in_the_url(): void
    {
        $branding = $this->portal('branding');

        $this->assertNotNull($branding['space'], 'branding forgot whose portal this is');
        $this->assertSame(DemoClientPortalSeeder::WORKSPACE_SLUG, $branding['space']['slug']);
    }

    /**
     * The awareness campaign reports reach, and no orders — negative, and the point of the seed.
     *
     * Writing a zero would be worse than writing nothing: a real 0 enters the sales figures as a
     * measured result, which is precisely the mixing REPORT-OBJECTIVE-14 forbids.
     */
    public function test_the_awareness_campaign_reports_no_orders_and_the_sales_campaign_does(): void
    {
        $campaigns = collect($this->portal('campaigns')['campaigns'])->keyBy('name');

        $awareness = $campaigns['Brand Awareness — Demo'] ?? null;
        $sales = $campaigns['National Day Sale — Demo'] ?? null;

        $this->assertNotNull($awareness);
        $this->assertNotNull($sales);

        $this->assertSame('awareness', $awareness['objective']);
        $this->assertGreaterThan(0, $awareness['metrics']['impressions'], 'an awareness campaign with no delivery');
        $this->assertEquals(0, $awareness['metrics']['conversions'], 'the awareness campaign claimed orders');

        $this->assertSame('sales', $sales['objective']);
        $this->assertGreaterThan(0, $sales['metrics']['conversions']);
        // Whole orders: «1,231.05 طلبًا» on a client's card reads as a rounding bug, not as precision.
        $this->assertSame(
            (float) (int) $sales['metrics']['conversions'],
            (float) $sales['metrics']['conversions'],
            'a client-facing order count is not a whole number',
        );
    }

    /**
     * The seeded world stops at ONE client space.
     *
     * The demo agency has six; the five others must stay empty, because «this account sees Acme and
     * not the other five» is the isolation being visible rather than merely asserted.
     */
    public function test_nothing_was_seeded_into_any_other_client_space(): void
    {
        $space = $this->space();

        $elsewhere = [
            'requests' => ExternalRequest::query()
                ->where('reference', 'like', DemoClientPortalSeeder::REQUEST_PREFIX.'%')
                ->where('client_id', '!=', $space->getKey())->count(),
            'quotes' => Quote::query()->withoutGlobalScopes()
                ->where('number', 'like', DemoClientPortalSeeder::QUOTE_PREFIX.'%')
                ->where('client_workspace_id', '!=', $space->getKey())->count(),
            'invoices' => Invoice::query()->withoutGlobalScopes()
                ->where('number', 'like', DemoClientPortalSeeder::INVOICE_PREFIX.'%')
                ->where('client_workspace_id', '!=', $space->getKey())->count(),
        ];

        foreach ($elsewhere as $what => $count) {
            $this->assertSame(0, $count, "the portal demo leaked {$what} into another client space");
        }
    }

    /** Re-running the seeder updates its own rows; it never lays down a second copy. */
    public function test_running_the_seeder_twice_does_not_duplicate_anything(): void
    {
        $count = fn (): array => [
            ExternalRequest::query()->where('reference', 'like', DemoClientPortalSeeder::REQUEST_PREFIX.'%')->count(),
            Quote::query()->withoutGlobalScopes()->where('number', 'like', DemoClientPortalSeeder::QUOTE_PREFIX.'%')->count(),
            Invoice::query()->withoutGlobalScopes()->where('number', 'like', DemoClientPortalSeeder::INVOICE_PREFIX.'%')->count(),
        ];

        $before = $count();

        app()->detectEnvironment(fn () => 'local');
        $this->seed(DemoClientPortalSeeder::class);
        app()->detectEnvironment(fn () => 'testing');

        $this->assertSame($before, $count());
    }

    /**
     * `demo:remove` clears the demo portal and leaves a real document standing.
     *
     * Deleting by client space would be the obvious implementation and the wrong one: a genuine quote
     * raised against the demo client would go with it. The prefix is the key precisely because a real
     * document cannot carry it.
     */
    public function test_demo_remove_clears_the_portal_demo_and_leaves_a_real_document_untouched(): void
    {
        $space = $this->space();

        app(TenantContext::class)->setTenantId((string) $space->tenant_id);
        $real = Quote::create([
            'tenant_id' => $space->tenant_id,
            'client_workspace_id' => $space->getKey(),
            'number' => 'Q-2026-0500',
            'currency' => 'SAR',
            'subtotal' => 1000,
            'tax' => 150,
            'discount' => 0,
            'total' => 1150,
            'status' => 'sent',
        ]);
        app(TenantContext::class)->forget();

        $this->artisan('demo:remove')->assertSuccessful();

        $this->assertSame(0, ExternalRequest::query()
            ->where('reference', 'like', DemoClientPortalSeeder::REQUEST_PREFIX.'%')->count());
        $this->assertSame(0, Quote::query()->withoutGlobalScopes()
            ->where('number', 'like', DemoClientPortalSeeder::QUOTE_PREFIX.'%')->count());
        $this->assertSame(0, Invoice::query()->withoutGlobalScopes()
            ->where('number', 'like', DemoClientPortalSeeder::INVOICE_PREFIX.'%')->count());

        $this->assertDatabaseHas('quotes', ['id' => $real->getKey()]);
    }

    /** Demo data is a development fixture, and the seeder says so by refusing. */
    public function test_the_seeder_refuses_outside_development(): void
    {
        ExternalRequest::query()->where('reference', 'like', DemoClientPortalSeeder::REQUEST_PREFIX.'%')->delete();

        // Invoked directly rather than through `$this->seed()`: `db:seed` asks for confirmation in
        // production, and the guard under test is the seeder's own, not the console's.
        app()->detectEnvironment(fn () => 'production');
        (new DemoClientPortalSeeder)->run();
        app()->detectEnvironment(fn () => 'testing');

        $this->assertSame(0, ExternalRequest::query()
            ->where('reference', 'like', DemoClientPortalSeeder::REQUEST_PREFIX.'%')->count());
    }
}
