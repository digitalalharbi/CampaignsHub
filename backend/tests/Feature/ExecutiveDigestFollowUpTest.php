<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\CRM\Models\Lead;
use App\Domains\Notifications\Mail\DailyDigestMail;
use App\Domains\Notifications\Services\DailyDigest;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EXECUTIVE-DAILY-DIGEST-001 — the digest says what happened AFTER the lead arrived.
 *
 * The digest could already answer «what was spent» and «what did it produce», and stopped exactly
 * where a lead-generation client's day begins. «40 leads» is not an outcome. Forty leads of which
 * eleven nobody has called is an outcome, and it is the one a manager reading an email at 8am is
 * actually asking about.
 *
 * ## The constraint that shapes every assertion here
 *
 * **No raw lead PII by default.** A digest reaches whatever inbox somebody subscribed with, through
 * a mail provider nobody in this product controls, and once a client's customer list is in an inbox
 * no permission change takes it back. So the email carries counts and rates and a link, and the last
 * test in this class reads the rendered HTML looking for the people — because a section that leaks a
 * name would pass every other test in this file.
 */
final class ExecutiveDigestFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'edg-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $workspace = ClientWorkspace::create(['name' => 'Client', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create([
            'client_workspace_id' => $workspace->id, 'name' => 'Lead generation', 'status' => 'active',
        ]);

        $this->user = User::create(['name' => 'M', 'email' => 'm-'.uniqid().'@t.test', 'password' => 'secret123']);
        Membership::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'portal' => 'agency', 'status' => 'active',
        ]);
    }

    /** @param array<string,mixed> $over */
    private function lead(string $name, array $over = []): Lead
    {
        $lead = new Lead;
        $lead->forceFill(array_merge([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => $name,
            'email' => Str::slug($name).'@buyer.test',
            'phone' => '+96650000'.random_int(1000, 9999),
            'source' => 'provider', 'status' => 'new', 'provider' => 'meta',
            'provider_lead_id' => (string) Str::uuid(),
            'received_at' => Carbon::parse('2026-08-20 09:00:00'),
            'created_at' => Carbon::parse('2026-08-20 09:00:00'),
        ], $over))->save();

        return $lead;
    }

    /** @return array<string,mixed> */
    private function digest(): array
    {
        return app(DailyDigest::class)->build(
            $this->user,
            (string) $this->tenant->id,
            [(string) $this->project->id],
            Carbon::parse('2026-08-20'),
        );
    }

    public function test_the_digest_reports_what_the_team_did_with_the_leads(): void
    {
        $this->lead('نورة');
        $this->lead('خالد', ['first_contact_at' => Carbon::parse('2026-08-20 09:20:00'), 'status' => 'contacted']);
        $this->lead('سارة', ['owner_id' => $this->user->id, 'assigned_at' => Carbon::parse('2026-08-20 09:05:00')]);

        $follow = $this->digest()['projects'][0]['follow_up'];

        $this->assertSame(3, $follow['received']);
        $this->assertSame(1, $follow['contacted']);
        $this->assertSame(2, $follow['not_contacted']);
    }

    /**
     * A day with leads and no recorded spend is still a day.
     *
     * Spend arrives through a sync that can lag by hours; a lead arrives the moment a form is
     * submitted. Gating the whole email on spend meant a morning with new leads and a late platform
     * sync sent nothing at all, and the reader heard about the leads a day later.
     */
    public function test_a_day_with_leads_and_no_spend_is_still_worth_sending(): void
    {
        $this->lead('نورة');

        $digest = $this->digest();

        $this->assertTrue($digest['sendable'], 'leads arrived and the digest refused to go out');
        $this->assertNull($digest['reason']);
    }

    /** A media-only client grows no section of zeroes it would then have to read past. */
    public function test_a_project_with_no_leads_has_no_follow_up_section(): void
    {
        $this->assertNull($this->digest()['projects'][0]['follow_up']);
    }

    /**
     * What needs a person is separated from what merely describes the day.
     *
     * A digest that lists every figure with equal weight is a digest nobody acts on.
     */
    public function test_the_things_that_need_a_person_are_named_as_such(): void
    {
        $this->lead('نورة');
        $this->lead('خالد');

        $kinds = array_column($this->digest()['projects'][0]['follow_up']['attention'], 'kind');

        $this->assertContains('unassigned_leads', $kinds);
        $this->assertContains('never_contacted', $kinds);
    }

    /**
     * **The constraint: no raw lead PII by default.**
     *
     * Read off the RENDERED email rather than the payload, because the payload is not what is
     * delivered — a template that helpfully listed «today's leads» would pass every assertion above
     * and still put a client's customers into an inbox.
     */
    public function test_the_rendered_email_carries_no_lead_identity(): void
    {
        $this->lead('نورة العتيبي');
        $this->lead('خالد الدوسري', ['first_contact_at' => Carbon::parse('2026-08-20 10:00:00')]);

        $html = (new DailyDigestMail($this->digest(), 'ar', 'Manager', 'daily'))->render();

        foreach (['نورة العتيبي', 'خالد الدوسري', 'buyer.test', '+96650'] as $identity) {
            $this->assertStringNotContainsString(
                $identity,
                $html,
                "the digest mailed lead identity: {$identity}",
            );
        }

        // And it did report the day, so the absence above is a redaction and not an empty section.
        $this->assertStringContainsString('متابعة العملاء المحتملين', $html);
    }

    /**
     * The team block names colleagues, and only when there is a team to compare.
     *
     * A staff member's name is not the client's customer data — blanking it would make the block
     * useless without protecting anybody. One owner is not a comparison, so the table stays away
     * until there are two.
     */
    public function test_the_team_block_appears_once_there_is_a_team(): void
    {
        $second = User::create(['name' => 'زميلة', 'email' => 'c-'.uniqid().'@t.test', 'password' => 'secret123']);

        $this->lead('نورة', ['owner_id' => $this->user->id, 'assigned_at' => Carbon::parse('2026-08-20 09:05:00')]);
        $this->lead('خالد', ['owner_id' => $second->id, 'assigned_at' => Carbon::parse('2026-08-20 09:06:00')]);

        $html = (new DailyDigestMail($this->digest(), 'ar', 'Manager', 'daily'))->render();

        $this->assertStringContainsString('زميلة', $html);
        $this->assertStringContainsString('حسب المسؤول', $html);

        // Still no lead identity, even with the team table on screen.
        $this->assertStringNotContainsString('نورة', $html);
    }

    /** A rate with no denominator is absent, never «0%» — they are different statements. */
    public function test_a_rate_nobody_could_measure_is_not_printed_as_zero(): void
    {
        $this->lead('نورة', ['status' => 'invalid']);

        $follow = $this->digest()['projects'][0]['follow_up'];

        $this->assertNull($follow['qualification_rate'], 'nobody was contacted; there is no rate to state');

        $html = (new DailyDigestMail($this->digest(), 'en', 'Manager', 'daily'))->render();
        $this->assertStringNotContainsString('Qualification rate', $html);
    }
}
