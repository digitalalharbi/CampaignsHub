<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Accounts\Capabilities\Capabilities;
use App\Domains\Accounts\Capabilities\CapabilitySwitch;
use App\Domains\Alerts\Http\Controllers\AlertController;
use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertEvaluator;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Forms\Models\LeadForm;
use App\Domains\Forms\Models\LeadFormSubmission;
use App\Domains\Forms\Models\LeadFormVersion;
use App\Domains\Forms\Support\FormDefinition;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TRACKING-HEALTH-001 — the page's findings, raised through the alerts engine that already exists.
 *
 * Only what the page calls broken or lossy is raised (never «could not be checked»), each finding is its own alert with its
 * own severity and both languages, a fixed finding resolves itself and says so, and the engine's cooldown and tenant
 * boundaries are the engine's own.
 */
final class TrackingHealthAlertTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active', 'enabled_modules' => ['paid_media', 'advanced_tracking']]);
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
        CapabilitySwitch::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'scope_type' => 'tenant', 'scope_key' => $this->tenant->id, 'module' => 'advanced_tracking'],
            ['scope_id' => null, 'enabled' => true],
        );
        app(Capabilities::class)->bump((string) $this->tenant->id);
    }

    private function rule(array $over = []): AlertRule
    {
        return AlertRule::create(array_merge([
            'tenant_id' => $this->tenant->id, 'type' => 'tracking_health', 'name' => 'Tracking health',
            'cooldown_minutes' => 720, 'channels' => ['in_app'], 'severity' => 'info', 'active' => true,
        ], $over));
    }

    private function publishedForm(?Project $project = null, string $name = 'Villa'): LeadForm
    {
        $project ??= $this->project;
        $form = LeadForm::create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'name' => $name, 'status' => 'published']);
        $version = LeadFormVersion::create([
            'tenant_id' => $this->tenant->id, 'lead_form_id' => $form->id, 'version' => 1, 'published_at' => Carbon::now(),
            'definition' => FormDefinition::normalise([
                'steps' => [['id' => 's1', 'fields' => [
                    ['id' => 'full_name', 'type' => 'text', 'label' => 'Name', 'maps_to' => 'name'],
                    ['id' => 'mobile', 'type' => 'phone', 'label' => 'Mobile', 'maps_to' => 'phone'],
                ]]],
                'consent' => ['required' => true, 'text' => ['en' => 'Contact me.']],
            ]),
            'checksum' => str_repeat('a', 64),
        ]);
        $form->forceFill(['published_version_id' => $version->id])->save();

        return $form;
    }

    private function views(LeadForm $form, int $n): void
    {
        $now = Carbon::now();
        $rows = [];
        for ($i = 1; $i <= $n; $i++) {
            $rows[] = [
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $form->project_id, 'event_type' => 'form_view',
                'subject_type' => 'lead_form', 'subject_id' => $form->id, 'source' => 'first_party_form', 'occurred_at' => $now, 'recorded_at' => $now,
                'consent_state' => 'unknown', 'visitor_confidence' => 'none', 'is_bot' => false, 'session_id' => 'ses-'.str_pad((string) $i, 10, '0', STR_PAD_LEFT),
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::table('tracking_events')->insert($rows);
    }

    private function submit(LeadForm $form): void
    {
        LeadFormSubmission::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $form->project_id, 'lead_form_id' => $form->id, 'lead_form_version_id' => $form->published_version_id,
            'idempotency_key' => 'k-'.Str::random(16), 'consent_state' => 'granted', 'submitted_at' => Carbon::now(),
        ]);
    }

    public function test_a_broken_form_is_raised_critical_in_both_languages_even_on_a_rule_saved_as_info(): void
    {
        $form = $this->publishedForm();
        $this->views($form, 22);
        $rule = $this->rule();

        $raised = app(AlertEvaluator::class)->evaluateRule($rule);

        $this->assertGreaterThanOrEqual(1, $raised);
        $event = AlertEvent::query()->where('rule_id', $rule->id)->where('entity_id', 'like', '%form_broken:'.$form->id)->firstOrFail();
        $this->assertSame('critical', $event->severity, 'The finding\'s own severity wins over the rule\'s.');
        $this->assertSame((string) $this->project->id, (string) $event->project_id);
        $this->assertStringContainsString('never sent', $event->context['title']);
        $this->assertStringContainsString('يُشاهَد ولا يُرسَل', $event->context['title_ar']);
        $this->assertNotSame($event->context['message'], $event->context['message_ar']);
    }

    public function test_what_could_not_be_checked_or_is_fine_or_merely_informative_raises_nothing(): void
    {
        $form = $this->publishedForm();
        $this->views($form, 3);
        $this->submit($form);   // viewed and sent: fine; coverage thin: unavailable; no destinations: info

        $rule = $this->rule();

        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($rule));
        $this->assertSame(0, AlertEvent::query()->where('rule_id', $rule->id)->count());
    }

    public function test_a_project_with_no_published_form_has_nothing_to_say_and_a_rule_can_be_scoped_to_one_project(): void
    {
        $other = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->project->client_workspace_id, 'name' => 'Q', 'status' => 'active']);
        $broken = $this->publishedForm($other, 'Other');
        $this->views($broken, 25);

        $scoped = $this->rule(['project_id' => $this->project->id]);
        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($scoped), 'This project has no published form; the other project\'s broken form is not its business.');

        $wide = $this->rule(['name' => 'wide']);
        $this->assertGreaterThanOrEqual(1, app(AlertEvaluator::class)->evaluateRule($wide));
        $this->assertSame((string) $other->id, (string) AlertEvent::query()->where('rule_id', $wide->id)->firstOrFail()->project_id);
    }

    public function test_the_engines_cooldown_keeps_one_alert_per_finding_and_a_fixed_finding_resolves_and_says_so(): void
    {
        $form = $this->publishedForm();
        $this->views($form, 22);
        $rule = $this->rule();
        $evaluator = app(AlertEvaluator::class);

        $evaluator->evaluateRule($rule);
        $evaluator->evaluateRule($rule);
        $broken = fn () => AlertEvent::query()->where('rule_id', $rule->id)->where('entity_id', 'like', '%form_broken:%');
        $this->assertSame(1, $broken()->count(), 'A second sweep inside the cooldown is the same alert.');
        $this->assertSame('open', $broken()->firstOrFail()->status);

        // Somebody fixes the form: it is sent.
        $this->submit($form);
        $evaluator->evaluateRule($rule);

        $this->assertSame('resolved', $broken()->firstOrFail()->status, 'The alert closed itself when its condition ended.');
    }

    public function test_another_workspaces_forms_are_never_swept_by_this_rule(): void
    {
        $rival = Tenant::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'status' => 'active', 'enabled_modules' => ['paid_media', 'advanced_tracking']]);
        app(TenantContext::class)->setTenantId((string) $rival->id);
        $client = ClientWorkspace::create(['tenant_id' => $rival->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $theirs = Project::create(['tenant_id' => $rival->id, 'client_workspace_id' => $client->id, 'name' => 'Theirs', 'status' => 'active']);
        LeadForm::create(['tenant_id' => $rival->id, 'project_id' => $theirs->id, 'name' => 'Theirs', 'status' => 'published']);
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);

        $rule = $this->rule();

        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($rule));
        $this->assertSame(0, AlertEvent::withoutGlobalScopes()->where('tenant_id', $rival->id)->count());
    }

    public function test_the_type_is_creatable_through_the_alerts_api_and_is_in_the_evaluators_periodic_set(): void
    {
        $this->assertContains('tracking_health', AlertEvaluator::PERIODIC);
        $this->assertContains('tracking_health', (new \ReflectionClassConstant(AlertController::class, 'TYPES'))->getValue());
    }
}
