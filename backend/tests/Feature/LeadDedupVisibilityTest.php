<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\CRM\Actions\LinkDuplicateLead;
use App\Domains\CRM\Models\Lead;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LEAD-DEDUP-001 reaching a screen.
 *
 * `canonical_lead_id` and `duplicate_reason` had been written since the dedup work shipped and no
 * surface had ever read them. The whole design is «recorded twice, counted once» — and «counted
 * once» is a claim a reader has to be able to SEE, or it is only a claim about the database. Until
 * now, every repeat submission looked like a separate person to anybody actually using the product.
 *
 * Three properties, and the second is the one that keeps the number honest:
 *
 *   1. A duplicate is VISIBLE as a duplicate, carrying the id it duplicates and the reason.
 *   2. «Received» and «unique» are both reported, always, over the SAME scope as the list beside
 *      them. One «total» would have to be one of the two, and whichever it was would be wrong for
 *      the other question, with nothing on screen to say which.
 *   3. `ambiguous` is not a kind of duplicate. It is a refusal to guess — an identity whose email
 *      says one person and whose phone says another — and it links to nothing. Presenting it as a
 *      resolved match would be the product asserting something it deliberately declined to decide.
 */
final class LeadDedupVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private string $projectId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'ldv-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->projectId = (string) Str::uuid();

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->user = User::create(['name' => 'O', 'email' => 'ldv-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->user, $this->tenant);
        $this->user->assignRole($role);
    }

    public function test_a_duplicate_arrives_at_the_client_as_a_duplicate(): void
    {
        $first = $this->lead('نورة', email: 'noura@example.com');
        $second = $this->lead('نورة', email: 'noura@example.com', provider: 'snapchat');
        app(LinkDuplicateLead::class)->handle($second);

        $rows = collect($this->list()['data'])->keyBy('id');

        $this->assertSame((string) $first->getKey(), $rows[(string) $second->getKey()]['canonical_lead_id']);
        $this->assertSame('email', $rows[(string) $second->getKey()]['duplicate_reason']);

        // The canonical is not a duplicate of anything, and says how many it absorbed.
        $this->assertNull($rows[(string) $first->getKey()]['canonical_lead_id']);
        $this->assertSame(1, $rows[(string) $first->getKey()]['duplicate_count']);
    }

    /** Both rows survive and both are listed. Nothing is hidden by default. */
    public function test_both_arrivals_are_listed(): void
    {
        $this->lead('نورة', email: 'noura@example.com');
        app(LinkDuplicateLead::class)->handle($this->lead('نورة', email: 'noura@example.com'));

        $body = $this->list();

        $this->assertCount(2, $body['data']);
        $this->assertSame(['received' => 2, 'unique' => 1], $body['meta']['counts']);
    }

    /** `unique=1` narrows the LIST, and leaves both counts intact — the reader can always see both. */
    public function test_the_unique_view_narrows_the_list_without_hiding_the_other_number(): void
    {
        $first = $this->lead('نورة', email: 'noura@example.com');
        app(LinkDuplicateLead::class)->handle($this->lead('نورة', email: 'noura@example.com'));

        $body = $this->list(['unique' => 1]);

        $this->assertSame([(string) $first->getKey()], array_column($body['data'], 'id'));
        $this->assertSame(['received' => 2, 'unique' => 1], $body['meta']['counts']);
    }

    /**
     * The counts describe the SAME scope as the list beside them.
     *
     * Counting over an unfiltered table would report figures that disagree with the rows on screen
     * the moment a search or a status is applied — two numbers about two different questions,
     * presented as though they were about one.
     */
    public function test_the_counts_follow_the_filters(): void
    {
        $noura = $this->lead('نورة', email: 'noura@example.com');
        app(LinkDuplicateLead::class)->handle($this->lead('نورة', email: 'noura@example.com'));
        $this->lead('محمد', email: 'mohammed@example.com');

        $body = $this->list(['search' => 'noura']);

        $this->assertSame(['received' => 2, 'unique' => 1], $body['meta']['counts']);
        $this->assertContains((string) $noura->getKey(), array_column($body['data'], 'id'));

        $all = $this->list();
        $this->assertSame(['received' => 3, 'unique' => 2], $all['meta']['counts']);
    }

    /**
     * An ambiguous identity is NOT counted as a duplicate.
     *
     * Its email matches one person and its phone another, so it links to neither. Counting it as
     * unique is the honest answer — a false duplicate deletes a real person from a sales list, while
     * over-counting by one is correctable — and the reason travels to the client so the row can say
     * what happened rather than looking like an ordinary lead.
     */
    public function test_an_ambiguous_identity_is_unique_and_says_why(): void
    {
        $this->lead('نورة', email: 'noura@example.com', phone: '0500000001');
        $this->lead('محمد', email: 'mohammed@example.com', phone: '0500000002');

        $mixed = $this->lead('لبس', email: 'noura@example.com', phone: '0500000002');
        app(LinkDuplicateLead::class)->handle($mixed);

        $body = $this->list();
        $row = collect($body['data'])->firstWhere('id', (string) $mixed->getKey());

        $this->assertNull($row['canonical_lead_id'], 'an ambiguous identity was linked to a guess');
        $this->assertSame('ambiguous', $row['duplicate_reason']);
        $this->assertSame(3, $body['meta']['counts']['unique'], 'the refusal to guess was counted as a match');
    }

    /**
     * The absorbed count is one query, not one per row.
     *
     * This list is paginated and a per-row relation would mean a query per lead — invisible on the
     * three rows a test seeds and the difference between a page and a timeout on a real account.
     */
    public function test_the_absorbed_count_does_not_cost_a_query_per_lead(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $canonical = $this->lead("عميل {$i}", email: "c{$i}@example.com");
            app(LinkDuplicateLead::class)->handle($this->lead("عميل {$i}", email: "c{$i}@example.com"));
            $this->assertNotNull($canonical);
        }

        DB::enableQueryLog();
        $this->list();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(16, $queries, "listing 16 leads took {$queries} queries");
    }

    // ---- helpers ---------------------------------------------------------------------------------

    /** @param array<string, mixed> $query */
    private function list(array $query = []): array
    {
        return $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/leads?'.http_build_query($query + ['per_page' => 100]))
            ->assertOk()
            ->json();
    }

    private function lead(string $name, ?string $email = null, ?string $phone = null, string $provider = 'meta'): Lead
    {
        $lead = new Lead;
        $lead->forceFill([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->projectId,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'email_normalized' => $email === null ? null : strtolower(trim($email)),
            'phone_normalized' => $phone === null ? null : preg_replace('/\D/', '', $phone),
            'source' => 'provider',
            'provider' => $provider,
            'provider_lead_id' => (string) Str::uuid(),
        ])->save();

        return $lead;
    }
}
