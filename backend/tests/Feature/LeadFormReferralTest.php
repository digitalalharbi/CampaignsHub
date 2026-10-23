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
use App\Domains\Forms\Services\ReferralCodes;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tracking\Models\TrackingEvent;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * FORMS-REFERRALS-001 — a lead can pass the form on; the lead it brings knows who brought it, from a code only we could have issued.
 */
final class LeadFormReferralTest extends TestCase
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

    private function as()
    {
        return $this->actingAs($this->owner, 'sanctum')->withHeaders(self::SPA);
    }

    /** @return array{0: string, 1: string} [slug, form id] */
    private function form(): array
    {
        $definition = [
            'steps' => [['id' => 's1', 'title' => 'You', 'fields' => [
                ['id' => 'full_name', 'type' => 'text', 'label' => 'Name', 'maps_to' => 'name'],
                ['id' => 'mobile', 'type' => 'phone', 'label' => 'Mobile', 'maps_to' => 'phone'],
            ]]],
            'consent' => ['required' => true, 'text' => ['en' => 'Contact me.']],
        ];
        $id = $this->as()->postJson("/api/v1/projects/{$this->project->id}/forms", ['name' => 'F', 'definition' => $definition])->assertCreated()->json('data.id');
        $this->as()->postJson("/api/v1/projects/{$this->project->id}/forms/{$id}/publish")->assertOk();

        return [(string) LeadForm::withoutGlobalScopes()->findOrFail($id)->slug, (string) $id];
    }

    private function submit(string $slug, int $n, ?string $ref = null): void
    {
        $this->postJson("/api/v1/public/forms/{$slug}/submissions", array_filter([
            'submission_id' => 'sub-'.str_pad((string) $n, 16, '0', STR_PAD_LEFT),
            'answers' => ['full_name' => "Person {$n}", 'mobile' => '05000066'.str_pad((string) $n, 2, '0', STR_PAD_LEFT)],
            'consent' => ['accepted' => true],
            'ref' => $ref,
        ]))->assertCreated();
    }

    private function codeFrom(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        return (string) $q['ref'];
    }

    public function test_a_lead_can_pass_on_the_form_and_the_lead_it_brings_knows_who_brought_it(): void
    {
        [$slug, $formId] = $this->form();
        $this->submit($slug, 1);
        $referrer = Lead::withoutGlobalScopes()->firstOrFail();

        $url = $this->as()->getJson("/api/v1/projects/{$this->project->id}/leads/{$referrer->id}/referral-link?form={$formId}")->assertOk()->json('data.url');
        $this->assertStringContainsString("/f/{$slug}?ref=", $url);
        $this->assertStringNotContainsString('Person 1', $url, 'The code names an opaque id, not a person.');

        $this->submit($slug, 2, $this->codeFrom($url));

        $brought = Lead::withoutGlobalScopes()->where('name', 'Person 2')->firstOrFail();
        $this->assertSame((string) $referrer->id, (string) $brought->referred_by_lead_id);
        $this->assertDatabaseHas('activities', ['subject_id' => (string) $referrer->id, 'type' => 'note']);
        $event = TrackingEvent::withoutGlobalScopes()->where('event_type', 'lead_referred')->firstOrFail();
        $this->assertSame((string) $brought->id, (string) $event->lead_id);
        $this->assertSame(1, TrackingEvent::withoutGlobalScopes()->where('event_type', 'lead_referred')->count());
    }

    public function test_a_code_that_does_not_verify_is_ignored_and_never_costs_the_lead(): void
    {
        [$slug, $formId] = $this->form();
        $this->submit($slug, 1);
        $referrer = Lead::withoutGlobalScopes()->firstOrFail();
        $good = $this->codeFrom($this->as()->getJson("/api/v1/projects/{$this->project->id}/leads/{$referrer->id}/referral-link?form={$formId}")->json('data.url'));
        [$id] = explode('.', $good);

        $n = 10;
        foreach ([$id.'.00000000', 'nonsense', str_repeat('a', 32).'.'.str_repeat('b', 8), $id.'.'.substr($good, -8).'x'] as $bad) {
            $this->submit($slug, ++$n, $bad);
        }

        $this->assertSame(0, Lead::withoutGlobalScopes()->whereNotNull('referred_by_lead_id')->count());
        $this->assertSame(5, Lead::withoutGlobalScopes()->count(), 'Every submission was still accepted.');
        $this->assertSame(0, TrackingEvent::withoutGlobalScopes()->where('event_type', 'lead_referred')->count());
    }

    public function test_a_code_is_bound_to_its_form_and_its_workspace(): void
    {
        [$slugA, $formA] = $this->form();
        [$slugB] = $this->form();
        $this->submit($slugA, 1);
        $referrer = Lead::withoutGlobalScopes()->firstOrFail();
        $code = $this->codeFrom($this->as()->getJson("/api/v1/projects/{$this->project->id}/leads/{$referrer->id}/referral-link?form={$formA}")->json('data.url'));

        $this->submit($slugB, 2, $code);   // carried to a different form of the same project
        $this->assertNull(Lead::withoutGlobalScopes()->where('name', 'Person 2')->value('referred_by_lead_id'));

        $this->assertNull(ReferralCodes::verify($code, (string) Str::uuid(), $formA), 'Carried to another workspace.');
        $this->assertNotNull(ReferralCodes::verify($code, (string) $this->tenant->id, $formA));
    }

    public function test_the_referrer_is_provenance_the_team_cannot_rewrite_and_the_funnel_counts_referred_and_referred_won(): void
    {
        [$slug, $formId] = $this->form();
        $this->submit($slug, 1);
        $referrer = Lead::withoutGlobalScopes()->firstOrFail();
        $code = $this->codeFrom($this->as()->getJson("/api/v1/projects/{$this->project->id}/leads/{$referrer->id}/referral-link?form={$formId}")->json('data.url'));
        $this->submit($slug, 2, $code);
        $this->submit($slug, 3, $code);
        $this->submit($slug, 4);

        // An edit through the Leads Centre cannot repoint the referrer.
        $brought = Lead::withoutGlobalScopes()->where('name', 'Person 2')->firstOrFail();
        $this->as()->patchJson("/api/v1/leads/{$brought->id}", ['name' => 'Renamed', 'referred_by_lead_id' => (string) $referrer->id.'x'])->assertOk();
        $this->assertSame((string) $referrer->id, (string) Lead::withoutGlobalScopes()->findOrFail($brought->id)->referred_by_lead_id);

        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $advance = app(AdvanceLead::class);
        $won = Lead::query()->where('name', 'Renamed')->firstOrFail();
        foreach ([LeadStage::Assigned, LeadStage::Contacted, LeadStage::Qualified, LeadStage::Appointment, LeadStage::Won] as $stage) {
            $won = $advance->execute($won, $stage);
        }
        app(TenantContext::class)->forget();

        $r = $this->as()->getJson("/api/v1/projects/{$this->project->id}/forms/{$formId}/funnel")->assertOk()->json('data');
        $this->assertSame(2, $r['leads']['referred']);
        $this->assertSame(1, $r['leads']['referred_won']);
        $this->assertSame(4, $r['leads']['received']);
    }

    public function test_the_link_needs_a_published_form_in_this_project_and_the_permission(): void
    {
        [$slug, $formId] = $this->form();
        $this->submit($slug, 1);
        $lead = Lead::withoutGlobalScopes()->firstOrFail();
        $viewer = User::create(['name' => 'V', 'email' => uniqid().'@v.test', 'password' => 'secret123']);
        $this->grantMembership($viewer, $this->tenant);

        $this->actingAs($viewer, 'sanctum')->withHeaders(self::SPA)->getJson("/api/v1/projects/{$this->project->id}/leads/{$lead->id}/referral-link?form={$formId}")->assertForbidden();
        $this->as()->getJson("/api/v1/projects/{$this->project->id}/leads/{$lead->id}/referral-link")->assertStatus(422);
        $this->as()->getJson("/api/v1/projects/{$this->project->id}/leads/{$lead->id}/referral-link?form=00000000-0000-0000-0000-000000000000")->assertNotFound();
    }
}
