<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Forms\Conversions\ConversionTransport;
use App\Domains\Forms\Jobs\DeliverConversion;
use App\Domains\Forms\Models\ConversionDelivery;
use App\Domains\Forms\Models\ConversionDestination;
use App\Domains\Forms\Models\LeadForm;
use App\Domains\Forms\Models\LeadFormSubmission;
use App\Domains\Forms\Models\LeadFormVersion;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * FORMS-DISPATCH-001 — a stored lead's conversion, reported to GA4 / Meta / a server-side GTM container only when
 * consent was given, once, with identifiers hashed, from a destination the team made live on purpose.
 *
 * Every send here is faked: nothing in this suite contacts a platform.
 */
final class LeadFormConversionsTest extends TestCase
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

    /** A fresh fake: a second `Http::fake` only appends, and the first stub that matches wins. */
    private function httpReturns(mixed $response): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => $response]);
    }

    private function as()
    {
        return $this->actingAs($this->owner, 'sanctum')->withHeaders(self::SPA);
    }

    private function slug(bool $consentRequired = true, bool $asksMeasurement = true): string
    {
        $definition = [
            'steps' => [['id' => 's1', 'title' => 'You', 'fields' => [
                ['id' => 'full_name', 'type' => 'text', 'label' => 'Name', 'maps_to' => 'name'],
                ['id' => 'mobile', 'type' => 'phone', 'label' => 'Mobile', 'maps_to' => 'phone'],
                ['id' => 'email', 'type' => 'email', 'label' => 'Email', 'maps_to' => 'email'],
            ]]],
            'consent' => ['required' => $consentRequired, 'text' => ['en' => 'May we contact you?'], 'measurement' => ['enabled' => $asksMeasurement, 'text' => ['en' => 'You may report my hashed details to measurement and ad platforms.']]],
        ];
        $id = $this->as()->postJson("/api/v1/projects/{$this->project->id}/forms", ['name' => 'Villa enquiry', 'definition' => $definition])->assertCreated()->json('data.id');
        $this->as()->postJson("/api/v1/projects/{$this->project->id}/forms/{$id}/publish")->assertOk();

        return (string) LeadForm::withoutGlobalScopes()->findOrFail($id)->slug;
    }

    /** @return array<string,mixed> */
    private function submission(bool $consent = true, bool $measurement = true): array
    {
        return [
            'submission_id' => 'sub-'.bin2hex(random_bytes(8)),
            'session_id' => 'ses-'.bin2hex(random_bytes(6)),
            'answers' => ['full_name' => 'Sara Al-Qahtani', 'mobile' => '0500000123', 'email' => 'Sara@Example.test'],
            'consent' => ['accepted' => $consent, 'measurement' => $measurement],
            'attribution' => ['utm_source' => 'meta', 'utm_campaign' => 'q4', 'fbclid' => 'AbCdEf12345', 'ga_client_id' => '1234567890.1700000000', 'landing_path' => '/villas?x=1'],
        ];
    }

    /** @param array<string,mixed> $over @return array<string,mixed> */
    private function ga4(array $over = []): array
    {
        return array_replace_recursive(['kind' => 'ga4', 'name' => 'GA4', 'config' => ['measurement_id' => 'G-ABC123XYZ9'], 'secrets' => ['api_secret' => 'SECRET-ga4-value']], $over);
    }

    private function create(array $body): string
    {
        return (string) $this->as()->postJson("/api/v1/projects/{$this->project->id}/conversion-destinations", $body)->assertCreated()->json('data.id');
    }

    private function live(string $id): void
    {
        $this->as()->patchJson("/api/v1/projects/{$this->project->id}/conversion-destinations/{$id}", ['status' => 'live', 'confirm_live' => true])->assertOk();
    }

    public function test_a_test_destination_records_the_exact_hashed_payload_and_sends_nothing(): void
    {
        Http::fake();
        $this->create($this->ga4());
        $body = $this->submission();
        $slug = $this->slug();

        $this->postJson("/api/v1/public/forms/{$slug}/submissions", $body)->assertCreated();

        Http::assertNothingSent();
        $delivery = ConversionDelivery::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('test', $delivery->status);
        $this->assertSame($body['submission_id'] === '' ? '' : (string) $delivery->event_id, (string) $delivery->event_id);
        $payload = $delivery->payload;
        $this->assertSame('generate_lead', $payload['body']['events'][0]['name']);
        $this->assertSame('1234567890.1700000000', $payload['body']['client_id'], 'The visit\'s own GA client id joins the event to its session.');
        $this->assertSame([hash('sha256', 'sara@example.test')], $payload['body']['user_data']['sha256_email_address']);
        $this->assertSame([hash('sha256', '966500000123')], $payload['body']['user_data']['sha256_phone_number']);
        $encoded = json_encode($payload);
        foreach (['sara@example.test', 'Sara', '0500000123', 'SECRET-ga4-value'] as $raw) {
            $this->assertStringNotContainsString($raw, $encoded, 'Neither an identifier nor a secret is stored in the clear.');
        }
    }

    public function test_a_visitor_who_did_not_agree_to_measurement_is_recorded_as_skipped_and_nothing_is_built(): void
    {
        Http::fake();
        $this->create($this->ga4());
        $slug = $this->slug();

        // Consent to be contacted, but NOT to their identifiers being reported to platforms: the lead is stored, no conversion leaves.
        $this->postJson("/api/v1/public/forms/{$slug}/submissions", $this->submission(consent: true, measurement: false))->assertCreated();

        $delivery = ConversionDelivery::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('skipped_consent', $delivery->status);
        $this->assertNull($delivery->payload);
        $this->assertStringContainsString('did not agree', (string) $delivery->error);
        $this->assertSame('denied', LeadFormSubmission::withoutGlobalScopes()->firstOrFail()->measurement_consent);
        Http::assertNothingSent();
    }

    public function test_a_form_that_never_asked_for_measurement_consent_reports_nothing(): void
    {
        Http::fake();
        $this->create($this->ga4());
        $slug = $this->slug(asksMeasurement: false);

        // A submitter cannot grant what the version never offered: the claim in the body is ignored.
        $this->postJson("/api/v1/public/forms/{$slug}/submissions", $this->submission(consent: true, measurement: true))->assertCreated();

        $this->assertSame('not_asked', LeadFormSubmission::withoutGlobalScopes()->firstOrFail()->measurement_consent);
        $delivery = ConversionDelivery::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('skipped_consent', $delivery->status);
        $this->assertStringContainsString('did not ask', (string) $delivery->error);
        Http::assertNothingSent();
    }

    public function test_a_live_destination_sends_once_with_the_secret_on_the_request_and_not_in_the_ledger(): void
    {
        Http::fake(['*' => Http::response([], 204)]);
        $id = $this->create($this->ga4());
        $this->live($id);
        $slug = $this->slug();

        $this->postJson("/api/v1/public/forms/{$slug}/submissions", $this->submission())->assertCreated();

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $r): bool {
            return str_starts_with($r->url(), 'https://www.google-analytics.com/mp/collect?')
                && str_contains($r->url(), 'measurement_id=G-ABC123XYZ9') && str_contains($r->url(), 'api_secret=SECRET-ga4-value')
                && $r['events'][0]['name'] === 'generate_lead';
        });
        $delivery = ConversionDelivery::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('sent', $delivery->status);
        $this->assertSame(204, $delivery->response_code);
        $this->assertNotNull($delivery->sent_at);
        $this->assertStringNotContainsString('SECRET-ga4-value', json_encode($delivery->toArray()));
    }

    public function test_a_replayed_submission_never_creates_a_second_delivery_for_a_destination(): void
    {
        Http::fake(['*' => Http::response([], 204)]);
        $this->live($this->create($this->ga4()));
        $slug = $this->slug();
        $body = $this->submission();

        $this->postJson("/api/v1/public/forms/{$slug}/submissions", $body)->assertCreated();
        $this->postJson("/api/v1/public/forms/{$slug}/submissions", $body)->assertOk();

        $this->assertSame(1, ConversionDelivery::withoutGlobalScopes()->count());
        Http::assertSentCount(1);
    }

    public function test_a_transient_failure_stays_queued_for_retry_and_a_permanent_one_is_recorded_as_failed(): void
    {
        Queue::fake();
        $id = $this->create($this->ga4());
        $this->live($id);
        $slug = $this->slug();
        $this->postJson("/api/v1/public/forms/{$slug}/submissions", $this->submission())->assertCreated();
        $delivery = ConversionDelivery::withoutGlobalScopes()->firstOrFail();
        Queue::assertPushed(DeliverConversion::class);

        $this->httpReturns(Http::response('try later', 503));
        (new DeliverConversion((string) $delivery->id))->handle(app(ConversionTransport::class));
        $delivery->refresh();
        $this->assertSame('queued', $delivery->status, 'A 503 may succeed later.');
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(503, $delivery->response_code);
        $this->assertNotNull($delivery->next_attempt_at);

        $this->httpReturns(Http::response('bad secret SECRET-ga4-value', 403));
        (new DeliverConversion((string) $delivery->id))->handle(app(ConversionTransport::class));
        $delivery->refresh();
        $this->assertSame('failed', $delivery->status, 'A 403 will not succeed by repeating it.');
        $this->assertStringNotContainsString('SECRET-ga4-value', (string) $delivery->error);
        $this->assertStringContainsString('***', (string) $delivery->error);

        // And the team can put it back in the queue.
        $this->httpReturns(Http::response([], 204));
        $this->as()->postJson("/api/v1/projects/{$this->project->id}/conversion-deliveries/{$delivery->id}/retry")->assertOk();
        Queue::assertPushed(DeliverConversion::class, 2);
        (new DeliverConversion((string) $delivery->id))->handle(app(ConversionTransport::class));
        $this->assertSame('sent', $delivery->refresh()->status);
    }

    public function test_a_destination_switched_off_after_queueing_is_not_contacted(): void
    {
        Queue::fake();
        Http::fake();
        $id = $this->create($this->ga4());
        $this->live($id);
        $slug = $this->slug();
        $this->postJson("/api/v1/public/forms/{$slug}/submissions", $this->submission())->assertCreated();
        $this->as()->patchJson("/api/v1/projects/{$this->project->id}/conversion-destinations/{$id}", ['status' => 'off'])->assertOk();

        $delivery = ConversionDelivery::withoutGlobalScopes()->firstOrFail();
        (new DeliverConversion((string) $delivery->id))->handle(app(ConversionTransport::class));

        Http::assertNothingSent();
        $this->assertSame('failed', $delivery->refresh()->status);
    }

    public function test_meta_gets_its_own_shape_with_the_same_event_id_and_hashed_identifiers(): void
    {
        Http::fake();
        $this->create(['kind' => 'meta_capi', 'name' => 'Meta', 'config' => ['pixel_id' => '123456789012345', 'event_name' => 'Lead'], 'secrets' => ['access_token' => 'TOKEN-meta-value']]);
        $slug = $this->slug();

        $this->postJson("/api/v1/public/forms/{$slug}/submissions", $this->submission())->assertCreated();

        $delivery = ConversionDelivery::withoutGlobalScopes()->firstOrFail();
        $row = $delivery->payload['body']['data'][0];
        $this->assertSame('Lead', $row['event_name']);
        $this->assertSame((string) $delivery->event_id, $row['event_id'], 'The browser tag receives this id too, so one conversion is counted once.');
        $this->assertSame('website', $row['action_source']);
        $this->assertSame([hash('sha256', 'sara@example.test')], $row['user_data']['em']);
        $this->assertSame([hash('sha256', 'sara')], $row['user_data']['fn']);
        $this->assertStringStartsWith('fb.1.', $row['user_data']['fbc']);
        $this->assertStringEndsWith('AbCdEf12345', $row['user_data']['fbc']);
        $this->assertStringContainsString('/villas', $row['event_source_url']);
        $this->assertStringNotContainsString('x=1', $row['event_source_url'], 'The landing path carries no query string.');
        $this->assertStringNotContainsString('TOKEN-meta-value', json_encode($delivery->payload));
    }

    public function test_going_live_needs_explicit_confirmation_and_the_credential_and_secrets_are_never_returned(): void
    {
        $id = $this->create($this->ga4(['secrets' => ['api_secret' => '']]));
        $url = "/api/v1/projects/{$this->project->id}/conversion-destinations/{$id}";

        $this->as()->patchJson($url, ['status' => 'live'])->assertStatus(422);
        $this->as()->patchJson($url, ['status' => 'live', 'confirm_live' => true])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'api_secret'));

        $this->as()->patchJson($url, ['secrets' => ['api_secret' => 'SECRET-ga4-value']])->assertOk();
        $listed = $this->as()->getJson("/api/v1/projects/{$this->project->id}/conversion-destinations")->assertOk();
        $this->assertStringNotContainsString('SECRET-ga4-value', $listed->getContent());
        $listed->assertJsonPath('data.0.secrets_set.api_secret', true)->assertJsonPath('data.0.status', 'test');

        $this->as()->patchJson($url, ['status' => 'live', 'confirm_live' => true])->assertOk()->assertJsonPath('data.status', 'live');
        $this->assertNotNull(ConversionDestination::withoutGlobalScopes()->findOrFail($id)->went_live_at);
        $this->assertSame('SECRET-ga4-value', ConversionDestination::withoutGlobalScopes()->findOrFail($id)->secrets['api_secret']);
        $this->assertStringNotContainsString('SECRET-ga4-value', (string) \DB::table('conversion_destinations')->value('secrets'), 'Encrypted at rest.');
    }

    public function test_only_platforms_with_a_builder_can_be_created_and_settings_are_validated(): void
    {
        $url = "/api/v1/projects/{$this->project->id}/conversion-destinations";

        foreach (['tiktok_events', 'snapchat_capi', 'google_ads', 'linkedin_capi', 'x_conversions', 'made_up'] as $kind) {
            $this->as()->postJson($url, ['kind' => $kind, 'name' => 'X', 'config' => ['pixel_id' => '123456789']])->assertStatus(422);
        }
        $this->as()->postJson($url, $this->ga4(['config' => ['measurement_id' => 'not-an-id']]))->assertStatus(422);
        $this->as()->postJson($url, ['kind' => 'gtm_server', 'name' => 'S', 'config' => ['endpoint_url' => 'http://insecure.test/x']])->assertStatus(422);
        $this->assertSame(0, ConversionDestination::withoutGlobalScopes()->count());
    }

    public function test_diagnostics_use_a_synthetic_lead_and_contact_the_platform_only_when_live_with_credentials(): void
    {
        Http::fake(['*' => Http::response(['validationMessages' => []], 200)]);
        $id = $this->create($this->ga4());
        $diagnose = "/api/v1/projects/{$this->project->id}/conversion-destinations/{$id}/diagnose";

        $test = $this->as()->postJson($diagnose)->assertOk()->json('data');
        Http::assertNothingSent();
        $this->assertTrue($test['synthetic']);
        $this->assertNull($test['validation']);
        $this->assertSame([hash('sha256', 'diagnostic@example.invalid')], $test['payload']['body']['user_data']['sha256_email_address']);

        $this->live($id);
        $live = $this->as()->postJson($diagnose)->assertOk()->json('data');
        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r): bool => str_starts_with($r->url(), 'https://www.google-analytics.com/debug/mp/collect?'));
        $this->assertTrue($live['validation']['ok']);

        $this->httpReturns(Http::response(['validationMessages' => [['description' => 'name is reserved']]], 200));
        $bad = $this->as()->postJson($diagnose)->assertOk()->json('data');
        $this->assertFalse($bad['validation']['ok']);
        $this->assertStringContainsString('name is reserved', $bad['validation']['error']);
    }

    public function test_a_form_scoped_destination_hears_only_its_own_form_and_another_project_cannot_reach_it(): void
    {
        Http::fake();
        $slug = $this->slug();
        $form = LeadForm::withoutGlobalScopes()->where('slug', $slug)->firstOrFail();
        $other = $this->as()->postJson("/api/v1/projects/{$this->project->id}/forms", ['name' => 'Other', 'definition' => LeadFormVersion::withoutGlobalScopes()->where('lead_form_id', $form->id)->orderByDesc('version')->firstOrFail()->definition])->assertCreated()->json('data.id');
        $this->as()->postJson("/api/v1/projects/{$this->project->id}/forms/{$other}/publish")->assertOk();
        $otherSlug = (string) LeadForm::withoutGlobalScopes()->findOrFail($other)->slug;

        $id = $this->create($this->ga4(['lead_form_id' => (string) $form->id]));
        $this->postJson("/api/v1/public/forms/{$slug}/submissions", $this->submission())->assertCreated();
        $this->postJson("/api/v1/public/forms/{$otherSlug}/submissions", $this->submission())->assertCreated();

        $this->assertSame(1, ConversionDelivery::withoutGlobalScopes()->count(), 'Only the form the destination was made for.');
        $this->as()->patchJson("/api/v1/projects/{$this->other->id}/conversion-destinations/{$id}", ['name' => 'stolen'])->assertNotFound();
        $this->as()->getJson("/api/v1/projects/{$this->other->id}/conversion-deliveries")->assertOk()->assertJsonCount(0, 'data');
        $this->as()->getJson("/api/v1/projects/{$this->project->id}/conversion-deliveries")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_person_without_the_permission_cannot_manage_destinations(): void
    {
        $viewer = User::create(['name' => 'V', 'email' => uniqid().'@v.test', 'password' => 'secret123']);
        $this->grantMembership($viewer, $this->tenant);

        $this->actingAs($viewer, 'sanctum')->withHeaders(self::SPA)->postJson("/api/v1/projects/{$this->project->id}/conversion-destinations", $this->ga4())->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->withHeaders(self::SPA)->getJson("/api/v1/projects/{$this->project->id}/conversion-destinations")->assertForbidden();
    }

    public function test_the_page_loads_a_tag_manager_container_only_when_the_team_made_it_live_and_it_delivers_nothing_itself(): void
    {
        Http::fake();
        $slug = $this->slug();
        $id = $this->create(['kind' => 'gtm_web', 'name' => 'Web container', 'config' => ['container_id' => 'gtm-abc1234']]);

        $this->getJson("/api/v1/public/forms/{$slug}")->assertOk()->assertJsonPath('data.tracking.gtm_container_id', null);

        $this->live($id);
        $this->getJson("/api/v1/public/forms/{$slug}")->assertOk()->assertJsonPath('data.tracking.gtm_container_id', 'GTM-ABC1234');

        // It configures the PAGE; a submission produces no server delivery for it.
        $this->postJson("/api/v1/public/forms/{$slug}/submissions", $this->submission())->assertCreated();
        $this->assertSame(0, ConversionDelivery::withoutGlobalScopes()->count());
        Http::assertNothingSent();

        $this->as()->postJson("/api/v1/projects/{$this->project->id}/conversion-destinations", ['kind' => 'gtm_web', 'name' => 'Bad', 'config' => ['container_id' => 'not-gtm']])->assertStatus(422);
    }

    public function test_the_answer_carries_the_deduplication_id_the_browser_tag_must_use(): void
    {
        $this->create(['kind' => 'meta_capi', 'name' => 'Meta', 'config' => ['pixel_id' => '123456789012345'], 'secrets' => ['access_token' => 'TOKEN-x']]);
        $slug = $this->slug();

        $eventId = $this->postJson("/api/v1/public/forms/{$slug}/submissions", $this->submission())->assertCreated()->json('data.event_id');

        $this->assertSame($eventId, (string) ConversionDelivery::withoutGlobalScopes()->firstOrFail()->event_id);
        $this->assertSame($eventId, LeadFormSubmission::withoutGlobalScopes()->firstOrFail()->id);
    }
}
