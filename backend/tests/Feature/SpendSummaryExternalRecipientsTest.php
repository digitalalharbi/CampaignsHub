<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\SpendLimit;
use App\Domains\Notifications\Mail\SpendSummaryMail;
use App\Domains\Notifications\Models\ExternalDigestRecipient;
use App\Domains\Notifications\Services\MailUnsubscribes;
use App\Domains\Notifications\Services\ProjectSpendSummary;
use App\Domains\Notifications\Services\SpendSummaryDispatcher;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SPEND-SUMMARY-EXTERNAL-RECIPIENTS-001 — «ملخص الإنفاق» and the budget follow-up for recipients OUTSIDE the platform
 * (`docs/OWNER_NOTIFICATIONS_RULES_2026-11-12.md` §3 and §8).
 */
final class SpendSummaryExternalRecipientsTest extends TestCase
{
    use RefreshDatabase;

    /** 08:00 Riyadh on Sunday 15 November 2026 — the weekly is due; the 7 complete days are 8–14 November. */
    private const NOW_UTC = '2026-11-15 05:00:00';

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW_UTC, 'UTC'));
        $this->seed(PermissionSeeder::class);
        [$this->tenant, $this->owner] = $this->workspace('a');
        config()->set('providers.channels.email', ConfiguredDigestEmailProvider::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Tenant, 1: User} */
    private function workspace(string $slug, bool $manage = true): array
    {
        $tenant = Tenant::create(['name' => strtoupper($slug), 'slug' => $slug.'-ss-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $keys = Permission::pluck('key')->all();
        $role->givePermissionTo(...($manage ? $keys : array_values(array_diff($keys, ['settings.manage']))));
        $user = User::create(['name' => 'Owner '.$slug, 'email' => 'owner-'.uniqid().'@ss.test', 'password' => 'secret123']);
        $user->forceFill(['email_verified_at' => now()])->save();
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'portal' => 'agency', 'status' => 'active']);
        $user->assignRole($role);

        return [$tenant, $user];
    }

    private function project(Tenant $tenant, string $name): Project
    {
        app(TenantContext::class)->setTenantId($tenant->id);
        $ws = ClientWorkspace::create(['tenant_id' => $tenant->id, 'name' => 'C'.$name, 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);

        return Project::create(['tenant_id' => $tenant->id, 'client_workspace_id' => $ws->id, 'name' => $name, 'status' => 'active']);
    }

    private function spend(Project $p, string $date, ?float $value, string $currency = 'SAR', ?float $original = null): void
    {
        DB::table('daily_metrics')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $p->tenant_id, 'project_id' => $p->id,
            'external_account_id' => (string) Str::uuid(), 'external_campaign_id' => (string) Str::uuid(),
            'unified_campaign_id' => null, 'provider' => 'meta', 'metric_key' => 'spend', 'metric_date' => $date,
            'value' => $value, 'project_currency' => $value === null ? null : $currency,
            'original_amount' => $original, 'original_currency' => $original === null ? null : 'USD',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function limit(Project $p, float $amount, string $currency = 'SAR'): void
    {
        SpendLimit::query()->withoutGlobalScopes()->create([
            'tenant_id' => $p->tenant_id, 'project_id' => $p->id, 'scope' => 'project', 'scope_id' => null,
            'amount' => $amount, 'currency' => $currency, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'active' => true,
        ]);
    }

    private function recipient(Tenant $tenant, array $projectIds, string $cadence = 'weekly', string $email = 'pm@client.test'): ExternalDigestRecipient
    {
        app(TenantContext::class)->setTenantId($tenant->id);
        $r = ExternalDigestRecipient::create(['tenant_id' => $tenant->id, 'email' => $email, 'name' => 'Sara', 'cadence' => $cadence, 'active' => true]);
        $r->bindProjects($projectIds);

        return $r;
    }

    // ── «ملخص الإنفاق» — the figures ─────────────────────────────────────────

    public function test_the_summary_states_spend_limit_remaining_share_and_days_left_from_real_figures_only(): void
    {
        $a = $this->project($this->tenant, 'Alpha');
        $b = $this->project($this->tenant, 'Beta');
        $c = $this->project($this->tenant, 'Gamma');
        foreach (range(8, 14) as $d) {
            $this->spend($a, "2026-11-{$d}", 100.0);           // 700 in the week, 700 of the 1,000 limit this month
        }
        $this->limit($a, 1000.0);
        $this->spend($b, '2026-11-10', 300.0);                  // no limit set
        $this->spend($c, '2026-11-10', null, 'SAR', 500.0);     // withheld (no exchange rate)
        $this->limit($c, 1000.0);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        $s = app(ProjectSpendSummary::class)->build((string) $this->tenant->id, [(string) $a->id, (string) $b->id, (string) $c->id],
            Carbon::parse('2026-11-08'), Carbon::parse('2026-11-14')->endOfDay(), Carbon::parse('2026-11-15'));
        $by = array_column($s['projects'], null, 'name');

        $this->assertSame(700.0, $by['Alpha']['spend']);
        $this->assertSame(1000.0, $by['Alpha']['cap']);
        $this->assertSame(300.0, $by['Alpha']['remaining']);
        $this->assertSame('ok', $by['Alpha']['state']);
        $this->assertSame(3, $by['Alpha']['days_left'], '300 left at 100 a day');
        $this->assertSame('none', $by['Beta']['state'], 'no limit is said, never a 0');
        $this->assertSame('unconverted', $by['Gamma']['state']);
        $this->assertNull($by['Gamma']['remaining']);
        $this->assertNull($s['total']['spend'], 'a withheld spend leaves no false total');

        $html = (new SpendSummaryMail($s, 'ar', 'weekly'))->render();
        $this->assertStringContainsString('ملخص الإنفاق • الأسبوع الماضي', $html);
        $this->assertStringContainsString('غير محوّل', $html);
        $this->assertStringContainsString('ما فيه حد إنفاق مضبوط', $html);
        $this->assertStringContainsString('يكفي تقريبًا', $html);
        $this->assertStringContainsString(config('disclaimers.sections.operational')['ar'], html_entity_decode($html));
    }

    // ── recipients outside the platform — the mail ───────────────────────────

    public function test_an_outside_recipient_gets_only_its_projects_with_no_link_into_the_platform_and_an_audit_row(): void
    {
        Mail::fake();
        $a = $this->project($this->tenant, 'Alpha');
        $b = $this->project($this->tenant, 'Beta');
        $this->spend($a, '2026-11-10', 200.0);
        $this->spend($b, '2026-11-10', 900.0);
        $this->limit($a, 1000.0);
        $r = $this->recipient($this->tenant, [(string) $a->id]);
        app(TenantContext::class)->forget();

        $this->assertSame('sent', app(SpendSummaryDispatcher::class)->sendExternal($r, Carbon::now('Asia/Riyadh')));

        Mail::assertSent(SpendSummaryMail::class, function (SpendSummaryMail $m) use ($r): bool {
            $html = $m->render();
            $this->assertTrue($m->hasTo($r->email));
            $this->assertStringContainsString('Alpha', $html);
            $this->assertStringNotContainsString('Beta', $html, 'only the bound project');
            $this->assertStringNotContainsString('/app/', $html, 'no link into the platform');
            $this->assertStringNotContainsString('افتح', $html, 'no «open the platform» button');
            $this->assertStringNotContainsString('إعدادات الإشعارات', $html, 'nothing that needs a login');
            $this->assertStringContainsString('/mail/unsubscribe/', $html, 'the one link is the unsubscribe');

            return true;
        });
        $this->assertDatabaseHas('audit_logs', ['action' => 'notifications.external_budget.sent', 'entity_id' => (string) $r->id, 'tenant_id' => (string) $this->tenant->id]);
        // Once per period: a second run sends nothing.
        $this->assertSame('already_sent', app(SpendSummaryDispatcher::class)->sendExternal($r, Carbon::now('Asia/Riyadh')));
    }

    public function test_one_click_unsubscribe_stops_the_next_send_without_a_login(): void
    {
        Mail::fake();
        $a = $this->project($this->tenant, 'Alpha');
        $r = $this->recipient($this->tenant, [(string) $a->id], 'daily');
        $url = app(MailUnsubscribes::class)->url((string) $this->tenant->id, $r->email, MailUnsubscribes::EXTERNAL);
        $this->assertNotNull($url);
        app(TenantContext::class)->forget();

        $this->post((string) parse_url((string) $url, PHP_URL_PATH))->assertSuccessful();

        $this->assertSame('unsubscribed', app(SpendSummaryDispatcher::class)->sendExternal($r, Carbon::now('Asia/Riyadh')));
        Mail::assertNothingSent();
    }

    // ── isolation and permissions ─────────────────────────────────────────

    public function test_a_project_of_another_workspace_cannot_be_bound_and_another_workspaces_recipient_is_not_visible(): void
    {
        [$other, $otherOwner] = $this->workspace('b');
        $theirs = $this->project($other, 'Theirs');
        $theirRecipient = $this->recipient($other, [(string) $theirs->id], 'weekly', 'pm@other.test');
        $mine = $this->project($this->tenant, 'Mine');
        app(TenantContext::class)->forget();

        $this->actingAs($this->owner, 'sanctum')->withHeader('X-Tenant-ID', (string) $this->tenant->id);
        $this->postJson('/api/v1/settings/notifications/external-recipients', ['email' => 'pm@client.test', 'cadence' => 'weekly', 'project_ids' => [(string) $theirs->id]])
            ->assertStatus(422);
        $this->assertSame(0, DB::table('external_digest_recipients')->where('tenant_id', $this->tenant->id)->count());

        $this->postJson('/api/v1/settings/notifications/external-recipients', ['email' => 'pm@client.test', 'cadence' => 'weekly', 'project_ids' => [(string) $mine->id]])
            ->assertCreated();
        $list = $this->getJson('/api/v1/settings/notifications/external-recipients')->assertOk()->json('data');
        $this->assertSame(['pm@client.test'], array_column($list, 'email'), 'only this workspace\'s recipients');
        $this->patchJson('/api/v1/settings/notifications/external-recipients/'.$theirRecipient->id, ['cadence' => 'daily'])->assertNotFound();
        $this->deleteJson('/api/v1/settings/notifications/external-recipients/'.$theirRecipient->id)->assertNotFound();
        $this->assertSame('weekly', ExternalDigestRecipient::query()->withoutGlobalScopes()->find($theirRecipient->id)->cadence);
        $this->assertDatabaseHas('audit_logs', ['action' => 'notifications.external_recipient.created']);
    }

    public function test_a_member_who_cannot_manage_settings_is_refused_an_invalid_address_is_refused_and_the_cap_holds(): void
    {
        [$t, $member] = $this->workspace('c', manage: false);
        $p = $this->project($t, 'P');
        app(TenantContext::class)->forget();
        $this->actingAs($member, 'sanctum')->withHeader('X-Tenant-ID', (string) $t->id)
            ->postJson('/api/v1/settings/notifications/external-recipients', ['email' => 'pm@client.test', 'cadence' => 'weekly', 'project_ids' => [(string) $p->id]])
            ->assertForbidden();

        $mine = $this->project($this->tenant, 'Mine');
        app(TenantContext::class)->forget();
        $this->actingAs($this->owner, 'sanctum')->withHeader('X-Tenant-ID', (string) $this->tenant->id);
        $this->postJson('/api/v1/settings/notifications/external-recipients', ['email' => 'not-an-address', 'cadence' => 'weekly', 'project_ids' => [(string) $mine->id]])->assertStatus(422);
        config()->set('mail.external_recipients_per_tenant', 1);
        $this->postJson('/api/v1/settings/notifications/external-recipients', ['email' => 'one@client.test', 'cadence' => 'weekly', 'project_ids' => [(string) $mine->id]])->assertCreated();
        $this->postJson('/api/v1/settings/notifications/external-recipients', ['email' => 'two@client.test', 'cadence' => 'weekly', 'project_ids' => [(string) $mine->id]])
            ->assertStatus(422)->assertJsonPath('errors.code', 'recipient_cap_reached');
    }

    /** A pivot row that somehow names another workspace's project is never followed: the send reads the recipient's own tenant only. */
    public function test_a_forged_binding_to_another_workspaces_project_never_reaches_the_mail(): void
    {
        Mail::fake();
        [$other] = $this->workspace('d');
        $theirs = $this->project($other, 'TheirSecret');
        $this->spend($theirs, '2026-11-10', 999.0);
        $mine = $this->project($this->tenant, 'Mine');
        $r = $this->recipient($this->tenant, [(string) $mine->id]);
        DB::table('external_digest_recipient_projects')->insert(['recipient_id' => $r->id, 'project_id' => $theirs->id, 'tenant_id' => $this->tenant->id]);
        app(TenantContext::class)->forget();

        app(SpendSummaryDispatcher::class)->sendExternal($r, Carbon::now('Asia/Riyadh'));

        Mail::assertSent(SpendSummaryMail::class, fn (SpendSummaryMail $m): bool => ! str_contains($m->render(), 'TheirSecret') && str_contains($m->render(), 'Mine'));
    }

    /** «أرسل تجربة لي» reaches the signed-in member only — never an outside address. */
    public function test_the_test_send_goes_to_the_member_only(): void
    {
        Mail::fake();
        $mine = $this->project($this->tenant, 'Mine');
        $this->recipient($this->tenant, [(string) $mine->id]);
        app(TenantContext::class)->forget();

        $this->actingAs($this->owner, 'sanctum')->withHeader('X-Tenant-ID', (string) $this->tenant->id)
            ->postJson('/api/v1/settings/notifications/external-recipients/test', ['cadence' => 'weekly'])->assertOk();

        Mail::assertSent(SpendSummaryMail::class, 1);
        Mail::assertSent(SpendSummaryMail::class, fn (SpendSummaryMail $m): bool => $m->hasTo($this->owner->email) && ! $m->hasTo('pm@client.test'));
    }

    // ── the sweep ────────────────────────────────────────────────────────────

    public function test_the_sweep_sends_each_due_recipient_once_and_the_workspace_weekly_only_to_who_switched_it_on(): void
    {
        Mail::fake();
        $a = $this->project($this->tenant, 'Alpha');
        $this->spend($a, '2026-11-12', 100.0);
        $this->recipient($this->tenant, [(string) $a->id], 'weekly', 'weekly@client.test');
        $this->recipient($this->tenant, [(string) $a->id], 'monthly', 'monthly@client.test');   // not the 1st: not due
        DB::table('notification_preferences')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => (string) $this->tenant->id, 'user_id' => $this->owner->id,
            'channels' => json_encode(['email' => true]), 'categories' => json_encode([]), 'digests' => json_encode(['spend_summary' => true]),
            'timezone' => 'Asia/Riyadh', 'locale' => 'ar', 'digest_hour' => 8, 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(TenantContext::class)->forget();

        $this->artisan('notifications:send-spend-summaries')->assertSuccessful();
        $this->artisan('notifications:send-spend-summaries')->assertSuccessful();

        Mail::assertSent(SpendSummaryMail::class, 2);
        Mail::assertSent(SpendSummaryMail::class, fn (SpendSummaryMail $m): bool => $m->external && $m->hasTo('weekly@client.test'));
        Mail::assertSent(SpendSummaryMail::class, fn (SpendSummaryMail $m): bool => ! $m->external && $m->hasTo($this->owner->email));
        Mail::assertNotSent(SpendSummaryMail::class, fn (SpendSummaryMail $m): bool => $m->hasTo('monthly@client.test'));
    }

    // ── review fixes ─────────────────────────────────────────────────────────

    /** A deleted project (soft delete keeps its status) is never mailed out again, whatever the pivot still says. */
    public function test_a_deleted_project_is_not_reported_to_an_outside_recipient(): void
    {
        Mail::fake();
        $kept = $this->project($this->tenant, 'Kept');
        $gone = $this->project($this->tenant, 'GoneProject');
        $this->spend($gone, '2026-11-10', 400.0);
        $r = $this->recipient($this->tenant, [(string) $kept->id, (string) $gone->id]);
        $gone->delete();
        app(TenantContext::class)->forget();

        $this->assertSame('sent', app(SpendSummaryDispatcher::class)->sendExternal($r, Carbon::now('Asia/Riyadh')));
        Mail::assertSent(SpendSummaryMail::class, fn (SpendSummaryMail $m): bool => ! str_contains($m->render(), 'GoneProject') && str_contains($m->render(), 'Kept'));
    }

    /** Spend in two currencies is not one amount: no sum, no unitless figure, outside the total; an unconverted limit is said to be outside the remaining total. */
    public function test_mixed_currency_spend_and_an_unconverted_limit_are_said_never_summed(): void
    {
        $mixed = $this->project($this->tenant, 'Mixed');
        $this->spend($mixed, '2026-11-10', 100.0, 'SAR');
        $this->spend($mixed, '2026-11-11', 100.0, 'USD');
        $held = $this->project($this->tenant, 'Held');
        $this->spend($held, '2026-11-10', null, 'SAR', 50.0);
        $this->limit($held, 1000.0);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        $s = app(ProjectSpendSummary::class)->build((string) $this->tenant->id, [(string) $mixed->id, (string) $held->id],
            Carbon::parse('2026-11-08'), Carbon::parse('2026-11-14')->endOfDay(), Carbon::parse('2026-11-15'));
        $by = array_column($s['projects'], null, 'name');

        $this->assertNull($by['Mixed']['spend'], 'SAR + USD is not one amount');
        $this->assertTrue($by['Mixed']['spend_withheld']);
        $this->assertNull($s['total']['spend']);
        $this->assertSame(1, $s['total']['cap_left_out']);
        $html = (new SpendSummaryMail($s, 'ar', 'weekly'))->render();
        $this->assertStringContainsString('بعض الحدود غير محوّلة، خارج المجموع', $html);
        $this->assertStringNotContainsString('200', $html);
    }

    public function test_an_address_already_added_in_another_case_is_a_422_not_a_500(): void
    {
        $mine = $this->project($this->tenant, 'Mine');
        app(TenantContext::class)->forget();
        $this->actingAs($this->owner, 'sanctum')->withHeader('X-Tenant-ID', (string) $this->tenant->id);
        $this->postJson('/api/v1/settings/notifications/external-recipients', ['email' => 'pm@client.test', 'cadence' => 'weekly', 'project_ids' => [(string) $mine->id]])->assertCreated();
        $this->postJson('/api/v1/settings/notifications/external-recipients', ['email' => ' PM@Client.test ', 'cadence' => 'weekly', 'project_ids' => [(string) $mine->id]])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    /**
     * What may be bound is the member's CEILING (every project they may open, with its money), never their own narrowed
     * digest preference. (`settings.manage` already carries every project capability in `ProjectAbilities`, so the money
     * filter in `bindable()` is defence in depth for a future change there.)
     */
    public function test_a_narrowed_own_digest_preference_does_not_limit_what_the_manager_may_bind(): void
    {
        $open = $this->project($this->tenant, 'Chosen');
        $other = $this->project($this->tenant, 'NotChosen');
        DB::table('notification_preferences')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => (string) $this->tenant->id, 'user_id' => $this->owner->id,
            'channels' => json_encode(['email' => true]), 'categories' => json_encode([]), 'project_ids' => json_encode([(string) $open->id]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(TenantContext::class)->forget();
        $this->actingAs($this->owner, 'sanctum')->withHeader('X-Tenant-ID', (string) $this->tenant->id);

        $names = array_column($this->getJson('/api/v1/settings/notifications/external-recipients/projects')->assertOk()->json('data'), 'name');
        $this->assertEqualsCanonicalizing(['Chosen', 'NotChosen'], $names);
        $this->postJson('/api/v1/settings/notifications/external-recipients', ['email' => 'pm@client.test', 'cadence' => 'weekly', 'project_ids' => [(string) $other->id]])
            ->assertCreated();
    }

    /** A forced mid-week run is its own window and never takes the scheduled week's slot. */
    public function test_a_forced_mid_week_run_does_not_cancel_the_scheduled_weekly(): void
    {
        Mail::fake();
        $a = $this->project($this->tenant, 'Alpha');
        $this->recipient($this->tenant, [(string) $a->id], 'weekly', 'weekly@client.test');
        app(TenantContext::class)->forget();

        Carbon::setTestNow(Carbon::parse('2026-11-18 05:00:00', 'UTC'));   // Wednesday
        $this->artisan('notifications:send-spend-summaries', ['--force' => true])->assertSuccessful();
        Carbon::setTestNow(Carbon::parse('2026-11-22 05:00:00', 'UTC'));   // Sunday: 15–21 November is due
        $this->artisan('notifications:send-spend-summaries')->assertSuccessful();

        Mail::assertSent(SpendSummaryMail::class, 2);
        $this->assertSame(['W2026-11-11', 'W2026-11-15'], DB::table('external_digest_sends')->orderBy('period_key')->pluck('period_key')->all());
    }

    /** The unsubscribe page an outside recipient lands on links nowhere into the platform. */
    public function test_the_outside_recipients_unsubscribe_page_has_no_link_into_the_platform(): void
    {
        $url = app(MailUnsubscribes::class)->url((string) $this->tenant->id, 'pm@client.test', MailUnsubscribes::EXTERNAL);
        app(TenantContext::class)->forget();
        $path = (string) parse_url((string) $url, PHP_URL_PATH);

        $this->get($path)->assertOk()->assertDontSee('/app/', false);
        $this->post($path)->assertOk()->assertDontSee('/app/', false)->assertDontSee('حسابك');
    }
}
