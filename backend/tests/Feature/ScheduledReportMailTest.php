<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Notifications\Mail\ScheduledReportMail;
use App\Domains\Notifications\Providers\LaravelMailProvider;
use App\Domains\Notifications\Support\MailDeliveryState;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportSchedule;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ScheduledReportDispatcher;
use App\Domains\Reports\Services\ScheduledReportMailer;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/** EMAIL-GAPS — internal members only until the Owner decides; a decision report; nothing old gets sent. */
final class ScheduledReportMailTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private Project $otherProject;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.provider.test',
            'mail.mailers.smtp.username' => 'operator-login',
            'providers.channels.email' => LaravelMailProvider::class,
            'reports.schedule_mail.enabled' => true,
        ]);
        Mail::fake();
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.Str::random(5), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id, 'name' => 'Store', 'status' => 'active']);
        $this->otherProject = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id, 'name' => 'Other', 'status' => 'active']);
    }

    /** @param  list<string>|null  $projects */
    private function member(string $email, ?array $projects = null, Portal $portal = Portal::App, ?Tenant $tenant = null): User
    {
        $tenant ??= $this->tenant;
        $user = User::create(['name' => 'Staff', 'email' => $email, 'password' => Hash::make('secret1234')]);
        app(GrantMembership::class)->execute(new MembershipGrant(user: $user, tenant: $tenant, portal: $portal, role: 'member', projectScopeIds: $projects ?? [(string) $this->project->id]));

        return $user;
    }

    /** @param  list<string>  $emails */
    private function runSchedule(array $emails, array $over = []): Report
    {
        $s = new ReportSchedule;
        $s->forceFill(array_merge([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => 'Weekly', 'type' => 'executive', 'frequency' => 'weekly', 'day' => 'sunday',
            'time' => '08:00', 'timezone' => 'Asia/Riyadh', 'audience' => 'client', 'language' => 'ar',
            'formats' => ['pdf'], 'recipients' => array_map(static fn (string $e): array => ['email' => $e, 'name' => 'N'], $emails),
            'active' => true, 'next_run_at' => Carbon::now()->subHour(),
        ], $over))->save();

        // Generation is not what is under test: its job is left out, and `finish()` writes what it would have.
        Bus::fake([GenerateReportJob::class]);

        return app(ScheduledReportDispatcher::class)->dispatchOne($s, Carbon::now());
    }

    private function finish(Report $report): void
    {
        Report::withoutGlobalScopes()->whereKey($report->id)->update([
            'status' => 'completed',
            'generated_at' => now(),
            'data' => json_encode([
                'currency' => 'SAR',
                'kpis' => ['spend' => 12345.67, 'cpa' => 46.94, 'roas' => 7.82, 'conversions' => 263, 'revenue' => 96500, 'clicks' => 15619, 'impressions' => 695281, 'reach' => 0],
                'reported' => ['spend' => true, 'conversions' => true, 'revenue' => true, 'clicks' => true, 'impressions' => true, 'reach' => false],
                'delta' => ['conversions' => 12.0, 'revenue' => -3.5, 'clicks' => 8.5],
                'campaigns' => [['name' => 'SECRET CAMPAIGN NAME', 'spend' => 12345.67]],
                'summary' => ['Spend of 12,345.67 SAR on SECRET CAMPAIGN NAME.'],
                'ads' => [
                    ['name' => 'Bundle Carousel', 'campaign_name' => 'SECRET CAMPAIGN NAME', 'impressions' => 9000, 'clicks' => 900, 'conversions' => 90, 'spend' => 5000],
                    ['name' => 'Static Offer', 'campaign_name' => 'SECRET CAMPAIGN NAME', 'impressions' => 9000, 'clicks' => 10, 'conversions' => 0, 'spend' => 5000],
                ],
                'recommendations' => [],
            ]),
        ]);
    }

    private function sweep(): int
    {
        return app(ScheduledReportMailer::class)->sendReady();
    }

    private function row(string $email): object
    {
        return DB::table('report_deliveries')->where('recipient', $email)->first();
    }

    public function test_both_switches_default_off_so_a_deploy_mails_nobody(): void
    {
        $file = require base_path('config/reports.php');
        $this->assertFalse($file['schedule_mail']['enabled']);
        $this->assertFalse($file['schedule_mail']['outside_recipients']);

        config(['reports.schedule_mail.enabled' => false]);
        $this->member('staff@a.test');
        $report = $this->runSchedule(['staff@a.test']);
        $this->finish($report);

        $this->assertSame(0, $this->sweep());
        Mail::assertNothingSent();
        // On an install with a working provider it does not claim to be waiting for credentials.
        $this->assertSame('suppressed', $this->row('staff@a.test')->status);
        $this->assertSame('schedule_mail_off', $this->row('staff@a.test')->reason);
    }

    /** Rows like these are already on Production. Switching the mail on must not turn them into a send. */
    public function test_rows_written_before_the_mail_existed_are_never_sent(): void
    {
        $this->member('staff@a.test');
        $report = $this->runSchedule(['staff@a.test']);
        $this->finish($report);
        DB::table('report_deliveries')->update(['status' => 'awaiting_provider_credentials']);

        $this->assertSame(0, $this->sweep());
        Mail::assertNothingSent();
    }

    public function test_an_internal_member_receives_it_once_and_the_row_says_accepted_never_delivered(): void
    {
        $this->member('staff@a.test');
        $report = $this->runSchedule(['staff@a.test']);
        $this->finish($report);

        $this->assertSame(1, $this->sweep());
        $this->assertSame(0, $this->sweep());

        Mail::assertSent(ScheduledReportMail::class, 1);
        $row = $this->row('staff@a.test');
        $this->assertSame('sent', $row->status);
        $ledger = DB::table('mail_deliveries')->where('dedup_key', $row->mail_dedup_key)->first();
        $this->assertSame('accepted', MailDeliveryState::of($ledger->status, $ledger->delivered_at));
        $this->assertNull($ledger->delivered_at);
    }

    public function test_several_formats_are_one_email_and_share_one_outcome(): void
    {
        $this->member('staff@a.test');
        $report = $this->runSchedule(['staff@a.test'], ['formats' => ['pdf', 'xlsx', 'csv']]);
        $this->finish($report);

        $this->sweep();

        Mail::assertSent(ScheduledReportMail::class, 1);
        $this->assertSame(['sent'], DB::table('report_deliveries')->pluck('status')->unique()->values()->all());
    }

    public function test_an_outside_recipient_is_refused_by_default_with_a_reason_and_no_link_is_minted(): void
    {
        $report = $this->runSchedule(['client@ext.test']);
        $this->finish($report);

        $this->sweep();

        Mail::assertNothingSent();
        $this->assertSame('suppressed', $this->row('client@ext.test')->status);
        $this->assertSame('outside_recipient_off', $this->row('client@ext.test')->reason);
        $this->assertSame(0, ReportShare::withoutGlobalScopes()->count());

        // A later decision does not reach back and send this one.
        config(['reports.schedule_mail.outside_recipients' => true]);
        $this->sweep();
        Mail::assertNothingSent();
    }

    public function test_a_member_of_another_tenant_is_an_outside_recipient_here(): void
    {
        $other = Tenant::create(['name' => 'B', 'slug' => 'b-'.Str::random(5), 'status' => 'active']);
        $this->member('theirs@b.test', [], Portal::App, $other);
        $report = $this->runSchedule(['theirs@b.test']);
        $this->finish($report);

        $this->sweep();

        Mail::assertNothingSent();
        $this->assertSame('outside_recipient_off', $this->row('theirs@b.test')->reason);
    }

    public function test_a_client_portal_user_is_an_outside_recipient(): void
    {
        $this->member('client@portal.test', null, Portal::ClientPortal);
        $report = $this->runSchedule(['client@portal.test']);
        $this->finish($report);

        $this->sweep();

        Mail::assertNothingSent();
        $this->assertSame('outside_recipient_off', $this->row('client@portal.test')->reason);
    }

    public function test_a_member_confined_to_another_project_is_refused_whatever_the_setting(): void
    {
        config(['reports.schedule_mail.outside_recipients' => true]);
        $this->member('other@a.test', [(string) $this->otherProject->id]);
        $report = $this->runSchedule(['other@a.test']);
        $this->finish($report);

        $this->sweep();

        Mail::assertNothingSent();
        $this->assertSame('outside_project_access', $this->row('other@a.test')->reason);
    }

    public function test_when_the_owner_allows_it_an_outsider_gets_a_new_safe_link_and_old_links_are_untouched(): void
    {
        config(['reports.schedule_mail.outside_recipients' => true]);
        $report = $this->runSchedule(['client@ext.test']);
        $this->finish($report);
        [$old] = app(ShareService::class)->create($report, ['hide_spend' => false], null);
        $before = $old->fresh()->getAttributes();

        $this->sweep();

        Mail::assertSent(ScheduledReportMail::class, fn (ScheduledReportMail $m): bool => str_contains($m->url, '/r/'));
        $this->assertSame($before, $old->fresh()->getAttributes());
        $new = ReportShare::withoutGlobalScopes()->where('id', '!=', $old->id)->firstOrFail();
        $this->assertTrue((bool) $new->hide_spend);
        $this->assertTrue((bool) $new->hide_campaign_names);
        $this->assertNotNull($new->expires_at);
    }

    /** A share link opens a report with no account, so that message may not sit in a queue payload. */
    public function test_a_message_carrying_a_share_link_is_never_queued(): void
    {
        config(['reports.schedule_mail.outside_recipients' => true]);
        $report = $this->runSchedule(['client@ext.test']);
        $this->finish($report);

        Queue::fake();
        $this->sweep();

        Queue::assertNothingPushed();
        Mail::assertSent(ScheduledReportMail::class, 1);
    }

    /** The permanent rules, read from the bytes that would leave: both languages, HTML and subject. */
    public function test_the_body_has_results_and_content_names_and_no_spend_or_campaign_name(): void
    {
        foreach (['ar', 'en'] as $language) {
            $this->member("staff-{$language}@a.test");
            $report = $this->runSchedule(["staff-{$language}@a.test"], ['language' => $language]);
            $this->finish($report);
            $this->sweep();

            Mail::assertSent(ScheduledReportMail::class, function (ScheduledReportMail $m) use ($language): bool {
                if ($m->lang !== $language) {
                    return false;
                }
                $html = $m->render();

                foreach (['12,345', '12345', 'SECRET CAMPAIGN', '46.94', '7.82', 'Spend', 'إنفاق', 'ROAS', 'CPA'] as $forbidden) {
                    $this->assertStringNotContainsString($forbidden, $html, "«{$forbidden}» reached a {$language} scheduled report email");
                }
                $this->assertStringContainsString('263', $html);
                $this->assertStringContainsString('96,500', $html);
                $this->assertStringContainsString('SAR', $html);
                $this->assertStringContainsString('Bundle Carousel', $html);
                $this->assertStringContainsString('Static Offer', $html);
                $this->assertStringStartsWith(($language === 'ar' ? 'كامبينز هَب' : 'CampaignsHub').' — ', (string) $m->envelope()->subject);

                return true;
            });
        }
    }

    public function test_figures_no_platform_reported_have_no_cell_and_a_decline_is_not_printed(): void
    {
        $this->member('staff@a.test');
        $report = $this->runSchedule(['staff@a.test'], ['language' => 'en']);
        $this->finish($report);
        $this->sweep();

        Mail::assertSent(ScheduledReportMail::class, function (ScheduledReportMail $m): bool {
            $labels = array_column($m->cards, 'label');
            $this->assertNotContains('Reach', $labels);
            $byLabel = array_column($m->cards, null, 'label');
            $this->assertSame('+12%', $byLabel['Results']['up']);
            $this->assertNull($byLabel['Revenue']['up']);

            return true;
        });
    }

    public function test_it_waits_for_the_report_and_says_so_when_the_report_fails(): void
    {
        $this->member('staff@a.test');
        $report = $this->runSchedule(['staff@a.test']);

        $this->sweep();
        Mail::assertNothingSent();
        $this->assertSame('queued', $this->row('staff@a.test')->status);

        Report::withoutGlobalScopes()->whereKey($report->id)->update(['status' => 'failed']);
        $this->sweep();

        Mail::assertNothingSent();
        $this->assertSame('failed', $this->row('staff@a.test')->status);
        $this->assertSame('report_failed', $this->row('staff@a.test')->reason);
    }

    public function test_a_report_that_never_finishes_does_not_leave_its_rows_waiting_for_ever(): void
    {
        $this->member('staff@a.test');
        $report = $this->runSchedule(['staff@a.test']);
        DB::table('reports')->where('id', $report->id)->update(['created_at' => now()->subDays(2)]);

        $this->sweep();

        $this->assertSame('report_failed', $this->row('staff@a.test')->reason);
    }

    public function test_an_unsubscribe_and_the_daily_limit_reach_the_row_a_screen_reads(): void
    {
        config(['mail.daily_limit_per_tenant' => 1]);
        $this->member('one@a.test');
        $this->member('two@a.test');
        $this->member('left@a.test');
        DB::table('mail_unsubscribes')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'recipient_hash' => hash('sha256', 'left@a.test'), 'scope' => 'scheduled_report']);

        $report = $this->runSchedule(['left@a.test', 'one@a.test', 'two@a.test']);
        $this->finish($report);
        $this->sweep();

        Mail::assertSent(ScheduledReportMail::class, 1);
        $this->assertSame('unsubscribed', $this->row('left@a.test')->status);
        $this->assertSame('sent', $this->row('one@a.test')->status);
        $this->assertSame('limit_reached', $this->row('two@a.test')->status);
    }

    /** The real command, as the scheduler runs it: no tenant, and the sweep clearing its context after itself. */
    public function test_the_command_dispatches_with_no_tenant_and_mails_on_a_later_run(): void
    {
        $this->member('staff@a.test');
        $s = new ReportSchedule;
        $s->forceFill([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => 'Weekly', 'type' => 'executive', 'frequency' => 'weekly', 'day' => 'sunday', 'time' => '08:00',
            'timezone' => 'Asia/Riyadh', 'audience' => 'client', 'language' => 'ar', 'formats' => ['pdf'],
            'recipients' => [['email' => 'staff@a.test']], 'active' => true, 'next_run_at' => Carbon::now()->subHour(),
        ])->save();
        app(TenantContext::class)->forget();

        Queue::fake();
        $this->artisan('reports:dispatch-scheduled')->assertSuccessful();
        Mail::assertNothingSent();
        $this->assertSame('queued', $this->row('staff@a.test')->status);

        $this->finish(Report::withoutGlobalScopes()->where('project_id', $this->project->id)->firstOrFail());
        $this->artisan('reports:dispatch-scheduled')->assertSuccessful();

        // A worker has the message now; the row waits with it rather than claiming anything.
        $this->assertSame('queued', $this->row('staff@a.test')->status);
        $this->assertNotNull($this->row('staff@a.test')->mail_dedup_key);
    }
}
