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
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tracking\Models\TrackingEvent;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * FORMS-FUNNEL-EVENTS-001 — a first-party form's funnel on the one event log.
 *
 * The browser reports a visit; the Leads Centre reports the lead. The two are kept apart, both go through the
 * recorder that owns the rules (off until the workspace switches advanced tracking on, no IP, no user agent,
 * a key only with consent), and a lead's own events are written once however often it moves.
 */
final class LeadFormFunnelTest extends TestCase
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

    private function tracking(bool $on): void
    {
        CapabilitySwitch::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'scope_type' => 'tenant', 'scope_key' => $this->tenant->id, 'module' => 'advanced_tracking'],
            ['scope_id' => null, 'enabled' => $on],
        );
        app(Capabilities::class)->bump((string) $this->tenant->id);
    }

    private function slug(): string
    {
        $definition = [
            'steps' => [['id' => 's1', 'title' => 'You', 'fields' => [
                ['id' => 'full_name', 'type' => 'text', 'label' => 'Name', 'maps_to' => 'name'],
                ['id' => 'mobile', 'type' => 'phone', 'label' => 'Mobile', 'maps_to' => 'phone'],
            ]]],
            'consent' => ['required' => true, 'text' => ['en' => 'May we call you?']],
        ];
        $id = $this->actingAs($this->owner, 'sanctum')->withHeaders(self::SPA)->postJson("/api/v1/projects/{$this->project->id}/forms", ['name' => 'F', 'definition' => $definition])->assertCreated()->json('data.id');
        $this->actingAs($this->owner, 'sanctum')->withHeaders(self::SPA)->postJson("/api/v1/projects/{$this->project->id}/forms/{$id}/publish")->assertOk();

        return (string) LeadForm::withoutGlobalScopes()->findOrFail($id)->slug;
    }

    /** @return array<string,mixed> */
    private function submission(): array
    {
        return [
            'submission_id' => 'sub-'.bin2hex(random_bytes(8)),
            'session_id' => 'ses-'.bin2hex(random_bytes(6)),
            'answers' => ['full_name' => 'Sara', 'mobile' => '0500000001'],
            'consent' => ['accepted' => true],
            'attribution' => ['utm_source' => 'meta', 'utm_campaign' => 'q4', 'gclid' => 'Cj0KCQiA-test-1'],
        ];
    }

    public function test_the_browser_reports_a_visit_and_the_events_join_by_session_and_dedupe_by_event_id(): void
    {
        $this->tracking(true);
        $slug = $this->slug();
        $session = 'ses-'.bin2hex(random_bytes(6));
        $attribution = ['utm_source' => 'meta', 'utm_campaign' => 'q4', 'gclid' => 'Cj0KCQiA-secret-value'];

        foreach ([['form_view', null], ['form_start', null], ['step_completed', 1], ['submit_attempt', 2]] as $n => [$event, $step]) {
            $this->postJson("/api/v1/public/forms/{$slug}/events", ['event' => $event, 'event_id' => 'evt-0000000'.$n, 'session_id' => $session, 'step' => $step, 'consent' => 'unknown', 'attribution' => $attribution])->assertNoContent();
        }
        // The same beacon sent again is the same event.
        $this->postJson("/api/v1/public/forms/{$slug}/events", ['event' => 'form_view', 'event_id' => 'evt-00000000', 'session_id' => $session])->assertNoContent();

        $rows = TrackingEvent::withoutGlobalScopes()->orderBy('created_at')->get();
        $this->assertSame(['form_view', 'form_start', 'step_completed', 'submit_attempt'], $rows->pluck('event_type')->all());
        $this->assertSame([$session], $rows->pluck('session_id')->unique()->values()->all());
        $this->assertSame([null, null, 1, 2], $rows->pluck('step')->all());
        $this->assertSame('gclid', $rows[0]->click_id_type);
        $this->assertSame('meta', $rows[0]->utm_source);
        $this->assertSame('unknown', $rows[0]->consent_state);
        $this->assertNull($rows[0]->visitor_key, 'No stable identifier without consent.');
        $this->assertStringNotContainsString('secret-value', json_encode($rows->toArray()), 'The click id\'s value never enters the log.');
    }

    public function test_nothing_is_recorded_while_advanced_tracking_is_off_and_the_answer_does_not_say_so(): void
    {
        $slug = $this->slug();
        $this->postJson("/api/v1/public/forms/{$slug}/events", ['event' => 'form_view', 'event_id' => 'evt-aaaaaaaa', 'session_id' => 'ses-aaaaaaaa'])->assertNoContent();

        $this->assertSame(0, TrackingEvent::withoutGlobalScopes()->count());
    }

    public function test_the_browser_cannot_write_a_lead_event(): void
    {
        $this->tracking(true);
        $slug = $this->slug();

        foreach (['lead_created', 'lead_won', 'conversion_event', 'short_link_open', 'nonsense'] as $event) {
            $this->postJson("/api/v1/public/forms/{$slug}/events", ['event' => $event, 'event_id' => 'evt-bbbbbbbb', 'session_id' => 'ses-bbbbbbbb'])->assertStatus(422);
        }
        $this->assertSame(0, TrackingEvent::withoutGlobalScopes()->count());
    }

    public function test_the_server_writes_lead_created_once_and_each_stage_once_however_often_it_moves(): void
    {
        $this->tracking(true);
        $slug = $this->slug();
        $body = $this->submission();

        $this->postJson("/api/v1/public/forms/{$slug}/submissions", $body)->assertCreated();
        $this->postJson("/api/v1/public/forms/{$slug}/submissions", $body)->assertOk();

        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $lead = Lead::query()->firstOrFail();
        $advance = app(AdvanceLead::class);
        $lead = $advance->execute($lead, LeadStage::Assigned);
        $lead = $advance->execute($lead, LeadStage::Contacted);
        $lead = $advance->execute($lead, LeadStage::Qualified);
        $lead = $advance->execute($lead, LeadStage::Contacted);   // a step back, then forward again
        $lead = $advance->execute($lead, LeadStage::Qualified);
        $lead = $advance->execute($lead, LeadStage::Appointment);
        $advance->execute($lead, LeadStage::Won);
        app(TenantContext::class)->forget();

        $counts = TrackingEvent::withoutGlobalScopes()->selectRaw('event_type, count(*) n')->groupBy('event_type')->pluck('n', 'event_type')->all();
        $this->assertEquals(['lead_created' => 1, 'lead_contacted' => 1, 'lead_qualified' => 1, 'lead_appointment' => 1, 'lead_won' => 1], $counts);

        $created = TrackingEvent::withoutGlobalScopes()->where('event_type', 'lead_created')->firstOrFail();
        $this->assertSame((string) $lead->id, (string) $created->lead_id);
        $this->assertSame($body['session_id'], $created->session_id);
        $this->assertSame('gclid', $created->click_id_type);
        $this->assertSame('granted', $created->consent_state);
        $this->assertSame('leads_centre', TrackingEvent::withoutGlobalScopes()->where('event_type', 'lead_won')->value('source'));
    }

    public function test_a_lead_that_did_not_come_through_a_form_writes_no_form_event(): void
    {
        $this->tracking(true);
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $lead = Lead::create(['tenant_id' => $this->tenant->id, 'name' => 'Manual', 'source' => 'manual', 'status' => 'new']);
        app(AdvanceLead::class)->execute($lead, LeadStage::Assigned);
        app(AdvanceLead::class)->execute($lead->refresh(), LeadStage::Contacted);
        app(TenantContext::class)->forget();

        $this->assertSame(0, TrackingEvent::withoutGlobalScopes()->count());
    }

    public function test_an_unpublished_or_unknown_form_takes_no_events(): void
    {
        $this->tracking(true);
        $this->postJson('/api/v1/public/forms/zzzzzzzzzzzz/events', ['event' => 'form_view', 'event_id' => 'evt-cccccccc', 'session_id' => 'ses-cccccccc'])->assertNotFound();
        $this->assertSame(0, TrackingEvent::withoutGlobalScopes()->count());
    }
}
