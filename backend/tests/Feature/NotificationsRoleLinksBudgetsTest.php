<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Funding\Models\ProjectFunding;
use App\Domains\Funding\Services\FundingNotifier;
use App\Domains\Metrics\Services\DeliveryState;
use App\Domains\Notifications\Engine\Content\CatalogMessageComposer;
use App\Domains\Notifications\Engine\Data\Delivery;
use App\Domains\Notifications\Engine\Data\Recipient;
use App\Domains\Notifications\Engine\Enums\Channel;
use App\Domains\Notifications\Engine\Enums\Priority;
use App\Domains\Notifications\Engine\Enums\Scope;
use App\Domains\Notifications\Services\DailyDigest;
use App\Domains\Notifications\Services\EnrolMemberNotifications;
use App\Domains\Notifications\Services\ProjectSpendSummary;
use App\Domains\Notifications\Support\MailDeepLinks;
use App\Domains\Notifications\Support\MailLinkContext;
use App\Domains\Notifications\Support\OperationalDigestModel;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\FundingFixtures;
use Tests\TestCase;

/**
 * Owner 2026-11-28 — the notifications review: who receives what, where its links go, which projects are told nothing, and that
 * every budget in a mail is the budgets board's own figure in SAR.
 *
 *  MAIL-LINK-RECIPIENT-001   links in the recipient's own portal and workspace, and only where they may open the page
 *  NOTIFY-STOPPED-001        a project that did not spend is told nothing (no budget notice, no digest)
 *  BUDGETS-ONE-SOURCE-001    budgets in mails = the approved budgets, SAR, quiet projects with money left included
 *  ENGINE-MAIL-VISUAL-001    an engine notice is a card with one button to its page, no reference number, for admin + finance
 *  NOTIFY-ROLE-DEFAULTS-001  default digests by role
 *  BUDGET-NOTICE-AUDIENCE-001 budget notices also reach the workspace administrators
 */
final class NotificationsRoleLinksBudgetsTest extends TestCase
{
    use FundingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-15 09:00:00', 'UTC'));
        $this->bootFunding();
    }

    protected function tearDown(): void
    {
        MailLinkContext::forget();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function inAgency(User $user): User
    {
        DB::table('memberships')->where('user_id', $user->id)->where('tenant_id', $this->tenant->id)->update(['portal' => 'agency']);

        return $user;
    }

    private function roleUser(string $slug, array $permissions): User
    {
        $role = Role::query()->firstOrCreate(['tenant_id' => $this->tenant->id, 'slug' => $slug], ['name' => $slug]);
        $role->givePermissionTo(...Permission::query()->whereIn('key', $permissions)->pluck('key')->all());
        $user = User::create(['name' => $slug.' '.uniqid(), 'email' => $slug.'-'.uniqid().'@a.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);

        return $user;
    }

    // ── MAIL-LINK-RECIPIENT-001 ───────────────────────────────────────────────

    public function test_an_agency_members_links_open_the_agency_portal_of_the_workspace_the_mail_is_about(): void
    {
        $project = $this->newProject();
        $member = $this->inAgency($this->owner());
        // A one-workspace reader's links carry no workspace; give this reader a second workspace so the link must name its own.
        $other = Tenant::create(['name' => 'Own agency', 'slug' => 'own-'.uniqid(), 'status' => 'active']);
        $this->assertStringNotContainsString('ws=', MailLinkContext::pathFor((int) $member->id, (string) $this->tenant->id, '/dashboard'));
        $this->grantMembership($member, $other, Portal::Agency);
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);

        MailLinkContext::enter($member, (string) $this->tenant->id);
        $report = MailDeepLinks::report((string) $project->id);
        $dashboard = MailDeepLinks::dashboard();
        $budgets = MailDeepLinks::budgets((string) $project->id);
        $rehomed = MailLinkContext::path('/app/alerts?event=x');
        MailLinkContext::forget();

        $this->assertStringContainsString('/agency/reports?project='.$project->id.'&ws='.$this->tenant->id, $report);
        $this->assertStringContainsString('/agency/dashboard?ws='.$this->tenant->id, $dashboard);
        $this->assertStringContainsString('/agency/budgets?project='.$project->id.'&ws='.$this->tenant->id, $budgets);
        $this->assertSame('/agency/alerts?event=x&ws='.$this->tenant->id, $rehomed);
        $this->assertStringNotContainsString('/app/', $report.$dashboard.$budgets);
        // Nobody addressed (a preview): the historical form, unchanged.
        $this->assertStringContainsString('/app/reports?project='.$project->id, MailDeepLinks::report((string) $project->id));
        $this->assertStringNotContainsString('ws=', MailDeepLinks::report((string) $project->id));
    }

    public function test_a_link_to_a_page_the_reader_may_not_open_is_not_drawn(): void
    {
        $project = $this->newProject();
        $this->spendRow($project, (string) Str::uuid(), 1, 100);
        $reader = $this->inAgency($this->userWith(['projects.view.all', 'clients.view_all', 'campaigns.view'])); // no reports, no budgets

        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        MailLinkContext::enter($reader, (string) $this->tenant->id);
        $digest = app(DailyDigest::class)->buildRange($reader, (string) $this->tenant->id, [(string) $project->id], Carbon::parse('2026-11-14'), Carbon::parse('2026-11-14')->endOfDay(), 'daily');
        $m = OperationalDigestModel::project($digest['projects'][0], 'ar', 1);
        MailLinkContext::forget();

        $this->assertNull($m['links']['report'], 'no «افتح التقرير» for a reader without reports');
        $this->assertNull($m['links']['budget'], 'no budgets link for a reader without budgets');
        $this->assertNotNull($m['links']['attention'], 'campaigns they may open keep their link');

        MailLinkContext::enterExternal((string) $this->tenant->id);
        $this->assertFalse(MailLinkContext::allows((string) $project->id, 'reports.view'), 'never a link into the product outside it');
    }

    // ── NOTIFY-STOPPED-001 ────────────────────────────────────────────────────

    public function test_a_project_that_did_not_spend_in_seven_days_gets_no_budget_notice_and_one_that_spent_does(): void
    {
        $quiet = $this->newProject('Quiet');
        $this->spendRow($quiet, (string) Str::uuid(), 20, 500);   // stopped twenty days ago
        $never = $this->newProject('Never');                       // never spent
        $spending = $this->newProject('Spending');
        $this->spendRow($spending, (string) Str::uuid(), 2, 150);

        $ids = app(DeliveryState::class)->spent([(string) $quiet->id, (string) $never->id, (string) $spending->id], '2026-11-09', '2026-11-15');
        $this->assertSame([(string) $spending->id], $ids);

        app()->forgetScopedInstances();
        Artisan::call('notifications:v2-internal-scan');
        // The fact opens its «set budget» task (creates_task) whoever its recipients are.
        $raised = DB::table('followup_tasks')->where('kind', 'set_budget')->pluck('project_id')->map(fn ($id) => (string) $id)->all();
        $this->assertContains((string) $spending->id, $raised);
        $this->assertNotContains((string) $quiet->id, $raised);
        $this->assertNotContains((string) $never->id, $raised);
    }

    public function test_the_strict_quiet_filter_drops_a_project_with_nothing_in_the_period(): void
    {
        $never = $this->newProject('Never');
        $state = app(DeliveryState::class);

        $this->assertSame([(string) $never->id], $state->watched([(string) $never->id], '2026-11-14', '2026-11-14'), 'the old rule kept it «setting up»');
        $this->assertSame([], $state->watched([(string) $never->id], '2026-11-14', '2026-11-14', strict: true));
    }

    // ── BUDGETS-ONE-SOURCE-001 ────────────────────────────────────────────────

    public function test_the_budgets_block_lists_every_budgeted_project_in_sar_quiet_ones_included_and_never_a_platform_budget(): void
    {
        $quiet = $this->newProject('Stopped with money left');
        $this->addBudget($quiet, 1_043, 10);
        $running = $this->newProject('Running');
        $this->spendRow($running, (string) Str::uuid(), 1, 400);
        $this->addBudget($running, 5_000, 10);
        $noBudget = $this->newProject('No budget');
        $this->spendRow($noBudget, (string) Str::uuid(), 1, 300, 'USD');

        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $list = app(DailyDigest::class)->budgetsFor((string) $this->tenant->id, [(string) $quiet->id, (string) $running->id, (string) $noBudget->id], Carbon::parse('2026-11-14')->endOfDay());
        $names = array_column($list, 'project_name');

        $this->assertContains('Stopped with money left', $names, 'a quiet project with money left is still shown');
        $this->assertContains('Running', $names);
        $this->assertNotContains('No budget', $names, 'a project with no approved budget has no budget in a mail');
        foreach ($list as $b) {
            $this->assertSame('SAR', $b['budget_month']['currency']);
        }

        $portfolio = OperationalDigestModel::portfolio(['projects' => [], 'budget_projects' => $list, 'days' => 1, 'date' => '2026-11-14', 'to_date' => '2026-11-14'], 'ar', 'daily');
        $this->assertNotNull($portfolio['budgets']['total'], 'one currency → one total');
        $this->assertNull($portfolio['budgets']['total_reason']);
        $this->assertSame('SAR', strtoupper((string) $portfolio['budgets']['total']['currency']));
        $this->assertEqualsWithDelta(1_043, (float) collect($portfolio['budgets']['rows'])->firstWhere('name', 'Stopped with money left')['remaining_value'], 0.01);
    }

    public function test_the_spend_summary_reads_the_approved_budget_in_sar(): void
    {
        $project = $this->newProject('Budgeted');
        $this->spendRow($project, (string) Str::uuid(), 2, 600);
        $this->addBudget($project, 2_000, 10);   // adding the budget reads its spend

        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $s = app(ProjectSpendSummary::class)->build((string) $this->tenant->id, [(string) $project->id], Carbon::parse('2026-11-08'), Carbon::parse('2026-11-14')->endOfDay(), Carbon::parse('2026-11-15'));
        $row = $s['projects'][0];

        $this->assertSame('budget', $row['source']);
        $this->assertSame('SAR', $row['cap_currency']);
        $this->assertEqualsWithDelta(2_000, $row['cap'], 0.01);
        $this->assertEqualsWithDelta(1_400, (float) $row['remaining'], 0.01);
        $this->assertSame('SAR', $row['spend_currency']);
    }

    // ── ENGINE-MAIL-VISUAL-001 ────────────────────────────────────────────────

    public function test_the_no_budget_notice_is_a_card_with_a_link_to_the_budgets_and_no_reference_number(): void
    {
        $project = $this->newProject('Spending');
        $admin = $this->inAgency($this->owner());
        $delivery = new Delivery(
            id: (string) Str::uuid(), reference: 'N-261128-ABCDEFGH', tenantId: (string) $this->tenant->id, eventKey: 'project.no_budget_for_period',
            scope: Scope::Internal, channel: Channel::Email, priority: Priority::Important,
            recipient: Recipient::user((int) $admin->id, (string) $admin->email, 'ar', 'admin'),
            projectId: (string) $project->id, subscriptionId: null,
            payload: ['project_name' => 'Spending', 'workspace_name' => 'SMARTCODE'], contentVersion: 1, attempts: 0,
        );

        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $message = app(CatalogMessageComposer::class)->compose($delivery);

        $this->assertStringContainsString('data-block="engine-card"', $message->html);
        $this->assertStringContainsString('data-viz="ring"', $message->html);
        $this->assertStringContainsString('/agency/budgets?project='.$project->id, $message->html);
        $this->assertStringContainsString('افتح الميزانيات', $message->html);
        $this->assertStringContainsString('سجّل ميزانية المشروع — المالية', $message->html);
        $this->assertStringNotContainsString('الرقم المرجعي', $message->html);
        $this->assertStringNotContainsString('N-261128-ABCDEFGH', $message->html.$message->text);
        $this->assertStringNotContainsString('|', $message->subject);
    }

    public function test_the_no_budget_notice_is_for_management_and_finance_never_the_project_manager(): void
    {
        $roles = config('notification_events.events')['project.no_budget_for_period']['default_roles'];

        $this->assertSame(['admin', 'finance'], $roles['agency']);
        $this->assertNotContains('project_manager', $roles['agency']);
        $this->assertNotContains('marketing', $roles['business']);
    }

    // ── NOTIFY-ROLE-DEFAULTS-001 ──────────────────────────────────────────────

    public function test_default_digests_follow_the_role(): void
    {
        $enrol = app(EnrolMemberNotifications::class);
        $owner = $this->owner();
        $pm = $this->roleUser('project-manager', ['projects.view', 'analytics.view']);
        $finance = $this->roleUser('finance', ['funding.view']);
        $other = $this->userWith(['projects.view']);

        $o = $enrol->defaultsFor($owner, (string) $this->tenant->id)['digests'];
        $p = $enrol->defaultsFor($pm, (string) $this->tenant->id)['digests'];
        $f = $enrol->defaultsFor($finance, (string) $this->tenant->id)['digests'];

        $this->assertTrue($o['daily'] && $o['per_project'], 'the administrator: the projects summary and one per project');
        $this->assertTrue($p['daily'] && $p['per_project'], 'the project manager: the same, narrowed to his projects by DigestScope');
        $this->assertTrue($f['daily'] && $f['weekly'] && $f['monthly'], 'finance: daily, weekly and month-end');
        $this->assertFalse($f['per_project'], 'finance never gets a performance mail per project');
        $this->assertSame(EnrolMemberNotifications::DEFAULT_DIGESTS, $enrol->defaultsFor($other, (string) $this->tenant->id)['digests']);
    }

    // ── BUDGET-NOTICE-AUDIENCE-001 ────────────────────────────────────────────

    public function test_budget_notices_also_reach_the_workspace_administrator(): void
    {
        $project = $this->newProject();
        $this->addBudget($project, 1_000, 5);
        $admin = $this->owner();
        $funding = ProjectFunding::query()->where('project_id', $project->id)->firstOrFail();

        $audience = (new \ReflectionMethod(FundingNotifier::class, 'audience'))->invoke(app(FundingNotifier::class), $funding, ['manager', 'admin', 'finance'], null);

        $this->assertContains((int) $admin->id, array_map(static fn (User $u): int => (int) $u->id, $audience));
    }

    // ── TEAM-MAIL-LOG-001 ─────────────────────────────────────────────────────

    public function test_the_delivery_log_names_each_mail_its_recipient_and_whether_they_are_inside_or_outside_the_workspace(): void
    {
        $owner = $this->owner();
        $project = $this->newProject('Fursato');
        $key = substr(str_replace('-', '', (string) $project->id), 0, 12);
        DB::table('digest_sends')->insert([
            ['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'user_id' => $this->finance->id, 'kind' => 'daily', 'period_key' => '2026-11-14',
                'status' => 'sent', 'attempts' => 1, 'sent_at' => now(), 'created_at' => now()->subMinutes(2), 'updated_at' => now()],
            ['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'user_id' => $owner->id, 'kind' => 'daily', 'period_key' => '2026-11-14~'.$key,
                'status' => 'sent', 'attempts' => 1, 'sent_at' => now(), 'created_at' => now()->subMinute(), 'updated_at' => now()],
        ]);
        $recipient = (string) Str::uuid();
        DB::table('external_digest_recipients')->insert(['id' => $recipient, 'tenant_id' => $this->tenant->id, 'email' => 'pm@client.test', 'cadence' => 'weekly', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('external_digest_sends')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'recipient_id' => $recipient, 'period_key' => 'W2026-11-08',
            'status' => 'sent', 'projects' => 1, 'sent_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $rows = collect($this->actingAs($owner, 'sanctum')->getJson('/api/v1/settings/notifications/deliveries')->assertOk()->json('data'));

        $summary = $rows->firstWhere('recipient', $this->finance->email);
        $this->assertSame('ملخص المشاريع : أمس', $summary['label']);
        $this->assertSame('internal', $summary['audience']);
        $perProject = $rows->firstWhere('recipient', $owner->email);
        $this->assertSame('ملخص المشروع : أمس', $perProject['label']);
        $this->assertSame('Fursato', $perProject['project']);
        $outside = $rows->firstWhere('recipient', 'pm@client.test');
        $this->assertSame('external', $outside['audience']);
    }
}
