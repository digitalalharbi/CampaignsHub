<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RequestSlaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RequestCatalogSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    private function makeRequest(array $overrides = []): ExternalRequest
    {
        $r = new ExternalRequest;
        $r->forceFill(array_merge([
            'tenant_id' => $this->tenant->id,
            'reference' => 'REQ-2026-'.strtoupper(substr(md5((string) mt_rand()), 0, 6)),
            'module' => 'paid_media',
            'type_id' => RequestType::where('key', 'paid_campaign_launch')->value('id'),
            'status_id' => RequestStatus::where('key', 'new')->value('id'),
            'priority' => 'medium',
            'source' => 'public_portal',
            'contact_name' => 'C', 'contact_email' => 'c@x.test',
            'is_external' => true,
            'submitted_at' => now(),
        ], $overrides))->save();

        return $r->refresh();
    }

    public function test_warning_fires_once_within_threshold(): void
    {
        $req = $this->makeRequest(['sla_due_at' => now()->addHours(2)]); // inside the 4h warning window

        $this->artisan('requests:evaluate-sla')->assertSuccessful();
        $req->refresh();
        $this->assertNotNull($req->sla_warned_at);
        $this->assertNull($req->sla_breached_at);
        $this->assertEquals(1, AppNotification::where('type', 'request.sla_warning')->count());

        // Re-run is idempotent — no duplicate warning.
        $this->artisan('requests:evaluate-sla')->assertSuccessful();
        $this->assertEquals(1, AppNotification::where('type', 'request.sla_warning')->count());
    }

    public function test_breach_is_detected_once_and_is_idempotent(): void
    {
        $req = $this->makeRequest(['sla_due_at' => now()->subHour()]); // overdue

        $this->artisan('requests:evaluate-sla')->assertSuccessful();
        $req->refresh();
        $this->assertNotNull($req->sla_breached_at);
        $this->assertEquals(1, AppNotification::where('type', 'request.sla_breached')->count());

        $this->artisan('requests:evaluate-sla')->assertSuccessful();
        $this->assertEquals(1, AppNotification::where('type', 'request.sla_breached')->count());
    }

    public function test_paused_and_terminal_and_archived_requests_are_not_breached(): void
    {
        $paused = $this->makeRequest(['sla_due_at' => now()->subHour(), 'sla_paused_at' => now()->subMinutes(30)]);
        $completed = $this->makeRequest(['sla_due_at' => now()->subHour(), 'status_id' => RequestStatus::where('key', 'completed')->value('id')]);
        $archived = $this->makeRequest(['sla_due_at' => now()->subHour(), 'archived_at' => now()]);

        $this->artisan('requests:evaluate-sla')->assertSuccessful();

        $this->assertNull($paused->refresh()->sla_breached_at);
        $this->assertNull($completed->refresh()->sla_breached_at);
        $this->assertNull($archived->refresh()->sla_breached_at);
        $this->assertEquals(0, AppNotification::where('type', 'request.sla_breached')->count());
    }
}
