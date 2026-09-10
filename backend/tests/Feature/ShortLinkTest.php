<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ShortLinks\Models\ShortLink;
use App\Domains\ShortLinks\Services\ShortLinkHops;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Frontend;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * SHORT-LINKS-001 — two fields, one action, and a hop a stranger can follow.
 *
 * ## What this feature is NOT
 *
 * The owner ruled out, by name: redirect types, URL parameters, custom slugs, technical tracking
 * settings, infrastructure. So there is no endpoint that accepts any of them, and the request the
 * interface sends carries a `kind` and a single `value` — named `value` rather than `phone` or `url`
 * precisely because the form has ONE field whose meaning follows the choice above it.
 *
 * ## The phone numbers here are invented
 *
 * The owner gave one number as an EXAMPLE for the interface's placeholder. It is not hardcoded
 * anywhere in this application and it is not used here: a fixture carrying somebody's real number is
 * how a real number ends up in a test failure, a screenshot, or a log.
 */
final class ShortLinkTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'S', 'slug' => 's-'.uniqid(), 'status' => 'active']);
        /*
         * `holdingTenant`, not a bare `setTenantId`: the request teardown clears the context — which
         * is correct — so a single call here would survive only until the first HTTP call, and every
         * assertion made AFTER one would read through an unscoped-to-nothing query and see no rows.
         * This is the harness's own opt-in for tests that assert on the database either side of a
         * request, and it leaves the real teardown behaviour in place for tests that do not.
         */
        $this->holdingTenant((string) $this->tenant->getKey());

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->operator = User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@s.test',
            'password' => Hash::make('secret1234'), 'email_verified_at' => now(),
        ]);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);
    }

    private function create(string $kind, string $value): TestResponse
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/short-links', ['kind' => $kind, 'value' => $value]);
    }

    // ---- WhatsApp: a phone number, and nothing to understand ------------------------------------

    public function test_a_phone_number_becomes_a_whatsapp_destination(): void
    {
        $id = $this->create('whatsapp', '966500000001')->assertCreated()->json('data.id');

        $link = ShortLink::query()->findOrFail($id);

        $this->assertSame('https://wa.me/966500000001', $link->destination);
        $this->assertSame(ShortLink::KIND_WHATSAPP, $link->kind);
    }

    /**
     * Punctuation people actually type is removed rather than refused.
     *
     * A validation message about brackets is the sort of technical obstacle this feature exists not
     * to have — the number is read off a phone screen, where it is already formatted.
     */
    public function test_the_way_people_actually_type_a_number_is_accepted(): void
    {
        foreach (['+966 50 000 0002', '(966) 500-000002', '966-50-0000002'] as $typed) {
            $id = $this->create('whatsapp', $typed)->assertCreated()->json('data.id');

            $this->assertSame(
                'https://wa.me/9665000000'.substr(preg_replace('/\D+/', '', $typed) ?? '', -2),
                ShortLink::query()->findOrFail($id)->destination,
                "«{$typed}» did not resolve to the number it contains",
            );
        }
    }

    /**
     * A leading zero is a national trunk prefix, not part of the international number.
     *
     * `wa.me` reads what it is given literally, so left in, the link opens a chat with nobody — and
     * it fails in the recipient's app rather than here, which is the worst place for it to fail.
     */
    public function test_a_national_trunk_prefix_is_not_part_of_the_number(): void
    {
        $id = $this->create('whatsapp', '0966500000003')->assertCreated()->json('data.id');

        $this->assertSame('https://wa.me/966500000003', ShortLink::query()->findOrFail($id)->destination);
    }

    public function test_something_that_cannot_be_a_phone_number_is_refused(): void
    {
        $this->create('whatsapp', '12345')->assertStatus(422);
        $this->create('whatsapp', 'call me')->assertStatus(422);
    }

    /** The list shows what they TYPED, not the `wa.me` address this derived from it. */
    public function test_the_list_shows_the_number_rather_than_our_derivation(): void
    {
        $this->create('whatsapp', '966500000004')->assertCreated();

        $row = $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/short-links')->assertOk()->json('data.0');

        $this->assertSame('966500000004', $row['shows']);
        $this->assertStringNotContainsString('wa.me', (string) $row['shows']);
    }

    // ---- Link: a safe HTTPS address -------------------------------------------------------------

    public function test_an_https_address_is_shortened(): void
    {
        $id = $this->create('link', 'https://example.com/ramadan-offer?utm_source=whatsapp')
            ->assertCreated()->json('data.id');

        $this->assertSame(
            'https://example.com/ramadan-offer?utm_source=whatsapp',
            ShortLink::query()->findOrFail($id)->destination,
        );
    }

    /**
     * Every refusal here is the feature: a short link is an open redirect with a friendly name.
     *
     * `http` is a downgrade the reader cannot see before clicking; `javascript:` and `data:` are
     * script execution wearing a URL's clothes; credentials in the authority are the oldest way to
     * make a hostile host read as a familiar one; and a host nobody outside can reach turns the
     * platform into a probe of its own network.
     */
    public function test_an_unsafe_destination_is_refused(): void
    {
        foreach ([
            'http://example.com',
            'javascript:alert(1)',
            'data:text/html;base64,PHNjcmlwdD4=',
            'https://user:pass@example.com',
            'https://localhost/admin',
            'https://127.0.0.1/admin',
            'https://10.0.0.5/internal',
            'https://build.internal/secrets',
            'not a url at all',
        ] as $hostile) {
            $this->create('link', $hostile)->assertStatus(422, "«{$hostile}» was accepted as a destination");
        }
    }

    /** A short link to a short link is a loop with an extra request in it. */
    public function test_our_own_hop_cannot_be_shortened(): void
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        $this->create('link', 'https://'.$host.'/l/abcdefg')->assertStatus(422);
    }

    // ---- The hop --------------------------------------------------------------------------------

    public function test_the_hop_redirects_and_counts_the_click(): void
    {
        $slug = $this->create('link', 'https://example.com/offer')->assertCreated()->json('data.slug');

        $this->get('/l/'.$slug)->assertRedirect('https://example.com/offer');

        $link = ShortLink::query()->where('slug', $slug)->firstOrFail();
        $this->assertSame(1, $link->clicks);
        $this->assertNotNull($link->last_clicked_at);
    }

    /** Followed by a stranger: no session, no tenant, and it still resolves. */
    public function test_the_hop_needs_no_session(): void
    {
        $slug = $this->create('link', 'https://example.com/public')->assertCreated()->json('data.slug');

        app(TenantContext::class)->forget();

        $this->get('/l/'.$slug)->assertRedirect('https://example.com/public');
    }

    /**
     * A disabled link stops redirecting; a slug nobody minted goes to the site.
     *
     * Not a 404: a browser opened from a chat message onto an error page is a dead end with nothing
     * on it, and the reader did not mistype our URL on purpose.
     */
    public function test_a_disabled_or_unknown_slug_sends_the_reader_to_the_site(): void
    {
        $created = $this->create('link', 'https://example.com/expired')->assertCreated();
        $slug = $created->json('data.slug');

        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/short-links/'.$created->json('data.id').'/disable')->assertOk();

        $this->get('/l/'.$slug)->assertRedirect(Frontend::origin().'/');
        $this->get('/l/zzzzzzz')->assertRedirect(Frontend::origin().'/');

        $this->assertSame(0, (int) ShortLink::query()->where('slug', $slug)->value('clicks'), 'a disabled link counted a click');
    }

    // ---- The slug ------------------------------------------------------------------------------

    /**
     * No character that looks like another in a sans-serif font.
     *
     * The stated case is a link read off a screen and typed, or dictated. `0`/`O` and `1`/`l`/`I` are
     * a support conversation, and narrowing the alphabet costs nothing.
     */
    public function test_the_slug_avoids_characters_that_look_like_each_other(): void
    {
        $slugs = [];

        foreach (range(1, 25) as $i) {
            $slugs[] = (string) $this->create('link', 'https://example.com/p'.$i)->assertCreated()->json('data.slug');
        }

        foreach ($slugs as $slug) {
            $this->assertMatchesRegularExpression('/^'.ShortLinkHops::slugPattern().'$/', $slug);
            $this->assertDoesNotMatchRegularExpression('/[01lIoO]/', $slug);
        }

        $this->assertCount(25, array_unique($slugs), 'two links were minted the same slug');
    }

    // ---- Reach ---------------------------------------------------------------------------------

    public function test_another_tenants_links_are_not_listed(): void
    {
        $this->create('link', 'https://example.com/mine')->assertCreated();

        $other = Tenant::create(['name' => 'O', 'slug' => 'o-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($other->id);
        $role = Role::create(['tenant_id' => $other->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $stranger = User::create(['name' => 'S', 'email' => 's-'.uniqid().'@o.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($stranger, $other);
        $stranger->assignRole($role);

        $this->assertSame([], $this->actingAs($stranger, 'sanctum')->getJson('/api/v1/short-links')->assertOk()->json('data'));
    }

    public function test_creating_needs_more_than_reading(): void
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'RO', 'slug' => 'ro-'.uniqid()]);
        $role->givePermissionTo('campaigns.view');

        $reader = User::create(['name' => 'R', 'email' => 'r-'.uniqid().'@s.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($reader, $this->tenant);
        $reader->assignRole($role);

        $this->actingAs($reader, 'sanctum')->getJson('/api/v1/short-links')->assertOk();
        $this->actingAs($reader, 'sanctum')
            ->postJson('/api/v1/short-links', ['kind' => 'link', 'value' => 'https://example.com'])
            ->assertForbidden();
    }

    /** The owner's example number is an interface placeholder and appears nowhere in this codebase. */
    public function test_the_example_number_is_not_hardcoded_in_the_application(): void
    {
        $offenders = [];

        foreach (['app', 'database', 'routes'] as $dir) {
            $found = shell_exec('grep -rl "966532115582" '.base_path($dir).' 2>/dev/null');

            if (is_string($found) && trim($found) !== '') {
                $offenders[] = trim($found);
            }
        }

        $this->assertSame([], $offenders, 'the example phone number is hardcoded in: '.implode(', ', $offenders));
    }

    /**
     * The address a person copies carries the name customers know, not the API host.
     *
     * The owner specified `https://campaignshub.io/l/{slug}`. In production the SPA and the API are
     * different hosts, and this is the one URL in the product that gets read aloud, printed and
     * pasted into a message — so it is built from the frontend origin. The hop itself is a Laravel
     * route, which is why `deploy/nginx-spa.conf` carries a `location /l/` above the SPA fallback:
     * the only part of this feature that is not deployed by pushing code.
     */
    public function test_the_copied_address_is_on_the_customer_facing_host(): void
    {
        config(['brand.frontend_url' => 'https://links.example', 'app.url' => 'https://api.example']);

        $slug = $this->create('link', 'https://example.com/named')->assertCreated()->json('data.slug');

        $this->assertSame(
            'https://links.example/l/'.$slug,
            $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/short-links')
                ->assertOk()->json('data.0.short_url'),
            'the copied link named the API host rather than the one customers know',
        );
    }
}
