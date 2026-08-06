<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Influencers\Models\Influencer;
use App\Domains\Influencers\Models\InfluencerCollaboration;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * A working influencer & UGC demo (INFL-001).
 *
 * Deliberately includes work in more than one state — published, owed, and LATE — because a demo in
 * which everything is fine shows none of what the portal is for. Deliverable dates are relative to
 * the seed run, so the overdue row stays overdue however long the demo sits.
 *
 * Two accounts, because the money boundary is the interesting one: `creators@` reads the work,
 * `creators.finance@` also holds `influencers.view_costs` and therefore sees what the creator is paid
 * and the margin. Signing in as each is the fastest way to see that the withholding is real.
 *
 * Idempotent; safe to re-run.
 */
final class DemoInfluencersSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'demo-agency')->first();

        if ($tenant === null) {
            return;
        }

        app(TenantContext::class)->setTenantId((string) $tenant->id);

        // `id` breaks the tie: the demo agency's spaces share a `created_at` to the second, and an
        // ordering that is not total picks an arbitrary row from physical order (see the note in
        // `DemoPortalLoginsSeeder::clientPortalCustomer()`). Re-seeding would move this work between
        // client spaces for no reason a reader could see.
        $client = ClientWorkspace::query()
            ->where('tenant_id', $tenant->id)->whereNull('archived_at')
            ->orderBy('created_at')->orderBy('id')->first();

        $roster = [
            ['name' => 'Layla Al-Harbi', 'handle' => 'layla.creates', 'primary_platform' => 'instagram',
                'followers' => 480_000, 'engagement_rate' => 4.60, 'tier' => 'macro',
                'categories' => ['lifestyle', 'beauty'], 'country' => 'SA', 'language' => 'ar'],
            ['name' => 'Omar Nasser', 'handle' => 'omarn', 'primary_platform' => 'tiktok',
                'followers' => 1_250_000, 'engagement_rate' => 6.10, 'tier' => 'mega',
                'categories' => ['comedy', 'food'], 'country' => 'SA', 'language' => 'ar'],
            ['name' => 'Sara Kamal', 'handle' => 'sarakamal', 'primary_platform' => 'youtube',
                'followers' => 92_000, 'engagement_rate' => 3.20, 'tier' => 'mid',
                'categories' => ['tech'], 'country' => 'AE', 'language' => 'en'],
        ];

        $created = [];
        foreach ($roster as $row) {
            $created[] = Influencer::firstOrCreate(
                ['tenant_id' => $tenant->id, 'handle' => $row['handle'], 'primary_platform' => $row['primary_platform']],
                $row + ['tenant_id' => $tenant->id, 'status' => 'active'],
            );
        }

        // Published, owed, and late — the three states the portal exists to tell apart.
        $this->collaborate($tenant, $created[0], $client, 'Ramadan lifestyle push', 45_000, 32_000, [
            ['type' => 'reel', 'platform' => 'instagram', 'days' => -12, 'status' => 'published'],
            ['type' => 'story', 'platform' => 'instagram', 'days' => -3, 'status' => 'pending'],
            ['type' => 'post', 'platform' => 'instagram', 'days' => 6, 'status' => 'pending'],
        ]);

        $this->collaborate($tenant, $created[1], $client, 'Product launch takeover', 120_000, 90_000, [
            ['type' => 'video', 'platform' => 'tiktok', 'days' => -1, 'status' => 'submitted'],
        ]);

        // No client: agency-internal work, which must stay visible to a client-scoped operator.
        $this->collaborate($tenant, $created[2], null, 'Our own brand channel', 18_000, 12_000, [
            ['type' => 'review', 'platform' => 'youtube', 'days' => 20, 'status' => 'pending'],
        ]);

        $this->operators($tenant);
        $this->creatorSide($tenant, $created[0]);

        $this->command?->info('Demo: influencer roster + collaborations seeded (one overdue on purpose).');
    }

    /** @param  list<array{type: string, platform: string, days: int, status: string}>  $deliverables */
    private function collaborate(
        Tenant $tenant,
        Influencer $influencer,
        ?ClientWorkspace $client,
        string $title,
        float $agreed,
        float $creatorFee,
        array $deliverables,
    ): void {
        $collaboration = InfluencerCollaboration::firstOrCreate(
            ['tenant_id' => $tenant->id, 'influencer_id' => $influencer->id, 'title' => $title],
            [
                'client_workspace_id' => $client?->id,
                'status' => 'active',
                'currency' => 'SAR',
                'agreed_fee' => $agreed,
                'influencer_fee' => $creatorFee,
                'starts_on' => now()->subDays(20)->toDateString(),
                'ends_on' => now()->addDays(30)->toDateString(),
                'brief' => 'Demo brief — sample content only.',
                'internal_notes' => 'Demo record. Not a real agreement.',
            ],
        );

        if ($collaboration->deliverables()->exists()) {
            return;
        }

        foreach ($deliverables as $d) {
            $collaboration->deliverables()->create([
                'tenant_id' => $tenant->id,
                'type' => $d['type'],
                'platform' => $d['platform'],
                'status' => $d['status'],
                'due_on' => now()->addDays($d['days'])->toDateString(),
                'submitted_url' => $d['status'] === 'pending' ? null : 'https://example.test/demo/'.$d['type'],
                'submitted_at' => $d['status'] === 'pending' ? null : now()->subDays(2),
                'published_at' => $d['status'] === 'published' ? now()->subDays(1) : null,
            ]);
        }
    }

    /**
     * The other side of the agreement (INFL-002).
     *
     * Layla gets a login, so signing in as her next to `creators.finance@` shows the money boundary
     * working in BOTH directions rather than only one: the finance operator sees the client's price
     * and the margin, and Layla sees what she is paid and no trace of either.
     *
     * Two collaborations in different states, because a creator with nothing to answer demonstrates
     * nothing: one offer waiting on her, and one already accepted with work owed. The third roster
     * entry deliberately keeps no login, so the roster shows both cases side by side.
     */
    private function creatorSide(Tenant $tenant, Influencer $creator): void
    {
        if ($creator->hasPortalAccess()) {
            return;
        }

        $user = User::firstOrCreate(
            ['email' => 'layla@creators.demo'],
            [
                'name' => $creator->name,
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $tenant, portal: Portal::Influencers, role: 'creator',
        ));

        $creator->forceFill(['user_id' => $user->getKey()])->save();

        // Already accepted — she has work owed, including one piece that is late.
        InfluencerCollaboration::query()
            ->where('tenant_id', $tenant->id)->where('influencer_id', $creator->id)
            ->where('title', 'Ramadan lifestyle push')
            ->update([
                'terms_sent_at' => now()->subDays(21),
                'creator_decision' => 'accepted',
                'creator_responded_at' => now()->subDays(20),
                'status' => 'active',
            ]);

        // …and an offer she has not answered, which is what her portal opens with.
        $offer = InfluencerCollaboration::firstOrCreate(
            ['tenant_id' => $tenant->id, 'influencer_id' => $creator->id, 'title' => 'Back-to-school teaser'],
            [
                'status' => 'awaiting_creator',
                'currency' => 'SAR',
                'agreed_fee' => 30_000,
                'influencer_fee' => 21_000,
                'starts_on' => now()->addDays(10)->toDateString(),
                'ends_on' => now()->addDays(40)->toDateString(),
                'brief' => 'Demo brief — two reels, product visible in the opening seconds.',
                'internal_notes' => 'Demo record. Not a real agreement.',
            ],
        );
        $offer->forceFill(['terms_sent_at' => now()->subDay()])->save();

        if (! $offer->deliverables()->exists()) {
            $offer->deliverables()->createMany([
                ['tenant_id' => $tenant->id, 'type' => 'reel', 'platform' => 'instagram',
                    'status' => 'pending', 'due_on' => now()->addDays(18)->toDateString()],
                ['tenant_id' => $tenant->id, 'type' => 'story', 'platform' => 'instagram',
                    'status' => 'pending', 'due_on' => now()->addDays(25)->toDateString()],
            ]);
        }
    }

    /** Two operators, so the cost boundary can be seen rather than taken on trust. */
    private function operators(Tenant $tenant): void
    {
        // `clients.view_all` because a creator manager at an agency works across the whole client
        // list — without it the ceiling is fail-closed and they would see only the agency's own
        // work, which is correct behaviour but a demo of nothing. The scoped case is demonstrated
        // by `manager@demo-agency.local` in the agency portal instead.
        $shared = ['influencers.view', 'campaigns.view', 'reports.view', ClientScopeResolver::ALL_CLIENTS];

        $this->operator($tenant, 'creators@demo-agency.local', 'Demo Creator Manager', 'creator-manager',
            [...$shared, 'influencers.manage']);

        $this->operator($tenant, 'creators.finance@demo-agency.local', 'Demo Creator Finance', 'creator-finance',
            [...$shared, 'influencers.manage', 'influencers.view_costs']);
    }

    /** @param  list<string>  $permissions */
    private function operator(Tenant $tenant, string $email, string $name, string $roleSlug, array $permissions): void
    {
        $role = Role::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => $roleSlug],
            ['name' => $name, 'is_system' => true],
        );
        $role->givePermissionTo(...$permissions);

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );
        $user->assignRole($role);

        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $tenant, portal: Portal::Influencers, role: 'member',
        ));
    }
}
