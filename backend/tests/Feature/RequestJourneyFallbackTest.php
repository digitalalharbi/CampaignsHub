<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Requests\Journey\RequestStage;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Requests\Services\RequestJourneyService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REQ-JOURNEY-FALLBACK-001 / REQ-DETAIL-LABELS-001 — a request the journey never wrote is read from
 * its status, and the stage speaks the reader's language.
 *
 * The live review of /portal/clients/:slug/requests/:reference (2026-10-09) showed a request whose
 * header said «قيد التنفيذ» while its journey rail said «Draft» — in English, on an Arabic page.
 */
final class RequestJourneyFallbackTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RequestCatalogSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency-'.uniqid(), 'status' => 'active', 'is_default_portal' => true, 'portal_enabled' => true]);
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    private function request(string $statusKey, ?string $journeyStage): ExternalRequest
    {
        return ExternalRequest::create([
            'tenant_id' => $this->tenant->id,
            'reference' => 'REQ-2026-'.strtoupper(bin2hex(random_bytes(3))),
            'module' => 'paid_media',
            'type_id' => RequestType::where('key', 'paid_campaign_launch')->value('id'),
            'status_id' => RequestStatus::where('key', $statusKey)->value('id'),
            'priority' => 'medium',
            'source' => 'public_portal',
            'contact_name' => 'Client Co',
            'contact_email' => 'c@co.test',
            'journey_stage' => $journeyStage,
        ]);
    }

    public function test_a_request_the_journey_never_wrote_is_read_from_its_status(): void
    {
        $service = app(RequestJourneyService::class);

        $this->assertSame(RequestStage::InProgress, $service->currentStage($this->request('in_progress', null)));
        $this->assertSame(RequestStage::Draft, $service->currentStage($this->request('new', null)), 'a new request has not begun its journey');
        $this->assertSame(RequestStage::Completed, $service->currentStage($this->request('completed', null)));
    }

    public function test_a_journey_stage_that_was_written_is_the_truth_even_when_it_is_draft(): void
    {
        $service = app(RequestJourneyService::class);

        $this->assertSame(RequestStage::Draft, $service->currentStage($this->request('in_progress', 'draft')));
        $this->assertSame(RequestStage::OnHold, $service->currentStage($this->request('in_progress', 'on_hold')));
    }

    public function test_every_stage_has_an_arabic_name_and_the_label_follows_the_locale(): void
    {
        foreach (RequestStage::cases() as $stage) {
            $this->assertNotSame('', $stage->labelAr(), $stage->value.' has no Arabic name');
            $this->assertSame(1, preg_match('/\p{Arabic}/u', $stage->labelAr()), $stage->value.' Arabic name is not Arabic');
        }
        $this->assertSame('مسودة', RequestStage::Draft->labelFor('ar'));
        $this->assertSame('Draft', RequestStage::Draft->labelFor('en'));
        $this->assertSame('قيد التنفيذ', RequestStage::InProgress->labelFor('ar'));
    }
}
