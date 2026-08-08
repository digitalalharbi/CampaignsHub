<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Notifications\Services\AlertDispatcher;
use App\Domains\Notifications\Services\NotificationDispatcher;
use App\Domains\Notifications\Support\MessageCatalogue;
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
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * MAIL-011 — a person can decide about each message, and the decision is honoured where mail is sent.
 *
 * Three failure modes this file is arranged against, all of them silent:
 *
 * 1. **A switch that controls more than it says.** The old screen offered six categories, and every
 *    message type nobody had classified fell into `performance` — so switching «الأداء» off also
 *    stopped conversation messages and subscription notices.
 * 2. **A switch that controls nothing.** A checkbox for a message the product cannot send, or a
 *    rhythm nothing implements. Neither errors; the person turns it on and waits.
 * 3. **A setting erased by saving a different one.** The two screens editing this row wrote every
 *    column on every save, so a checkbox in settings cleared a digest chosen from the account page.
 */
final class NotificationPreferencesCentreTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-08-07 09:00:00';

    private Tenant $tenant;

    private Project $alpha;

    private Project $beta;

    private User $owner;

    private User $scoped;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-prefs', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $one = ClientWorkspace::create(['name' => 'One', 'slug' => 'one-prefs', 'mode' => 'managed']);
        $two = ClientWorkspace::create(['name' => 'Two', 'slug' => 'two-prefs', 'mode' => 'managed']);
        $this->alpha = Project::create(['client_workspace_id' => $one->id, 'name' => 'مشروع', 'status' => 'active']);
        $this->beta = Project::create(['client_workspace_id' => $two->id, 'name' => 'Beta', 'status' => 'active']);

        $ownerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner-prefs']);
        $ownerRole->givePermissionTo('clients.view_all');
        $memberRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Member', 'slug' => 'member-prefs']);

        $this->owner = $this->member('owner@prefs.test', $ownerRole);
        $this->scoped = $this->member('scoped@prefs.test', $memberRole, (string) $this->alpha->id);

        app(TenantContext::class)->forget();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $email, Role $role, ?string $projectScope = null): User
    {
        $user = User::create(['name' => $email, 'email' => $email, 'password' => 'secret123']);
        $membership = Membership::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'portal' => 'agency', 'status' => 'active',
        ]);
        if ($projectScope !== null) {
            MembershipScope::create([
                'membership_id' => $membership->id,
                'scope_type' => MembershipScope::TYPE_PROJECT,
                'scope_id' => $projectScope,
            ]);
        }
        $user->assignRole($role);

        return $user->fresh();
    }

    /** @param array<string,mixed> $body */
    private function save(array $body, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner, 'sanctum')->putJson('/api/v1/settings/notifications', $body);
    }

    private function read(?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner, 'sanctum')->getJson('/api/v1/settings/notifications');
    }

    // ── The catalogue itself ────────────────────────────────────────────────────────────────────

    /**
     * Every switch on the screen belongs to a message something in this repository sends.
     *
     * A preference for a message that cannot arrive is worse than a missing preference: the person
     * who turns it on and waits has no way to discover that nothing will ever come. `sent_by` names
     * the sender; this walks the source tree and checks each one is really there.
     */
    public function test_every_message_on_the_screen_is_one_the_product_can_actually_send(): void
    {
        $source = '';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $source .= $file->getFilename()."\n";
            }
        }

        foreach (MessageCatalogue::types() as $type => $definition) {
            /*
             * `sent_by` is one or more class names, each optionally carrying a method or a constant
             * (`Service::method`, `Mail::SOME_CONSTANT`), chained with `→` or `/` where a message
             * passes through more than one. Everything after `::` is a member rather than a file.
             */
            $classes = [];
            foreach (preg_split('#[→/]#u', $definition['sent_by']) ?: [] as $part) {
                $name = trim(explode('::', trim($part))[0]);
                if ($name !== '') {
                    $classes[] = $name;
                }
            }

            $this->assertNotEmpty($classes, "«{$type}» does not name a sender at all");

            foreach ($classes as $class) {
                $this->assertStringContainsString(
                    $class.'.php',
                    $source,
                    "«{$type}» claims to be sent by {$class}, which does not exist",
                );
            }
        }
    }

    /**
     * Every finding the digest engine can produce has a switch.
     *
     * The reverse of the test above, and the one that would have caught the original defect: a note
     * kind that is not in the catalogue is resolved as `performance` by default, so somebody
     * switching performance off silently loses a message about their budget or their integrations.
     */
    public function test_every_finding_the_engine_produces_has_a_switch_of_its_own(): void
    {
        $source = file_get_contents(app_path('Domains/Reports/Services/ReportObservations.php'));
        preg_match_all("/'kind' => '([a-z_]+)'/", (string) $source, $matches);

        $this->assertNotEmpty($matches[1], 'the observations engine produced no kinds to check');

        foreach (array_unique($matches[1]) as $kind) {
            $this->assertTrue(
                MessageCatalogue::has($kind),
                "«{$kind}» is a finding the product can raise and nobody can switch it off",
            );
        }
    }

    public function test_the_screen_describes_every_catalogued_message(): void
    {
        $payload = $this->read()->assertOk()->json('data');

        $shown = [];
        foreach ($payload['catalogue'] as $group) {
            foreach ($group['types'] as $t) {
                $shown[] = $t['key'];
            }
        }

        $this->assertEqualsCanonicalizing(MessageCatalogue::keys(), $shown);
        // And an effective answer for each, so no row on the screen has to guess.
        foreach (MessageCatalogue::keys() as $key) {
            $this->assertArrayHasKey($key, $payload['types'], "«{$key}» has no effective setting");
        }
    }

    // ── Saving ──────────────────────────────────────────────────────────────────────────────────

    /**
     * **The regression.** Saving one setting does not erase another.
     *
     * The old `update()` wrote every column on every request, so a client that did not know about
     * `digests` cleared it. This is the exact sequence a person performed: choose a digest on one
     * screen, tick something unrelated on the other.
     */
    public function test_saving_one_setting_does_not_erase_another(): void
    {
        $this->save([
            'digests' => ['daily' => true, 'weekly' => false, 'alerts' => true],
            'timezone' => 'Europe/London',
            'digest_hour' => 6,
            'locale' => 'en',
        ])->assertOk();

        // A save that says nothing about the digests at all.
        $this->save(['channels' => ['in_app' => true, 'email' => true]])->assertOk();

        $after = $this->read()->assertOk()->json('data');
        $this->assertTrue($after['digests']['daily'], 'the digest opt-in was erased by an unrelated save');
        $this->assertSame('Europe/London', $after['timezone']);
        $this->assertSame(6, $after['digest_hour']);
        $this->assertSame('en', $after['locale']);
    }

    /**
     * Reading the document and sending it straight back changes nothing.
     *
     * `show()` returns an effective map that includes mandatory types and the two digests, and the
     * obvious client edits one field and PUTs the whole thing. If echoing what it was just given were
     * a refusal, the screen would break on a save that changed nothing at all.
     */
    public function test_reading_the_settings_and_sending_them_back_is_accepted_and_changes_nothing(): void
    {
        $this->save(['digests' => ['daily' => true, 'weekly' => false, 'alerts' => true]])->assertOk();

        $before = $this->read()->assertOk()->json('data');
        $this->save($before)->assertOk();
        $after = $this->read()->assertOk()->json('data');

        $this->assertSame($before['digests'], $after['digests']);
        $this->assertSame($before['types'], $after['types']);
        $this->assertSame($before['timezone'], $after['timezone']);
    }

    public function test_a_message_that_cannot_be_switched_off_refuses_to_be(): void
    {
        $this->save(['types' => ['password_reset' => ['email' => false]]])
            ->assertStatus(422)
            ->assertSee('cannot be switched off', false);

        $this->assertTrue($this->read()->json('data.types.password_reset.email'));
    }

    public function test_a_message_the_product_does_not_have_is_refused(): void
    {
        $this->save(['types' => ['weekly_horoscope' => ['email' => true]]])
            ->assertStatus(422)
            ->assertSee('no message type', false);
    }

    /**
     * A rhythm nothing implements is refused rather than stored.
     *
     * Nothing batches an invoice or a new conversation message, so `weekly` against one of those
     * would hold it for a digest that will never carry it — a message that vanishes, with a setting
     * on the screen that explains why in a way that is not true.
     */
    public function test_a_rhythm_that_message_does_not_have_is_refused(): void
    {
        $this->save(['types' => ['subscription' => ['rhythm' => 'weekly']]])
            ->assertStatus(422)
            ->assertSee('cannot be sent on', false);

        // The same rhythm on a finding the digest does carry is accepted.
        $this->save(['types' => ['budget_pace' => ['rhythm' => 'weekly']]])->assertOk();
        $this->assertSame('weekly', $this->read()->json('data.types.budget_pace.rhythm'));
    }

    public function test_a_digest_is_switched_through_its_own_opt_in_not_per_type(): void
    {
        $this->save([
            'digests' => ['daily' => false, 'weekly' => false, 'alerts' => false],
            'types' => ['daily_digest' => ['email' => true]],
        ])->assertStatus(422)->assertSee('digest opt-ins', false);

        $this->save(['digests' => ['daily' => true, 'weekly' => false, 'alerts' => false]])->assertOk();
        $this->assertTrue($this->read()->json('data.types.daily_digest.email'));
    }

    // ── The resolution order ────────────────────────────────────────────────────────────────────

    /**
     * An older per-category choice still silences the messages that lived under it.
     *
     * Thousands of rows hold the six-key map and nothing rewrites them, so somebody who switched
     * «الميزانية» off before this unit existed must still have budget mail off afterwards without
     * touching the new screen.
     */
    public function test_a_choice_made_before_this_screen_existed_is_still_honoured(): void
    {
        DB::table('notification_preferences')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => (string) $this->tenant->id,
            'user_id' => $this->owner->id,
            'channels' => json_encode(['in_app' => true, 'email' => true]),
            'categories' => json_encode(['budget' => ['in_app' => true, 'email' => false]]),
            'frequency' => 'realtime',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $types = $this->read()->assertOk()->json('data.types');

        $this->assertFalse($types['budget_pace']['email'], 'an older budget choice stopped being honoured');
        $this->assertFalse($types['rising_cost']['email']);
        // And it says nothing about a category it was never written for.
        $this->assertTrue($types['sync_failed']['email']);
    }

    public function test_switching_email_off_entirely_outranks_a_per_type_yes(): void
    {
        $this->save([
            'channels' => ['in_app' => true, 'email' => false],
            'types' => ['budget_pace' => ['email' => true, 'in_app' => true, 'rhythm' => 'immediate']],
        ])->assertOk();

        $this->assertFalse($this->read()->json('data.types.budget_pace.email'));
        // Except for the messages that have no switch at all.
        $this->assertTrue($this->read()->json('data.types.password_reset.email'));
    }

    // ── Where it actually bites ─────────────────────────────────────────────────────────────────

    /**
     * A message switched off is not sent, even to somebody who asked for alerts.
     *
     * The sweep is the only place this can be proven. `NotificationChoices` answering correctly
     * would mean nothing if the sender never asked it — which is exactly the shape of the defect
     * MAIL-010 had, where the resolver honoured a category the alert path never consulted.
     */
    public function test_a_message_switched_off_is_not_mailed_even_when_alerts_are_on(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();
        $this->seedFallingCtr();

        $this->save([
            'digests' => ['daily' => false, 'weekly' => false, 'alerts' => true],
            'types' => ['falling_rate' => ['email' => false, 'in_app' => true, 'rhythm' => 'immediate']],
        ])->assertOk();

        $counts = app(AlertDispatcher::class)->sweep($this->owner, (string) $this->tenant->id, Carbon::parse('2026-08-07'));

        $this->assertSame(0, $counts['sent']);
        $this->assertSame(1, $counts['switched_off']);
        Mail::assertNothingSent();
    }

    /**
     * «Not now, in the digest» holds the message rather than losing it.
     *
     * The digests already print every observation for the period, which is what makes this rhythm
     * honest: the person still receives the finding, later, in the summary.
     */
    public function test_a_message_set_to_daily_is_held_for_the_digest_rather_than_mailed_now(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();
        $this->seedFallingCtr();

        $this->save([
            'digests' => ['daily' => true, 'weekly' => false, 'alerts' => true],
            'types' => ['falling_rate' => ['email' => true, 'in_app' => true, 'rhythm' => 'daily']],
        ])->assertOk();

        $counts = app(AlertDispatcher::class)->sweep($this->owner, (string) $this->tenant->id, Carbon::parse('2026-08-07'));

        $this->assertSame(0, $counts['sent']);
        $this->assertSame(1, $counts['held_for_digest']);
        Mail::assertNothingSent();
    }

    /** Somebody who has expressed no opinion still receives what they opted into. */
    public function test_asking_for_alerts_without_touching_a_single_switch_still_delivers_them(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();
        $this->seedFallingCtr();

        $counts = app(AlertDispatcher::class)->sweep($this->owner, (string) $this->tenant->id, Carbon::parse('2026-08-07'));

        $this->assertSame(1, $counts['sent'], 'an opt-in was narrowed by defaults nobody chose');
    }

    /** The bell honours the same switch, so the two surfaces cannot disagree. */
    public function test_the_in_app_bell_honours_a_per_type_switch(): void
    {
        $this->save(['types' => ['sync_failed' => ['email' => false, 'in_app' => false, 'rhythm' => 'immediate']]])->assertOk();

        $raised = app(NotificationDispatcher::class)->dispatch([
            'tenant_id' => (string) $this->tenant->id,
            'user_id' => $this->owner->id,
            'type' => 'sync_failed',
            'title' => 'توقفت المزامنة',
        ]);

        $this->assertNull($raised, 'a message the person switched off still reached the bell');
    }

    // ── The scope and the clock ─────────────────────────────────────────────────────────────────

    /**
     * The project picker offers a person's own ceiling and nothing beyond it.
     *
     * It is a settings screen, so the temptation is to list every project in the workspace and let
     * the digest filter later. That would make this form a way to learn which clients exist.
     */
    public function test_the_project_list_offers_only_what_that_person_can_already_reach(): void
    {
        $mine = array_column((array) $this->read($this->scoped)->assertOk()->json('data.projects'), 'name');

        $this->assertSame(['مشروع'], $mine);
        $this->assertNotContains('Beta', $mine);
    }

    public function test_the_project_scope_can_be_chosen_and_is_stored(): void
    {
        $this->save(['project_ids' => [(string) $this->alpha->id]])->assertOk();
        $this->assertSame([(string) $this->alpha->id], $this->read()->json('data.project_ids'));

        // Clearing it back to «everything I can reach» is a null, not an empty array of nothing.
        $this->save(['project_ids' => null])->assertOk();
        $this->assertNull($this->read()->json('data.project_ids'));
    }

    // ── Fixtures ────────────────────────────────────────────────────────────────────────────────

    private function withConfiguredEmail(): void
    {
        config()->set('providers.channels.email', ConfiguredAlertEmailProvider::class);
    }

    /** A day whose click-through rate collapsed against a flat reach — a `falling_rate` warning. */
    private function seedFallingCtr(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $write = function (string $key, float $value, string $date): void {
            app(UpsertDailyMetrics::class)->handle([
                new NormalizedMetric(
                    tenantId: (string) $this->tenant->id,
                    projectId: (string) $this->alpha->id,
                    externalAccountId: '11111111-1111-1111-1111-111111111111',
                    externalCampaignId: '22222222-2222-2222-2222-222222222222',
                    provider: 'meta',
                    metricKey: $key,
                    metricDate: Carbon::parse($date),
                    value: $value,
                ),
            ]);
        };

        $write('spend', 1000.0, '2026-08-07');
        $write('impressions', 300_000.0, '2026-08-07');
        $write('clicks', 1_000.0, '2026-08-07');

        $write('spend', 1000.0, '2026-08-06');
        $write('impressions', 300_000.0, '2026-08-06');
        $write('clicks', 3_000.0, '2026-08-06');

        app(TenantContext::class)->forget();
    }
}
