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
use Illuminate\Support\Carbon;
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

    /**
     * SHORT-LINK-RESUME-001 — a disabled link resumes at the SAME address, and its counter continues.
     *
     * Nothing is minted: the slug people already hold is the one that works again, and the clicks
     * counted before the pause and after it are one series.
     */
    public function test_a_disabled_link_resumes_at_the_same_address_and_its_counter_continues(): void
    {
        $created = $this->create('link', 'https://example.com/offer')->assertCreated();
        $id = $created->json('data.id');
        $slug = $created->json('data.slug');

        $this->get('/l/'.$slug)->assertRedirect('https://example.com/offer');
        $this->actingAs($this->operator, 'sanctum')->postJson("/api/v1/short-links/{$id}/disable")->assertOk();
        $this->get('/l/'.$slug)->assertRedirect(Frontend::origin().'/');

        $resumed = $this->actingAs($this->operator, 'sanctum')->postJson("/api/v1/short-links/{$id}/enable")->assertOk();
        $this->assertTrue($resumed->json('data.is_active'));
        $this->assertSame($slug, $resumed->json('data.slug'), 'the same address resumes');

        $this->get('/l/'.$slug)->assertRedirect('https://example.com/offer');
        $this->assertSame(2, (int) ShortLink::query()->where('slug', $slug)->value('clicks'), 'the counter continues across the pause');
    }

    public function test_enabling_requires_the_permission(): void
    {
        $created = $this->create('link', 'https://example.com/offer')->assertCreated();
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'RO', 'slug' => 'ro-'.uniqid()]);
        $role->givePermissionTo('campaigns.view');
        $reader = User::create([
            'name' => 'RO', 'email' => 'ro-'.uniqid().'@s.test',
            'password' => Hash::make('secret1234'), 'email_verified_at' => now(),
        ]);
        $this->grantMembership($reader, $this->tenant);
        $reader->assignRole($role);

        $this->actingAs($reader, 'sanctum')->postJson('/api/v1/short-links/'.$created->json('data.id').'/enable')->assertForbidden();
    }

    // ---- SMART-LINK-001 — chosen slug, expiry, UTM: each optional, each off by default -------------

    public function test_a_chosen_slug_is_the_address_and_it_resolves(): void
    {
        $res = $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/short-links', ['kind' => 'link', 'value' => 'https://example.com/offer', 'slug' => ' Summer-Sale '])
            ->assertCreated();

        $this->assertSame('summer-sale', $res->json('data.slug'));
        $this->assertTrue($res->json('data.custom_slug'));
        $this->assertSame('active', $res->json('data.state'));
        $this->get('/l/summer-sale')->assertRedirect('https://example.com/offer');
    }

    public function test_a_taken_reserved_or_malformed_slug_is_refused_with_the_reason(): void
    {
        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/short-links', ['kind' => 'link', 'value' => 'https://example.com/a', 'slug' => 'taken-one'])->assertCreated();

        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/short-links', ['kind' => 'link', 'value' => 'https://example.com/b', 'slug' => 'taken-one'])
            ->assertStatus(422)->assertJsonValidationErrors(['slug']);
        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/short-links', ['kind' => 'link', 'value' => 'https://example.com/b', 'slug' => 'api'])
            ->assertStatus(422)->assertJsonValidationErrors(['slug']);
        foreach (['ab', '-bad', 'bad--slug', 'عربي', 'has space'] as $bad) {
            $this->actingAs($this->operator, 'sanctum')
                ->postJson('/api/v1/short-links', ['kind' => 'link', 'value' => 'https://example.com/b', 'slug' => $bad])
                ->assertStatus(422)->assertJsonValidationErrors(['slug']);
        }
    }

    public function test_the_minted_path_is_unchanged_when_no_slug_is_chosen(): void
    {
        $res = $this->create('link', 'https://example.com/minted')->assertCreated();
        $this->assertMatchesRegularExpression('/^[a-z2-9]{7}$/', $res->json('data.slug'));
        $this->assertFalse($res->json('data.custom_slug'));
        $this->assertNull($res->json('data.expires_at'));
        $this->assertNull($res->json('data.utm'));
    }

    public function test_an_expired_link_sends_the_reader_to_the_site_and_counts_nothing(): void
    {
        $res = $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/short-links', ['kind' => 'link', 'value' => 'https://example.com/limited', 'expires_at' => now()->addDay()->toIso8601String()])
            ->assertCreated();
        $slug = $res->json('data.slug');
        $this->get('/l/'.$slug)->assertRedirect('https://example.com/limited');

        Carbon::setTestNow(now()->addDays(2));
        $this->get('/l/'.$slug)->assertRedirect(Frontend::origin().'/');
        $this->assertSame(1, (int) ShortLink::query()->where('slug', $slug)->value('clicks'), 'an expired link counted a click');
        $this->assertSame('expired', $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/short-links')->json('data.0.state'));
        Carbon::setTestNow();

        // A past expiry cannot be chosen at creation; it can be cleared later.
        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/short-links', ['kind' => 'link', 'value' => 'https://example.com/x', 'expires_at' => now()->subDay()->toIso8601String()])
            ->assertStatus(422)->assertJsonValidationErrors(['expires_at']);
        $this->actingAs($this->operator, 'sanctum')
            ->patchJson('/api/v1/short-links/'.$res->json('data.id'), ['expires_at' => null])->assertOk()
            ->assertJsonPath('data.expires_at', null);
    }

    public function test_utm_parameters_are_applied_to_the_destination_once_and_only_known_keys(): void
    {
        $res = $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/short-links', [
                'kind' => 'link', 'value' => 'https://example.com/p?ref=x&utm_source=old',
                'utm' => ['utm_source' => 'tiktok', 'utm_medium' => ' paid ', 'utm_campaign' => '', 'utm_bogus' => 'no'],
            ])->assertCreated();

        $this->assertSame(['utm_source' => 'tiktok', 'utm_medium' => 'paid'], $res->json('data.utm'));
        $this->assertSame('https://example.com/p?ref=x&utm_source=old', $res->json('data.shows'), 'the row shows what was typed');
        $this->get('/l/'.$res->json('data.slug'))->assertRedirect('https://example.com/p?ref=x&utm_source=tiktok&utm_medium=paid');
    }

    public function test_a_whatsapp_link_carries_no_utm(): void
    {
        $res = $this->createWith(['utm' => ['utm_source' => 'tiktok']])->assertCreated();
        $this->assertNull($res->json('data.utm'));
        $this->assertStringNotContainsString('utm_', (string) ShortLink::query()->find($res->json('data.id'))?->destination);
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

    // ---- Delete: the Owner's own correction, observed in Production -----------------------------

    /**
     * «Disable» was never the answer to «I made that by mistake».
     *
     * A disabled link stays in the library forever, and the library is the whole surface. Delete
     * removes it from what the user sees and stops it resolving; the row is soft-deleted so the click
     * history it accumulated is still there to audit, which is the difference between removing a link
     * and losing the record that it existed.
     */
    public function test_a_deleted_link_leaves_the_library(): void
    {
        $id = $this->create('link', 'https://example.com/a')->assertCreated()->json('data.id');

        $this->actingAs($this->operator, 'sanctum')
            ->deleteJson('/api/v1/short-links/'.$id)->assertOk();

        $this->assertSame([], $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/short-links')->assertOk()->json('data'));
    }

    /** And it stops resolving — a link removed from the library must not keep working in a chat. */
    public function test_a_deleted_link_no_longer_resolves(): void
    {
        $created = $this->create('link', 'https://example.com/a')->assertCreated();
        $slug = $created->json('data.slug');

        $this->get('/l/'.$slug)->assertRedirect('https://example.com/a');

        $this->actingAs($this->operator, 'sanctum')
            ->deleteJson('/api/v1/short-links/'.$created->json('data.id'))->assertOk();

        /* The site, not a 404 the reader reached from a chat message — the same rule a disabled one follows. */
        $this->get('/l/'.$slug)->assertRedirect(Frontend::origin().'/');
    }

    /** The click history survives the delete, which is the reason it is a soft delete. */
    public function test_the_click_history_survives_a_delete(): void
    {
        $created = $this->create('link', 'https://example.com/a')->assertCreated();
        $this->get('/l/'.$created->json('data.slug'));

        $this->actingAs($this->operator, 'sanctum')
            ->deleteJson('/api/v1/short-links/'.$created->json('data.id'))->assertOk();

        $this->assertDatabaseHas('short_links', ['slug' => $created->json('data.slug'), 'clicks' => 1]);
    }

    /** Another tenant's link is not theirs to delete, and the refusal is the server's. */
    public function test_a_stranger_cannot_delete_another_tenants_link(): void
    {
        $id = $this->create('link', 'https://example.com/a')->assertCreated()->json('data.id');

        $other = Tenant::create(['name' => 'O', 'slug' => 'o-'.uniqid(), 'status' => 'active']);
        $role = Role::create(['tenant_id' => $other->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $stranger = User::create([
            'name' => 'X', 'email' => 'x-'.uniqid().'@s.test',
            'password' => Hash::make('secret1234'), 'email_verified_at' => now(),
        ]);
        $this->grantMembership($stranger, $other);
        $stranger->assignRole($role);

        $this->actingAs($stranger, 'sanctum')->deleteJson('/api/v1/short-links/'.$id)->assertNotFound();

        $this->assertDatabaseHas('short_links', ['id' => $id, 'deleted_at' => null]);
    }

    /** And an operator without the permission is refused. */
    public function test_deleting_requires_the_permission(): void
    {
        $id = $this->create('link', 'https://example.com/a')->assertCreated()->json('data.id');

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'RO', 'slug' => 'ro-'.uniqid()]);
        $role->givePermissionTo('campaigns.view');
        $reader = User::create([
            'name' => 'RO', 'email' => 'ro-'.uniqid().'@s.test',
            'password' => Hash::make('secret1234'), 'email_verified_at' => now(),
        ]);
        $this->grantMembership($reader, $this->tenant);
        $reader->assignRole($role);

        $this->actingAs($reader, 'sanctum')->deleteJson('/api/v1/short-links/'.$id)->assertForbidden();

        $this->assertDatabaseHas('short_links', ['id' => $id, 'deleted_at' => null]);
    }

    // ---- SHORT-LINK-COMPLIANCE-001 --------------------------------------------------------------

    private function createWith(array $extra): TestResponse
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/short-links', ['kind' => 'whatsapp', 'value' => '966500000009'] + $extra);
    }

    public function test_a_message_is_sanitised_and_percent_encoded_onto_wa_me_only(): void
    {
        $id = $this->createWith(['message' => "مرحبا\u{0007} &redirect=https://evil.test #x\r\n\r\n\r\n\r\nسعر؟"])
            ->assertCreated()->json('data.id');
        $link = ShortLink::query()->findOrFail($id);

        $this->assertStringStartsWith('https://wa.me/966500000009?text=', $link->destination);
        $this->assertSame('wa.me', parse_url($link->destination, PHP_URL_HOST));
        $this->assertStringNotContainsString('&redirect=', $link->destination);
        $this->assertStringNotContainsString("\u{0007}", (string) $link->message);
        parse_str((string) parse_url($link->destination, PHP_URL_QUERY), $query);
        $this->assertSame(['text'], array_keys($query), 'a typed message must never add a parameter');
    }

    public function test_every_visitor_gets_the_same_single_redirect_whatever_the_user_agent(): void
    {
        $slug = ShortLink::query()->findOrFail($this->create('whatsapp', '966500000009')->json('data.id'))->slug;

        foreach (['Mozilla/5.0 (iPhone)', 'Googlebot/2.1', 'facebookexternalhit/1.1', 'TikTokSpider', 'SnapchatAdsReview', ''] as $agent) {
            $this->withHeaders(['User-Agent' => $agent])->get('/l/'.$slug)
                ->assertStatus(302)
                ->assertRedirect('https://wa.me/966500000009');
        }
    }

    public function test_landing_mode_states_the_destination_and_is_identical_for_a_crawler(): void
    {
        $slug = ShortLink::query()->findOrFail($this->createWith(['mode' => 'landing'])->assertCreated()->json('data.id'))->slug;

        $person = $this->get('/l/'.$slug)->assertOk();
        $bot = $this->withHeaders(['User-Agent' => 'Googlebot/2.1'])->get('/l/'.$slug)->assertOk();

        $person->assertSee('wa.me', false)->assertSee('https://wa.me/966500000009', false);
        $this->assertSame($person->getContent(), $bot->getContent());
        $this->assertStringNotContainsString('http-equiv="refresh"', (string) $person->getContent());
    }

    public function test_the_admin_side_proves_the_final_destination_is_whatsapp(): void
    {
        $data = $this->createWith(['message' => 'hello'])->assertCreated()->json('data');

        $this->assertSame('wa.me', $data['destination_host']);
        $this->assertTrue($data['destination_is_whatsapp']);
        $this->assertSame('redirect', $data['mode']);
    }

    public function test_the_message_of_an_existing_whatsapp_link_can_be_edited_and_the_hop_follows_it(): void
    {
        $data = $this->createWith([])->assertCreated()->json('data');
        $slug = $data['slug'];

        $this->actingAs($this->operator, 'sanctum')
            ->patchJson('/api/v1/short-links/'.$data['id'], ['message' => "مرحبا 👋\nأريد التفاصيل، شكراً!"])
            ->assertOk()
            ->assertJsonPath('data.message', "مرحبا 👋\nأريد التفاصيل، شكراً!");

        $location = $this->get('/l/'.$slug)->assertStatus(302)->headers->get('Location');
        $this->assertStringStartsWith('https://wa.me/966500000009?text=', (string) $location);
        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $q);
        $this->assertSame("مرحبا 👋\nأريد التفاصيل، شكراً!", $q['text']);
        $this->assertStringContainsString('%0A', (string) $location, 'a line break must be encoded');
        $this->assertSame(1, ShortLink::query()->findOrFail($data['id'])->clicks, 'the click is counted before the hop');
    }

    public function test_a_non_whatsapp_link_cannot_be_given_a_message(): void
    {
        $id = $this->create('link', 'https://example.com/offer')->assertCreated()->json('data.id');

        $this->actingAs($this->operator, 'sanctum')
            ->patchJson('/api/v1/short-links/'.$id, ['message' => 'hi'])
            ->assertStatus(422);
    }
}
