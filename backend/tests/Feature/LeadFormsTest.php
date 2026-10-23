<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Accounts\Capabilities\Capabilities;
use App\Domains\Accounts\Capabilities\CapabilitySwitch;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\CRM\Models\Lead;
use App\Domains\Forms\Models\LeadForm;
use App\Domains\Forms\Models\LeadFormSubmission;
use App\Domains\Forms\Models\LeadFormVersion;
use App\Domains\Forms\Services\SubmitLeadForm;
use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * FORMS-HUB-001 — a project's lead form, from the builder to a stored lead.
 *
 * The claims, each proved against the real routes and the real Leads Centre:
 *  - a submission becomes a lead in the form's own project, carrying every answer and its attribution;
 *  - the server judges the answers against the PUBLISHED version, whatever the browser claimed;
 *  - one submission id is one lead however many times it arrives;
 *  - consent is required when asked for, and recorded with the wording's version;
 *  - a published version never changes; project and tenant lines hold.
 */
final class LeadFormsTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    private const SPA = ['Origin' => 'http://localhost:5173'];

    private Tenant $tenant;

    private User $owner;

    private Project $project;

    private Project $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
        $client = ClientWorkspace::create(['name' => 'Client', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
        $this->other = Project::create(['client_workspace_id' => $client->id, 'name' => 'Q', 'status' => 'active']);
        app(TenantContext::class)->forget();
    }

    /** @return array<string,mixed> */
    private function definition(): array
    {
        return [
            'steps' => [
                ['id' => 's1', 'title' => 'You', 'fields' => [
                    ['id' => 'full_name', 'type' => 'text', 'label' => 'Name', 'maps_to' => 'name'],
                    ['id' => 'mobile', 'type' => 'phone', 'label' => 'Mobile', 'maps_to' => 'phone'],
                    ['id' => 'email', 'type' => 'email', 'label' => 'Email', 'maps_to' => 'email'],
                ]],
                ['id' => 's2', 'title' => 'Interest', 'fields' => [
                    ['id' => 'interest', 'type' => 'select', 'label' => 'Interest', 'required' => true, 'options' => [['value' => 'villa', 'label' => 'Villa'], ['value' => 'land', 'label' => 'Land']]],
                    ['id' => 'budget', 'type' => 'number', 'label' => 'Budget', 'validation' => ['min' => 100000], 'show_if' => ['field' => 'interest', 'op' => 'equals', 'value' => 'villa']],
                ]],
            ],
            'consent' => ['required' => true, 'text' => ['en' => 'You may contact me about this enquiry.']],
            'thank_you' => ['message' => ['en' => 'Thank you.']],
        ];
    }

    private function as(?User $user = null)
    {
        return $this->actingAs($user ?? $this->owner, 'sanctum')->withHeaders(self::SPA);
    }

    private function publishedForm(array $over = []): string
    {
        $id = $this->as()->postJson("/api/v1/projects/{$this->project->id}/forms", array_merge(['name' => 'Villa enquiry', 'definition' => $this->definition()], $over))
            ->assertCreated()->json('data.id');
        $this->as()->postJson("/api/v1/projects/{$this->project->id}/forms/{$id}/publish")->assertOk();

        return (string) LeadForm::withoutGlobalScopes()->findOrFail($id)->slug;
    }

    /** @param array<string,mixed> $over @return array<string,mixed> */
    private function submission(array $over = []): array
    {
        return array_replace_recursive([
            'submission_id' => 'sub-'.bin2hex(random_bytes(8)),
            'answers' => ['full_name' => 'Sara Al-Qahtani', 'mobile' => '0500000001', 'email' => 'Sara@Example.test', 'interest' => 'villa', 'budget' => 250000],
            'consent' => ['accepted' => true],
            'attribution' => ['utm_source' => 'meta', 'utm_campaign' => 'q4-villas', 'gclid' => 'Cj0KCQiA-test-1', 'landing_path' => '/villas?token=secret', 'referrer' => 'https://l.facebook.com/x?y=1'],
        ], $over);
    }

    public function test_a_submission_becomes_a_lead_in_the_forms_project_with_every_answer_and_its_attribution(): void
    {
        $slug = $this->publishedForm();

        $this->postJson("/api/v1/public/forms/{$slug}/submissions", $this->submission())->assertCreated()->assertJsonPath('data.accepted', true)->assertJsonPath('data.duplicate', false);

        $lead = Lead::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertSame((string) $this->project->id, (string) $lead->project_id);
        $this->assertSame('Sara Al-Qahtani', $lead->name);
        $this->assertSame('sara@example.test', $lead->email);
        $this->assertSame('website', $lead->source);
        $this->assertSame('campaignshub_form', $lead->provider);
        $this->assertSame('q4-villas', $lead->utm_campaign);
        $this->assertSame('gclid:Cj0KCQiA-test-1', $lead->click_id);
        $this->assertSame('/villas', $lead->landing_page, 'The path only: a query string can carry a token.');
        $this->assertEquals(['Interest' => 'villa', 'Budget' => 250000], $lead->form_answers);

        $submission = LeadFormSubmission::withoutGlobalScopes()->firstOrFail();
        $this->assertSame((string) $lead->id, (string) $submission->lead_id);
        $this->assertSame('granted', $submission->consent_state);
        $this->assertSame('l.facebook.com', $submission->attribution['referrer_host']);
        $this->assertArrayNotHasKey('ip', $submission->attribution);
    }

    public function test_one_submission_id_is_one_lead_however_many_times_it_arrives(): void
    {
        $slug = $this->publishedForm();
        $body = $this->submission();

        $this->postJson("/api/v1/public/forms/{$slug}/submissions", $body)->assertCreated();
        $this->postJson("/api/v1/public/forms/{$slug}/submissions", $body)->assertOk()->assertJsonPath('data.duplicate', true);

        $this->assertSame(1, Lead::withoutGlobalScopes()->count());
        $this->assertSame(1, LeadFormSubmission::withoutGlobalScopes()->count());
    }

    public function test_consent_is_required_when_the_version_asks_and_the_wording_version_is_recorded(): void
    {
        $slug = $this->publishedForm();

        $this->postJson("/api/v1/public/forms/{$slug}/submissions", $this->submission(['consent' => ['accepted' => false]]))
            ->assertStatus(422)->assertJsonValidationErrors('consent');
        $this->assertSame(0, Lead::withoutGlobalScopes()->count());

        $this->postJson("/api/v1/public/forms/{$slug}/submissions", $this->submission())->assertCreated();
        $version = LeadFormVersion::withoutGlobalScopes()->firstOrFail()->definition['consent']['version'];
        $this->assertSame($version, LeadFormSubmission::withoutGlobalScopes()->firstOrFail()->consent_version);
    }

    public function test_the_server_judges_the_answers_not_the_browser(): void
    {
        $slug = $this->publishedForm();

        // A required answer missing, a value outside the options, an unknown key.
        $body = $this->submission();
        $body['answers'] = ['interest' => 'castle', 'is_admin' => true, 'mobile' => '0500000001'];
        $this->postJson("/api/v1/public/forms/{$slug}/submissions", $body)
            ->assertStatus(422)->assertJsonValidationErrors(['answers.interest', 'answers.is_admin', 'answers.full_name']);

        // A number under its minimum.
        $this->postJson("/api/v1/public/forms/{$slug}/submissions", $this->submission(['answers' => ['budget' => 5]]))
            ->assertStatus(422)->assertJsonValidationErrors(['answers.budget']);
        $this->assertSame(0, Lead::withoutGlobalScopes()->count());
    }

    public function test_an_answer_to_a_field_the_logic_hides_is_dropped_not_stored(): void
    {
        $slug = $this->publishedForm();

        // «budget» shows only for villas; this person chose land, so a budget they once typed is not kept.
        $this->postJson("/api/v1/public/forms/{$slug}/submissions", $this->submission(['answers' => ['interest' => 'land', 'budget' => 999999]]))->assertCreated();

        $this->assertEquals(['Interest' => 'land'], Lead::withoutGlobalScopes()->firstOrFail()->form_answers);
    }

    public function test_a_filled_honeypot_is_accepted_in_silence_and_stores_nothing(): void
    {
        $slug = $this->publishedForm();

        $this->postJson("/api/v1/public/forms/{$slug}/submissions", $this->submission() + ['website' => 'http://spam.test'])->assertOk();

        $this->assertSame(0, Lead::withoutGlobalScopes()->count());
        $this->assertSame(0, LeadFormSubmission::withoutGlobalScopes()->count());
    }

    public function test_a_form_owner_is_assigned_the_lead_and_must_be_someone_who_can_work_the_project(): void
    {
        $stranger = User::create(['name' => 'S', 'email' => uniqid().'@s.test', 'password' => 'secret123']);
        $this->as()->postJson("/api/v1/projects/{$this->project->id}/forms", ['name' => 'F', 'definition' => $this->definition(), 'default_owner_id' => $stranger->id])->assertStatus(422);

        $slug = $this->publishedForm(['default_owner_id' => $this->owner->id]);
        $this->postJson("/api/v1/public/forms/{$slug}/submissions", $this->submission())->assertCreated();

        $lead = Lead::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($this->owner->id, $lead->owner_id);
        $this->assertNotNull($lead->assigned_at);
    }

    public function test_the_same_person_through_a_second_submission_is_linked_not_dropped(): void
    {
        $slug = $this->publishedForm();
        $this->postJson("/api/v1/public/forms/{$slug}/submissions", $this->submission())->assertCreated();
        $this->postJson("/api/v1/public/forms/{$slug}/submissions", $this->submission())->assertCreated();

        $leads = Lead::withoutGlobalScopes()->orderBy('created_at')->get();
        $this->assertCount(2, $leads, 'Both submissions are real acquisition events.');
        $this->assertSame((string) $leads[0]->id, (string) $leads[1]->canonical_lead_id);
    }

    public function test_a_published_version_is_immutable_and_a_change_starts_the_next_one(): void
    {
        $slug = $this->publishedForm();
        $form = LeadForm::withoutGlobalScopes()->where('slug', $slug)->firstOrFail();

        $changed = $this->definition();
        $changed['steps'][0]['fields'][0]['label'] = 'Full name';
        $this->as()->patchJson("/api/v1/projects/{$this->project->id}/forms/{$form->id}", ['definition' => $changed])->assertOk();

        $versions = LeadFormVersion::withoutGlobalScopes()->where('lead_form_id', $form->id)->orderBy('version')->get();
        $this->assertCount(2, $versions);
        $this->assertSame('Name', $versions[0]->definition['steps'][0]['fields'][0]['label']);
        $this->assertNull($versions[1]->published_at, 'The live form is still version 1 until the new draft is published.');
        $this->getJson("/api/v1/public/forms/{$slug}")->assertOk()->assertJsonPath('data.version', 1);

        $this->expectException(\LogicException::class);
        $versions[0]->forceFill(['definition' => $changed])->save();
    }

    public function test_the_public_form_reveals_the_definition_and_nothing_of_the_workspace(): void
    {
        $slug = $this->publishedForm();
        $body = $this->getJson("/api/v1/public/forms/{$slug}")->assertOk()->json();

        $this->assertSame('Villa enquiry', $body['data']['name']);
        $encoded = json_encode($body);
        foreach (['tenant', (string) $this->tenant->id, (string) $this->project->id, 'owner', 'default_owner_id'] as $leak) {
            $this->assertStringNotContainsString($leak, $encoded);
        }
    }

    public function test_a_draft_an_archived_form_and_a_switched_off_module_are_not_found(): void
    {
        $id = $this->as()->postJson("/api/v1/projects/{$this->project->id}/forms", ['name' => 'D', 'definition' => $this->definition()])->assertCreated()->json('data.id');
        $slug = (string) LeadForm::withoutGlobalScopes()->findOrFail($id)->slug;
        $this->getJson("/api/v1/public/forms/{$slug}")->assertNotFound();

        $this->as()->postJson("/api/v1/projects/{$this->project->id}/forms/{$id}/publish")->assertOk();
        $this->getJson("/api/v1/public/forms/{$slug}")->assertOk();

        CapabilitySwitch::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'scope_type' => 'tenant', 'scope_key' => $this->tenant->id, 'module' => 'forms_hub'],
            ['scope_id' => null, 'enabled' => false],
        );
        app(Capabilities::class)->bump((string) $this->tenant->id);
        $this->getJson("/api/v1/public/forms/{$slug}")->assertNotFound();
        $this->postJson("/api/v1/public/forms/{$slug}/submissions", $this->submission())->assertNotFound();
        $this->as()->getJson("/api/v1/projects/{$this->project->id}/forms")->assertForbidden();

        CapabilitySwitch::withoutGlobalScopes()->where('module', 'forms_hub')->delete();
        app(Capabilities::class)->bump((string) $this->tenant->id);
        $this->as()->postJson("/api/v1/projects/{$this->project->id}/forms/{$id}/archive")->assertOk();
        $this->getJson("/api/v1/public/forms/{$slug}")->assertNotFound();
    }

    public function test_a_form_is_addressed_by_its_project_and_the_team_side_needs_the_permission(): void
    {
        $id = $this->as()->postJson("/api/v1/projects/{$this->project->id}/forms", ['name' => 'F', 'definition' => $this->definition()])->assertCreated()->json('data.id');

        $this->as()->getJson("/api/v1/projects/{$this->other->id}/forms/{$id}")->assertNotFound();
        $this->as()->getJson("/api/v1/projects/{$this->project->id}/forms")->assertOk()->assertJsonPath('data.0.id', $id);

        $viewer = User::create(['name' => 'V', 'email' => uniqid().'@v.test', 'password' => 'secret123']);
        $this->grantMembership($viewer, $this->tenant);
        $this->as($viewer)->postJson("/api/v1/projects/{$this->project->id}/forms", ['name' => 'X', 'definition' => $this->definition()])->assertForbidden();
    }

    public function test_an_invalid_definition_is_refused_with_the_reasons(): void
    {
        $bad = $this->definition();
        $bad['steps'][0]['fields'] = [['id' => 'only', 'type' => 'text', 'label' => 'Only']];

        $this->as()->postJson("/api/v1/projects/{$this->project->id}/forms", ['name' => 'F', 'definition' => $bad])->assertStatus(422);
        $this->assertSame(0, LeadForm::withoutGlobalScopes()->count());
    }

    public function test_another_workspace_can_neither_reach_nor_receive_into_this_workspaces_forms(): void
    {
        $slug = $this->publishedForm();
        $form = LeadForm::withoutGlobalScopes()->where('slug', $slug)->firstOrFail();

        $rival = Tenant::create(['name' => 'Rival', 'slug' => 'rv-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $rival->id);
        $role = Role::create(['tenant_id' => $rival->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $outsider = User::create(['name' => 'X', 'email' => uniqid().'@x.test', 'password' => 'secret123']);
        $this->grantMembership($outsider, $rival);
        $outsider->assignRole($role);
        $theirClient = ClientWorkspace::create(['name' => 'Their client', 'slug' => 'tc-'.uniqid(), 'mode' => 'managed']);
        $theirProject = Project::create(['client_workspace_id' => $theirClient->id, 'name' => 'T', 'status' => 'active']);
        app(TenantContext::class)->forget();

        // Their own project, our form id: nothing. Our project id from their session: refused.
        $this->as($outsider)->getJson("/api/v1/projects/{$theirProject->id}/forms/{$form->id}")->assertNotFound();
        $this->as($outsider)->patchJson("/api/v1/projects/{$theirProject->id}/forms/{$form->id}", ['name' => 'stolen'])->assertNotFound();
        $this->as($outsider)->getJson("/api/v1/projects/{$this->project->id}/forms")->assertStatus(404);
        $this->assertSame('Villa enquiry', LeadForm::withoutGlobalScopes()->findOrFail($form->id)->name);

        // A submission through our public address lands in OUR workspace only, whoever is signed in.
        $this->actingAs($outsider, 'sanctum')->postJson("/api/v1/public/forms/{$slug}/submissions", $this->submission())->assertCreated();
        $lead = Lead::withoutGlobalScopes()->firstOrFail();
        $this->assertSame((string) $this->tenant->id, (string) $lead->tenant_id);
        $this->assertSame((string) $this->project->id, (string) $lead->project_id);
    }

    public function test_the_public_answer_carries_no_lead_data_and_the_team_listing_carries_no_submitter_pii(): void
    {
        $slug = $this->publishedForm();
        $answer = $this->postJson("/api/v1/public/forms/{$slug}/submissions", $this->submission())->assertCreated()->getContent();
        foreach (['Sara', 'sara@example.test', '0500000001', 'lead_id', 'owner'] as $leak) {
            $this->assertStringNotContainsString($leak, (string) $answer);
        }

        $team = $this->as()->getJson("/api/v1/projects/{$this->project->id}/forms")->assertOk()->getContent();
        foreach (['Sara', 'sara@example.test', '0500000001'] as $pii) {
            $this->assertStringNotContainsString($pii, (string) $team, 'The forms list counts submissions; the people are in the Leads Centre, behind its own permissions.');
        }
    }

    public function test_every_public_route_is_rate_limited_per_form_and_address(): void
    {
        foreach (['api.v1.public.forms.show', 'api.v1.public.forms.submit', 'api.v1.public.forms.event'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertContains('throttle:public-form', $route->gatherMiddleware(), $name);
        }
    }

    public function test_each_accepted_submission_is_exactly_one_lead_with_its_form_version_and_evidence(): void
    {
        $slug = $this->publishedForm();
        $form = LeadForm::withoutGlobalScopes()->where('slug', $slug)->firstOrFail();
        $ids = [];
        foreach (range(1, 3) as $n) {
            $body = $this->submission(['answers' => ['email' => "p{$n}@example.test", 'mobile' => '05000001'.str_pad((string) $n, 2, '0', STR_PAD_LEFT)]]);
            $this->postJson("/api/v1/public/forms/{$slug}/submissions", $body)->assertCreated();
            $ids[] = $body['submission_id'];
        }

        $this->assertSame(3, Lead::withoutGlobalScopes()->count());
        $this->assertSame(3, LeadFormSubmission::withoutGlobalScopes()->count());
        foreach (LeadFormSubmission::withoutGlobalScopes()->get() as $sub) {
            $lead = Lead::withoutGlobalScopes()->findOrFail($sub->lead_id);
            $this->assertSame((string) $form->id, (string) $lead->form_id);
            $this->assertSame((string) $form->project_id, (string) $lead->project_id);
            $this->assertSame((string) $form->published_version_id, (string) $sub->lead_form_version_id);
            $this->assertNotNull($sub->consent_version);
            $this->assertSame('granted', $sub->consent_state);
            $this->assertSame('q4-villas', $sub->attribution['utm_campaign']);
            $this->assertSame($sub->idempotency_key, $lead->provider_lead_id);
            $this->assertNotEmpty($lead->form_answers);
        }
        $this->assertEqualsCanonicalizing($ids, LeadFormSubmission::withoutGlobalScopes()->pluck('idempotency_key')->all());
    }

    private function agent(string $name): User
    {
        $u = User::create(['name' => $name, 'email' => uniqid().'@t.test', 'password' => 'secret123']);
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->grantMembership($u, $this->tenant);
        $u->assignRole($role);
        app(TenantContext::class)->forget();

        return $u;
    }

    public function test_round_robin_gives_each_lead_to_whoever_of_the_pool_holds_the_fewest_and_stamps_the_promised_follow_up(): void
    {
        [$a, $b, $c] = [$this->agent('A'), $this->agent('B'), $this->agent('C')];
        $slug = $this->publishedForm(['assignment_mode' => 'round_robin', 'assignment_owner_ids' => [$a->id, $b->id, $c->id], 'follow_up_minutes' => 30]);

        foreach (range(1, 6) as $n) {
            $this->postJson("/api/v1/public/forms/{$slug}/submissions", $this->submission(['answers' => ['mobile' => '05000009'.str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'email' => "rr{$n}@example.test"]]))->assertCreated();
        }

        $held = Lead::withoutGlobalScopes()->selectRaw('owner_id, count(*) n')->groupBy('owner_id')->pluck('n', 'owner_id')->all();
        $this->assertEquals([$a->id => 2, $b->id => 2, $c->id => 2], $held, 'Six leads over three agents: two each.');
        foreach (Lead::withoutGlobalScopes()->get() as $lead) {
            $this->assertNotNull($lead->assigned_at);
            $this->assertSame('assigned', $lead->status);
            $this->assertEqualsWithDelta(30 * 60, $lead->received_at->diffInSeconds($lead->next_follow_up_at, false), 5);
        }
    }

    public function test_a_pool_member_who_can_no_longer_work_the_project_is_skipped_at_the_draw(): void
    {
        $a = $this->agent('A');
        // B works leads through a narrow tenant role (no settings.manage, so nothing is implied).
        $b = User::create(['name' => 'B', 'email' => uniqid().'@t.test', 'password' => 'secret123']);
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $narrow = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Narrow', 'slug' => 'n-'.uniqid()]);
        $narrow->givePermissionTo('leads.view', 'leads.update', 'projects.view.all');
        $this->grantMembership($b, $this->tenant);
        $b->assignRole($narrow);
        app(TenantContext::class)->forget();

        $slug = $this->publishedForm(['assignment_mode' => 'round_robin', 'assignment_owner_ids' => [$a->id, $b->id]]);

        // Taken off leads after the form was built.
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $narrow->permissions()->detach();
        app(TenantContext::class)->forget();
        app()->forgetInstance(ProjectAbilities::class);
        app()->forgetInstance(SubmitLeadForm::class);

        foreach (range(1, 3) as $n) {
            $this->postJson("/api/v1/public/forms/{$slug}/submissions", $this->submission(['answers' => ['mobile' => '05000008'.str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'email' => "sk{$n}@example.test"]]))->assertCreated();
        }

        $this->assertSame([$a->id], Lead::withoutGlobalScopes()->pluck('owner_id')->unique()->values()->all());
    }

    public function test_a_pool_with_somebody_who_cannot_work_the_project_is_refused_whole(): void
    {
        $a = $this->agent('A');
        $stranger = User::create(['name' => 'S', 'email' => uniqid().'@s.test', 'password' => 'secret123']);

        $this->as()->postJson("/api/v1/projects/{$this->project->id}/forms", ['name' => 'F', 'definition' => $this->definition(), 'assignment_mode' => 'round_robin', 'assignment_owner_ids' => [$a->id, $stranger->id]])->assertStatus(422);
        $this->as()->postJson("/api/v1/projects/{$this->project->id}/forms", ['name' => 'F', 'definition' => $this->definition(), 'follow_up_minutes' => 1])->assertStatus(422);
        $this->assertSame(0, LeadForm::withoutGlobalScopes()->count());
    }

    public function test_with_nobody_to_take_it_the_lead_is_left_unassigned_for_the_managers_queue(): void
    {
        $slug = $this->publishedForm(['follow_up_minutes' => 30]);

        $this->postJson("/api/v1/public/forms/{$slug}/submissions", $this->submission())->assertCreated();

        $lead = Lead::withoutGlobalScopes()->firstOrFail();
        $this->assertNull($lead->owner_id);
        $this->assertNull($lead->next_follow_up_at, 'A follow-up promised to nobody is not a promise.');
    }
}
