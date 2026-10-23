<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Accounts\Capabilities\Capabilities;
use App\Domains\Accounts\Capabilities\CapabilitySwitch;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\CRM\Models\Lead;
use App\Domains\Forms\Models\ConversionDelivery;
use App\Domains\Forms\Models\LeadForm;
use App\Domains\Forms\Models\LeadFormSubmission;
use App\Domains\Forms\Services\FormDataRetention;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tracking\Models\TrackingEvent;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * FORMS-PDPL-001 — the evidence around a form submission expires and can be erased on request; the proof of consent stays.
 */
final class LeadFormRetentionTest extends TestCase
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
        CapabilitySwitch::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'scope_type' => 'tenant', 'scope_key' => $this->tenant->id, 'module' => 'advanced_tracking'],
            ['scope_id' => null, 'enabled' => true],
        );
        app(Capabilities::class)->bump((string) $this->tenant->id);
    }

    private function as(?User $u = null)
    {
        return $this->actingAs($u ?? $this->owner, 'sanctum')->withHeaders(self::SPA);
    }

    /** @return array{0: string, 1: LeadForm} */
    private function form(?int $retentionDays = null): array
    {
        $definition = [
            'steps' => [['id' => 's1', 'title' => 'You', 'fields' => [
                ['id' => 'full_name', 'type' => 'text', 'label' => 'Name', 'maps_to' => 'name'],
                ['id' => 'mobile', 'type' => 'phone', 'label' => 'Mobile', 'maps_to' => 'phone'],
            ]]],
            'consent' => ['required' => true, 'text' => ['en' => 'Contact me.'], 'measurement' => ['enabled' => true, 'text' => ['en' => 'Report.']]],
        ];
        $id = $this->as()->postJson("/api/v1/projects/{$this->project->id}/forms", ['name' => 'F', 'definition' => $definition, 'retention_days' => $retentionDays])->assertCreated()->json('data.id');
        $this->as()->postJson("/api/v1/projects/{$this->project->id}/forms/{$id}/publish")->assertOk();
        $form = LeadForm::withoutGlobalScopes()->findOrFail($id);

        return [(string) $form->slug, $form];
    }

    private function submit(string $slug, int $n): LeadFormSubmission
    {
        $this->postJson("/api/v1/public/forms/{$slug}/submissions", [
            'submission_id' => 'sub-'.str_pad((string) $n, 16, '0', STR_PAD_LEFT),
            'session_id' => 'ses-'.str_pad((string) $n, 10, '0', STR_PAD_LEFT),
            'answers' => ['full_name' => "Person {$n}", 'mobile' => '05000077'.str_pad((string) $n, 2, '0', STR_PAD_LEFT)],
            'consent' => ['accepted' => true, 'measurement' => true],
            'attribution' => ['utm_source' => 'meta', 'utm_campaign' => 'q4', 'gclid' => 'Cj0KCQiA-test-1', 'landing_path' => '/villas', 'referrer' => 'https://l.facebook.com/x'],
        ])->assertCreated();

        return LeadFormSubmission::withoutGlobalScopes()->where('idempotency_key', 'sub-'.str_pad((string) $n, 16, '0', STR_PAD_LEFT))->firstOrFail();
    }

    private function age(LeadFormSubmission $s, int $days): void
    {
        $past = Carbon::now()->subDays($days);
        DB::table('lead_form_submissions')->where('id', $s->id)->update(['submitted_at' => $past]);
        DB::table('tracking_events')->where('lead_id', $s->lead_id)->update(['occurred_at' => $past]);
        DB::table('conversion_deliveries')->where('lead_id', $s->lead_id)->update(['created_at' => $past]);
    }

    public function test_expired_evidence_is_anonymised_per_the_forms_retention_and_the_consent_record_is_kept(): void
    {
        [$slug] = $this->form(retentionDays: 90);
        $old = $this->submit($slug, 1);
        $recent = $this->submit($slug, 2);
        $this->age($old, 120);
        $this->age($recent, 10);

        $this->artisan('forms:purge-evidence')->expectsOutputToContain('Submissions anonymised: 1')->assertSuccessful();

        $old->refresh();
        $recent->refresh();
        $this->assertNull($old->attribution);
        $this->assertNull($old->session_id);
        $this->assertNotNull($old->evidence_purged_at);
        $this->assertSame('retention', $old->purge_reason);
        // The proof that the processing was lawful outlives the data it justified.
        $this->assertSame('granted', $old->consent_state);
        $this->assertSame('granted', $old->measurement_consent);
        $this->assertNotNull($old->consent_version);
        $this->assertNotNull($old->lead_form_version_id);
        $this->assertNotNull($old->lead_id);

        $this->assertNotNull($recent->attribution);
        $this->assertNotNull($recent->session_id);
        $this->assertNull($recent->evidence_purged_at);
        $this->assertSame(0, TrackingEvent::withoutGlobalScopes()->where('lead_id', $old->lead_id)->count());
        $this->assertGreaterThan(0, TrackingEvent::withoutGlobalScopes()->where('lead_id', $recent->lead_id)->count());
    }

    public function test_a_form_without_its_own_period_uses_the_default_and_a_second_run_changes_nothing(): void
    {
        [$slug] = $this->form();
        $s = $this->submit($slug, 1);
        $this->age($s, 800);   // past the 730-day default

        $first = app(FormDataRetention::class)->purgeExpired();
        $stamp = $s->refresh()->evidence_purged_at;
        $second = app(FormDataRetention::class)->purgeExpired();

        $this->assertSame(1, $first['submissions']);
        $this->assertSame(0, $second['submissions']);
        $this->assertTrue($stamp->equalTo($s->refresh()->evidence_purged_at), 'A purged row is never touched again.');
    }

    public function test_the_retention_period_has_a_floor_so_evidence_cannot_be_wiped_within_days(): void
    {
        $this->as()->postJson("/api/v1/projects/{$this->project->id}/forms", ['name' => 'F', 'definition' => ['steps' => []], 'retention_days' => 3])->assertStatus(422);
        [$slug, $form] = $this->form();
        $this->as()->patchJson("/api/v1/projects/{$this->project->id}/forms/{$form->id}", ['retention_days' => 7])->assertStatus(422);
        $this->as()->patchJson("/api/v1/projects/{$this->project->id}/forms/{$form->id}", ['retention_days' => 30])->assertOk();
        $this->assertSame(30, $form->refresh()->retention_days);
        $this->assertNotEmpty($slug);
    }

    public function test_an_erasure_anonymises_the_lead_clears_its_evidence_keeps_the_consent_and_the_count_and_is_audited(): void
    {
        [$slug] = $this->form();
        $s = $this->submit($slug, 1);
        $other = $this->submit($slug, 2);
        $lead = Lead::withoutGlobalScopes()->findOrFail($s->lead_id);
        $destination = $this->as()->postJson("/api/v1/projects/{$this->project->id}/conversion-destinations", ['kind' => 'ga4', 'name' => 'GA4', 'config' => ['measurement_id' => 'G-ABC123XYZ9']])->assertCreated()->json('data.id');
        ConversionDelivery::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'conversion_destination_id' => $destination, 'lead_id' => $lead->id,
            'event_name' => 'generate_lead', 'event_id' => 'e-1', 'status' => 'test', 'payload' => ['body' => ['hash' => 'x']],
        ]);

        $this->as()->postJson("/api/v1/projects/{$this->project->id}/leads/{$lead->id}/erase")->assertStatus(422);
        $this->as()->postJson("/api/v1/projects/{$this->project->id}/leads/{$lead->id}/erase", ['confirm' => true])->assertOk();

        $lead = Lead::withoutGlobalScopes()->findOrFail($lead->id);
        $this->assertSame('Erased', $lead->name);
        foreach (['email', 'phone', 'email_normalized', 'phone_normalized', 'form_answers', 'notes', 'click_id', 'landing_page'] as $col) {
            $this->assertNull($lead->{$col}, $col);
        }
        $this->assertSame('q4', $lead->utm_campaign, 'A campaign tag describes an advertisement, not a person.');

        $s->refresh();
        $this->assertNull($s->attribution);
        $this->assertNull($s->session_id);
        $this->assertSame('erasure', $s->purge_reason);
        $this->assertSame('granted', $s->consent_state);
        $this->assertNotNull($s->consent_version);
        $this->assertSame(0, TrackingEvent::withoutGlobalScopes()->where('lead_id', $lead->id)->count());
        $this->assertNull(ConversionDelivery::withoutGlobalScopes()->where('lead_id', $lead->id)->value('payload'));

        // Nobody else was touched, and the erased lead still counts once.
        $this->assertNotNull($other->refresh()->attribution);
        $this->assertSame('Person 2', Lead::withoutGlobalScopes()->findOrFail($other->lead_id)->name);
        $this->assertSame(2, Lead::withoutGlobalScopes()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'lead.erased', 'entity_id' => (string) $lead->id]);
    }

    public function test_erasure_needs_the_delete_permission_and_stays_in_its_project(): void
    {
        [$slug] = $this->form();
        $s = $this->submit($slug, 1);

        // Can work the project's leads, may not delete them.
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid()]);
        $role->givePermissionTo('leads.view', 'leads.update', 'projects.view.all');
        $worker = User::create(['name' => 'W', 'email' => uniqid().'@w.test', 'password' => 'secret123']);
        $this->grantMembership($worker, $this->tenant);
        $worker->assignRole($role);
        $client = ClientWorkspace::create(['name' => 'Other', 'slug' => 'o-'.uniqid(), 'mode' => 'managed']);
        $elsewhere = Project::create(['client_workspace_id' => $client->id, 'name' => 'Q', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $this->as($worker)->postJson("/api/v1/projects/{$this->project->id}/leads/{$s->lead_id}/erase", ['confirm' => true])->assertForbidden();
        $this->assertSame('Person 1', Lead::withoutGlobalScopes()->findOrFail($s->lead_id)->name);

        $this->as()->postJson("/api/v1/projects/{$elsewhere->id}/leads/{$s->lead_id}/erase", ['confirm' => true])->assertNotFound();
        $this->assertSame('Person 1', Lead::withoutGlobalScopes()->findOrFail($s->lead_id)->name);
    }
}
