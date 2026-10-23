<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Accounts\Capabilities\Capabilities;
use App\Domains\Accounts\Capabilities\CapabilitySwitch;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\CRM\Actions\AdvanceLead;
use App\Domains\CRM\Enums\LeadStage;
use App\Domains\CRM\Models\Lead;
use App\Domains\Forms\Models\LeadForm;
use App\Domains\Projects\Models\Project;
use App\Domains\ShortLinks\Models\ShortLink;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * FORMS-DASHBOARDS-001 — a form's and a link's funnel: the visit from the event log, the leads from the Leads Centre, and
 * never a figure whose input is missing.
 */
final class LeadFormFunnelReportTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    private const SPA = ['Origin' => 'http://localhost:5173'];

    private Tenant $tenant;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active', 'enabled_modules' => ['paid_media', 'advanced_tracking']]);
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
        $client = ClientWorkspace::create(['name' => 'Client', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
        app(TenantContext::class)->forget();
    }

    private function as()
    {
        return $this->actingAs($this->owner, 'sanctum')->withHeaders(self::SPA);
    }

    private function tracking(bool $on): void
    {
        CapabilitySwitch::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'scope_type' => 'tenant', 'scope_key' => $this->tenant->id, 'module' => 'advanced_tracking'],
            ['scope_id' => null, 'enabled' => $on],
        );
        app(Capabilities::class)->bump((string) $this->tenant->id);
    }

    /** @return array{0: string, 1: string} [slug, form id] */
    private function form(): array
    {
        $definition = [
            'steps' => [
                ['id' => 's1', 'title' => 'You', 'fields' => [
                    ['id' => 'full_name', 'type' => 'text', 'label' => 'Name', 'maps_to' => 'name'],
                    ['id' => 'mobile', 'type' => 'phone', 'label' => 'Mobile', 'maps_to' => 'phone'],
                ]],
                ['id' => 's2', 'title' => 'More', 'fields' => [['id' => 'city', 'type' => 'text', 'label' => 'City']]],
            ],
            'consent' => ['required' => true, 'text' => ['en' => 'Contact me.']],
        ];
        $id = $this->as()->postJson("/api/v1/projects/{$this->project->id}/forms", ['name' => 'Villa enquiry', 'definition' => $definition])->assertCreated()->json('data.id');
        $this->as()->postJson("/api/v1/projects/{$this->project->id}/forms/{$id}/publish")->assertOk();

        return [(string) LeadForm::withoutGlobalScopes()->findOrFail($id)->slug, (string) $id];
    }

    private function visit(string $slug, string $session, array $events): void
    {
        foreach ($events as [$event, $step]) {
            $this->postJson("/api/v1/public/forms/{$slug}/events", ['event' => $event, 'event_id' => "e-{$session}-{$event}-".($step ?? 0), 'session_id' => $session, 'step' => $step])->assertNoContent();
        }
    }

    private function submit(string $slug, string $n, array $attribution = []): void
    {
        $this->postJson("/api/v1/public/forms/{$slug}/submissions", [
            'submission_id' => 'sub-'.str_pad($n, 16, '0', STR_PAD_LEFT),
            'answers' => ['full_name' => "Person {$n}", 'mobile' => '05000012'.str_pad($n, 2, '0', STR_PAD_LEFT), 'city' => 'Riyadh'],
            'consent' => ['accepted' => true],
            'attribution' => $attribution,
        ])->assertCreated();
    }

    public function test_the_visit_drop_off_and_the_leads_are_read_from_their_own_sources(): void
    {
        $this->tracking(true);
        [$slug, $id] = $this->form();

        // Four sessions view; three start; two finish step 1; one reaches step 2 and sends.
        foreach (['ses-aaaaaaaa', 'ses-bbbbbbbb', 'ses-cccccccc', 'ses-dddddddd'] as $s) {
            $this->visit($slug, $s, [['form_view', null]]);
        }
        foreach (['ses-aaaaaaaa', 'ses-bbbbbbbb', 'ses-cccccccc'] as $s) {
            $this->visit($slug, $s, [['form_start', null]]);
        }
        foreach (['ses-aaaaaaaa', 'ses-bbbbbbbb'] as $s) {
            $this->visit($slug, $s, [['step_completed', 1]]);
        }
        $this->visit($slug, 'ses-aaaaaaaa', [['step_completed', 2], ['submit_attempt', 2]]);
        $this->submit($slug, '1');
        $this->submit($slug, '2');

        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $first = Lead::query()->orderBy('created_at')->firstOrFail();
        $advance = app(AdvanceLead::class);
        $first = $advance->execute($first, LeadStage::Assigned);
        $first = $advance->execute($first, LeadStage::Contacted);
        $first = $advance->execute($first, LeadStage::Qualified);
        $first = $advance->execute($first, LeadStage::Appointment);
        $first->forceFill(['estimated_value' => 40000, 'currency' => 'SAR'])->save();
        $advance->execute($first, LeadStage::Won);
        app(TenantContext::class)->forget();

        $r = $this->as()->getJson("/api/v1/projects/{$this->project->id}/forms/{$id}/funnel")->assertOk()->json('data');

        $this->assertSame('measured', $r['visit']['state']);
        $this->assertSame('session', $r['visit']['unit']);
        $this->assertSame([4, 3, 1], [$r['visit']['views'], $r['visit']['starts'], $r['visit']['attempts']]);
        $this->assertSame([['step' => 1, 'reached' => 3, 'completed' => 2, 'left' => 1], ['step' => 2, 'reached' => 2, 'completed' => 1, 'left' => 1]], $r['visit']['steps']);
        $this->assertSame(0.75, $r['visit']['rates']['view_to_start']);

        $this->assertSame(2, $r['leads']['submissions']);
        $this->assertSame(2, $r['leads']['received']);
        $this->assertSame(['contacted' => 1, 'qualified' => 1, 'appointment' => 1, 'won' => 1], array_intersect_key($r['leads'], array_flip(['contacted', 'qualified', 'appointment', 'won'])));
        $this->assertSame(0.5, $r['rates']['lead_to_won']);
        $this->assertEquals(['SAR' => 40000.0], $r['won_estimated_value']);
        $this->assertNull($r['cost']['cost_per_lead'], 'No spend is linked to a form, so no cost per lead is invented.');
    }

    public function test_machines_are_left_out_of_the_visit(): void
    {
        $this->tracking(true);
        [$slug, $id] = $this->form();
        $this->withHeaders(['User-Agent' => 'facebookexternalhit/1.1'])->postJson("/api/v1/public/forms/{$slug}/events", ['event' => 'form_view', 'event_id' => 'e-bot-00000001', 'session_id' => 'ses-botbotbot'])->assertNoContent();
        // withHeaders is sticky on the test client: the next visitor is a person on a phone.
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/604.1']);
        $this->visit($slug, 'ses-realreal', [['form_view', null]]);

        $r = $this->as()->getJson("/api/v1/projects/{$this->project->id}/forms/{$id}/funnel")->assertOk()->json('data');

        $this->assertSame(1, $r['visit']['views']);
    }

    public function test_a_project_that_never_recorded_a_visit_gets_no_visit_numbers_but_its_leads_are_still_counted(): void
    {
        [$slug, $id] = $this->form();   // tracking never switched on
        $this->submit($slug, '1');

        $r = $this->as()->getJson("/api/v1/projects/{$this->project->id}/forms/{$id}/funnel")->assertOk()->json('data');

        $this->assertSame('tracking_off', $r['visit']['state']);
        $this->assertArrayNotHasKey('views', $r['visit']);
        $this->assertSame(1, $r['leads']['received']);
        $this->assertNotNull($r['rates']['lead_to_won']);
        $this->assertEquals(0, $r['rates']['lead_to_won'], 'A measured zero: one lead, none won.');
    }

    public function test_a_rate_without_a_denominator_is_null_not_zero(): void
    {
        [, $id] = $this->form();

        $r = $this->as()->getJson("/api/v1/projects/{$this->project->id}/forms/{$id}/funnel")->assertOk()->json('data');

        $this->assertSame(0, $r['leads']['received']);
        $this->assertNull($r['rates']['lead_to_won']);
        $this->assertNull($r['rates']['lead_to_contacted']);
    }

    public function test_the_same_person_twice_is_two_submissions_and_one_person(): void
    {
        [$slug, $id] = $this->form();
        $this->submit($slug, '1');
        $this->postJson("/api/v1/public/forms/{$slug}/submissions", [
            'submission_id' => 'sub-'.str_pad('2', 16, '0', STR_PAD_LEFT), 'answers' => ['full_name' => 'Person 1', 'mobile' => '0500001201'], 'consent' => ['accepted' => true],
        ])->assertCreated();

        $r = $this->as()->getJson("/api/v1/projects/{$this->project->id}/forms/{$id}/funnel")->assertOk()->json('data');

        $this->assertSame(2, $r['leads']['received']);
        $this->assertSame(1, $r['leads']['people']);
    }

    public function test_a_link_is_credited_only_with_the_leads_that_arrived_carrying_its_tag(): void
    {
        $this->tracking(true);
        [$slug] = $this->form();
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $link = ShortLink::create(['slug' => 'villas', 'kind' => 'link', 'destination' => 'https://example.test', 'utm' => ['utm_source' => 'meta', 'utm_campaign' => 'q4-villas'], 'is_active' => true]);
        $other = ShortLink::create(['slug' => 'plain', 'kind' => 'link', 'destination' => 'https://example.test', 'is_active' => true]);
        app(TenantContext::class)->forget();

        $this->submit($slug, '1', ['utm_source' => 'meta', 'utm_campaign' => 'q4-villas']);
        $this->submit($slug, '2', ['utm_source' => 'meta', 'utm_campaign' => 'q4-villas']);
        $this->submit($slug, '3', ['utm_source' => 'tiktok', 'utm_campaign' => 'q4-villas']);
        $this->submit($slug, '4', ['utm_source' => 'meta', 'utm_campaign' => 'other']);

        $r = $this->as()->getJson("/api/v1/projects/{$this->project->id}/links/{$link->id}/funnel")->assertOk()->json('data');
        $this->assertTrue($r['credited']);
        $this->assertSame(2, $r['leads']['received']);
        $this->assertStringContainsString('utm_campaign', $r['attribution_rule']);

        $none = $this->as()->getJson("/api/v1/projects/{$this->project->id}/links/{$other->id}/funnel")->assertOk()->json('data');
        $this->assertFalse($none['credited']);
        $this->assertSame(0, $none['leads']['received']);
        $this->assertNull($none['click_to_lead_rate']);
        $this->assertStringContainsString('no utm_campaign', $none['attribution_rule']);
    }

    public function test_the_funnel_needs_the_permission_stays_in_its_project_and_bounds_the_range(): void
    {
        [, $id] = $this->form();
        $viewer = User::create(['name' => 'V', 'email' => uniqid().'@v.test', 'password' => 'secret123']);
        $this->grantMembership($viewer, $this->tenant);

        $this->actingAs($viewer, 'sanctum')->withHeaders(self::SPA)->getJson("/api/v1/projects/{$this->project->id}/forms/{$id}/funnel")->assertForbidden();
        $this->as()->getJson("/api/v1/projects/{$this->project->id}/forms/".'00000000-0000-0000-0000-000000000000'.'/funnel')->assertNotFound();
        $this->as()->getJson("/api/v1/projects/{$this->project->id}/forms/{$id}/funnel?from=2020-01-01&to=2026-10-01")->assertStatus(422);
        $this->as()->getJson("/api/v1/projects/{$this->project->id}/forms/{$id}/funnel?from=nonsense")->assertStatus(422);
    }
}
