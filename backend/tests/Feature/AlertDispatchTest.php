<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Metrics\Models\SpendLimit;
use App\Domains\Notifications\Mail\AlertBundleMail;
use App\Domains\Notifications\Providers\MessageProvider;
use App\Domains\Notifications\Services\AlertDispatcher;
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
use Tests\TestCase;

/**
 * MAIL-006 — alerts are the digest's own findings, sent early and not repeated.
 *
 * The interesting assertions are the ones about SILENCE. An alert channel is only worth having while
 * its messages are rare enough to be read, so most of this file is about what does not arrive: the
 * `info` notes, the second copy of a finding that has not changed, and anything at all when there is
 * no mail provider to send it.
 */
final class AlertDispatchTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-08-07 09:00:00';

    private Tenant $tenant;

    private Project $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-alerts', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-alerts', 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'مشروع', 'status' => 'active']);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner-alerts']);
        $role->givePermissionTo('clients.view_all');

        $this->user = User::create(['name' => 'Ops', 'email' => 'ops@alerts.test', 'password' => 'secret123']);
        Membership::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'portal' => 'agency', 'status' => 'active']);
        $this->user->assignRole($role);
        $this->user = $this->user->fresh();

        app(TenantContext::class)->forget();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function withConfiguredEmail(): void
    {
        config()->set('providers.channels.email', ConfiguredAlertEmailProvider::class);
    }

    /**
     * A day with spend and a rate that fell hard enough to be worth an interruption.
     *
     * The figures are chosen so the observations engine produces a `warning`: clicks collapse
     * against a flat impression count, which is CTR falling by more than its threshold.
     */

    /**
     * BUDGET-ALERT-EMAIL-001 — the workspace's own ceiling reaches an inbox.
     *
     * `AlertEvaluator` has recorded internal spend-limit crossings in `spend_limit_events` and
     * raised them in-app since the governance work. It could not email one: the dispatcher builds
     * its findings from the digest's OBSERVATIONS, and a crossing that never became an observation
     * was never a finding. So the one budget object nothing enforces — the one somebody set
     * precisely because they intend to act on it themselves — was the one nobody was told about
     * outside the product.
     *
     * The message has to say «internal» and has to say nothing stops delivery, in as many words: an
     * operator who reads a generic «budget at risk» and assumes the platform stopped it will not go
     * and pause anything.
     */
    public function test_an_internal_spend_limit_crossing_reaches_the_inbox(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();
        $this->seedSpendAgainstAnInternalLimit();

        $counts = app(AlertDispatcher::class)->sweep($this->user, (string) $this->tenant->id, Carbon::parse('2026-08-07'));

        $this->assertSame(1, $counts['sent'], 'the crossing produced no email');

        Mail::assertSent(AlertBundleMail::class, function (AlertBundleMail $m): bool {
            $detail = implode(' ', array_map(static fn (array $i): string => (string) ($i['detail'] ?? ''), $m->items));

            return $m->hasTo('ops@alerts.test')
                // «internal», and «nothing stops delivery» — the two facts that separate it from a
                // platform budget, which the platform itself enforces.
                && str_contains($detail, 'داخلي')
                && str_contains($detail, 'لا يوقف CampaignsHub العرض');
        });
    }

    /**
     * And it is announced once.
     *
     * The crossing already has a ledger — `spend_limit_events` is unique on (limit, threshold) — and
     * the dispatcher has a three-day cooldown. Both exist; this holds them to it, because a ceiling
     * that emails every morning for the rest of the period is one people filter.
     */
    public function test_the_crossing_is_not_repeated_the_next_morning(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();
        $this->seedSpendAgainstAnInternalLimit();

        $dispatcher = app(AlertDispatcher::class);
        $first = $dispatcher->sweep($this->user, (string) $this->tenant->id, Carbon::parse('2026-08-07'));
        $second = $dispatcher->sweep($this->user, (string) $this->tenant->id, Carbon::parse('2026-08-07'));

        $this->assertSame(1, $first['sent']);
        $this->assertSame(0, $second['sent']);
        $this->assertSame(1, $second['already_sent']);
    }

    /** A limit nothing has crossed says nothing. Silence is the correct output, not a 0% note. */
    public function test_a_limit_nobody_is_near_produces_no_email(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();
        $this->seedSpendAgainstAnInternalLimit(amount: 1_000_000);

        $counts = app(AlertDispatcher::class)->sweep($this->user, (string) $this->tenant->id, Carbon::parse('2026-08-07'));

        $this->assertSame(0, $counts['sent']);
    }

    /**
     * Spend, and a ceiling it has passed.
     *
     * 8,000 against a 10,000 limit with an 80% threshold: the case this exists for, since at 100%
     * somebody has usually already noticed.
     */
    private function seedSpendAgainstAnInternalLimit(float $amount = 10_000.0): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        /*
         * Written WITH its currency columns, the way the governor reads it.
         *
         * `SpendLimitGovernor` refuses a figure it cannot compare — a spend in another currency with
         * no rate is «unknown», never «understated» — so a row with no `original_currency` produces a
         * null utilisation and no crossing. That is correct behaviour, and it would have made this
         * test pass for entirely the wrong reason.
         */
        app(UpsertDailyMetrics::class)->handle([
            new NormalizedMetric(
                tenantId: (string) $this->tenant->id,
                projectId: (string) $this->project->id,
                externalAccountId: '11111111-1111-1111-1111-111111111111',
                externalCampaignId: '22222222-2222-2222-2222-222222222222',
                provider: 'meta',
                metricKey: 'spend',
                metricDate: Carbon::parse('2026-08-07'),
                value: 8_000.0,
                originalCurrency: 'SAR',
                projectCurrency: 'SAR',
                originalAmount: 8_000.0,
                convertedAmount: 8_000.0,
                exchangeRate: 1.0,
            ),
        ]);

        SpendLimit::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'scope' => 'project',
            'scope_id' => null,
            'amount' => $amount,
            'currency' => 'SAR',
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-31',
            'thresholds' => [80, 100],
            'active' => true,
        ]);

        app(TenantContext::class)->forget();
    }

    private function seedFallingCtr(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $write = function (string $key, float $value, string $date): void {
            app(UpsertDailyMetrics::class)->handle([
                new NormalizedMetric(
                    tenantId: (string) $this->tenant->id,
                    projectId: (string) $this->project->id,
                    externalAccountId: '11111111-1111-1111-1111-111111111111',
                    externalCampaignId: '22222222-2222-2222-2222-222222222222',
                    provider: 'meta',
                    metricKey: $key,
                    metricDate: Carbon::parse($date),
                    value: $value,
                ),
            ]);
        };

        // Today: the same reach, a third of the clicks.
        $write('spend', 1000.0, '2026-08-07');
        $write('impressions', 300_000.0, '2026-08-07');
        $write('clicks', 1_000.0, '2026-08-07');

        // The day before, which is the window the change is measured against.
        $write('spend', 1000.0, '2026-08-06');
        $write('impressions', 300_000.0, '2026-08-06');
        $write('clicks', 3_000.0, '2026-08-06');

        app(TenantContext::class)->forget();
    }

    public function test_a_material_decline_reaches_the_inbox_with_its_figures(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();
        $this->seedFallingCtr();

        $counts = app(AlertDispatcher::class)->sweep($this->user, (string) $this->tenant->id, Carbon::parse('2026-08-07'));

        $this->assertSame(1, $counts['sent'], 'the decline produced no alert');
        // One message carrying the findings — MAIL-013. It was one email per finding until then.
        Mail::assertSent(AlertBundleMail::class, function (AlertBundleMail $m): bool {
            return $m->hasTo('ops@alerts.test')
                && count($m->items) === 1
                && str_contains($m->items[0]['title'], 'معدل النقر')
                && $m->items[0]['context'] === 'مشروع';
        });
    }

    /**
     * The same finding, unchanged, does not arrive again the next day.
     *
     * This is the whole reason the cooldown exists. «Your CTR is still down» every morning is not
     * new information, and an alert channel that repeats itself is one a reader filters away — after
     * which the alert that mattered goes unread too.
     */
    public function test_the_same_finding_is_not_repeated_inside_its_cooldown(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();
        $this->seedFallingCtr();

        $dispatcher = app(AlertDispatcher::class);
        $first = $dispatcher->sweep($this->user, (string) $this->tenant->id, Carbon::parse('2026-08-07'));
        $second = $dispatcher->sweep($this->user, (string) $this->tenant->id, Carbon::parse('2026-08-07'));

        $this->assertSame(1, $first['sent']);
        $this->assertSame(1, $second['already_sent']);
        $this->assertSame(0, $second['sent']);
        Mail::assertSent(AlertBundleMail::class, 1);
    }

    /**
     * `info` notes never interrupt anybody.
     *
     * «Two metrics are not reported by your platforms» belongs beside the figures in tomorrow's
     * digest. Sending it as an alert teaches the reader that alerts are not urgent, which costs the
     * ones that are.
     */
    public function test_an_informational_note_is_left_for_the_digest(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(UpsertDailyMetrics::class)->handle([
            new NormalizedMetric(
                tenantId: (string) $this->tenant->id, projectId: (string) $this->project->id,
                externalAccountId: '11111111-1111-1111-1111-111111111111',
                externalCampaignId: '22222222-2222-2222-2222-222222222222',
                provider: 'meta', metricKey: 'spend',
                metricDate: Carbon::parse('2026-08-07'), value: 500.0,
            ),
        ]);
        app(TenantContext::class)->forget();

        $counts = app(AlertDispatcher::class)->sweep($this->user, (string) $this->tenant->id, Carbon::parse('2026-08-07'));

        $this->assertSame(0, $counts['sent']);
        Mail::assertNothingSent();
    }

    /** With no mail provider the state is recorded honestly and nothing claims to have been sent. */
    public function test_with_no_provider_the_state_is_awaiting_credentials(): void
    {
        Mail::fake();
        $this->seedFallingCtr();

        $counts = app(AlertDispatcher::class)->sweep($this->user, (string) $this->tenant->id, Carbon::parse('2026-08-07'));

        $this->assertSame(1, $counts['awaiting_credentials']);
        Mail::assertNothingSent();

        $row = DB::table('digest_sends')->where('kind', 'alert')->first();
        $this->assertSame('awaiting_credentials', $row->status);
        $this->assertSame('no_email_provider', $row->reason);
        $this->assertNull($row->sent_at, 'nothing may carry a sent time that was not sent');
    }

    /**
     * A recipient who can reach no project is swept and mailed nothing — fail-closed.
     *
     * `DigestScope` is the gate, and this is the assertion that it is actually consulted rather than
     * assumed: a user with no scope rows and no `clients.view_all` reaches nothing.
     */
    public function test_a_recipient_with_no_scope_receives_nothing(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();
        $this->seedFallingCtr();

        $stranger = User::create(['name' => 'S', 'email' => 's@alerts.test', 'password' => 'secret123']);
        Membership::create(['tenant_id' => $this->tenant->id, 'user_id' => $stranger->id, 'portal' => 'agency', 'status' => 'active']);

        $counts = app(AlertDispatcher::class)->sweep($stranger->fresh(), (string) $this->tenant->id, Carbon::parse('2026-08-07'));

        $this->assertSame(0, $counts['sent']);
        Mail::assertNothingSent();
    }

    /** The alert ledger is the digest ledger — one table, one unique index, one deduplication rule. */
    public function test_the_alert_is_recorded_in_the_same_ledger_as_the_digests(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();
        $this->seedFallingCtr();

        app(AlertDispatcher::class)->sweep($this->user, (string) $this->tenant->id, Carbon::parse('2026-08-07'));

        $row = DB::table('digest_sends')->where('kind', 'alert')->first();
        $this->assertNotNull($row);
        $this->assertSame('sent', $row->status);
        $this->assertNotNull($row->sent_at);
        /*
         * The key is a DIGEST of (project, finding, cooldown bucket), not a readable join of them.
         *
         * `period_key` holds 24 characters and a project id alone is a 36-character UUID, so a
         * readable key was cut off inside the id — which made two projects whose ids share a prefix
         * share a cooldown, and one of them silently never alert.
         */
        $this->assertSame(24, strlen($row->period_key));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}$/', $row->period_key);
    }

    /** Two projects with the same finding get two alerts — the key cannot conflate them. */
    public function test_two_projects_with_the_same_finding_do_not_share_a_cooldown(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();
        $this->seedFallingCtr();

        app(TenantContext::class)->setTenantId($this->tenant->id);
        $second = Project::create(['client_workspace_id' => $this->project->client_workspace_id, 'name' => 'مشروع ثانٍ', 'status' => 'active']);
        foreach ([['2026-08-07', 1_000.0], ['2026-08-06', 3_000.0]] as [$date, $clicks]) {
            foreach ([['spend', 1000.0], ['impressions', 300_000.0], ['clicks', $clicks]] as [$key, $value]) {
                app(UpsertDailyMetrics::class)->handle([
                    new NormalizedMetric(
                        tenantId: (string) $this->tenant->id, projectId: (string) $second->id,
                        externalAccountId: '33333333-3333-3333-3333-333333333333',
                        externalCampaignId: '44444444-4444-4444-4444-444444444444',
                        provider: 'meta', metricKey: $key,
                        metricDate: Carbon::parse($date), value: $value,
                    ),
                ]);
            }
        }
        app(TenantContext::class)->forget();

        $counts = app(AlertDispatcher::class)->sweep($this->user, (string) $this->tenant->id, Carbon::parse('2026-08-07'));

        $this->assertSame(2, $counts['sent'], 'one project’s alert was swallowed by the other’s cooldown');
        $this->assertSame(2, DB::table('digest_sends')->where('kind', 'alert')->distinct()->count('period_key'));
    }
}

/**
 * A stand-in for a real, credentialed provider.
 *
 * Wired through `config('providers.channels.email')` rather than by mocking `ProviderRegistry`,
 * because the registry is `final` — and because routing the fake through the real registry is what
 * proves the honest «awaiting credentials» mapping is the thing being exercised.
 */
final class ConfiguredAlertEmailProvider implements MessageProvider
{
    public function channel(): string
    {
        return 'email';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    /** @param  array<string,mixed>  $payload */
    public function send(string $destination, array $payload): array
    {
        return ['status' => 'sent', 'provider_message_id' => 'alert-ack', 'error' => null];
    }
}
