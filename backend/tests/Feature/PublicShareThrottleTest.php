<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Middleware\ConditionalThrottle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * The public link's own limiter, and the switch it now goes through.
 *
 * Sixty reads a minute per IP protects the FIGURES endpoint from enumeration. It was hand-rolled in
 * the controller and was the one limiter in the product that ignored
 * `ConditionalThrottle::relaxationAllowed()` — every other goes through the middleware that consults
 * it. That cost real time in the gate, where a whole browser suite runs from one IP and two report
 * spec files together spend more than sixty requests in a minute: the failure surfaced as «تعذّر فتح
 * التقرير — Too many requests» in whichever test landed on the boundary, in three different files
 * across three runs, each looking like an unrelated flake.
 *
 * ## What must stay true, and is asserted here
 *
 * The limit is a security control, and relaxing it is allow-listed to `local` WITH an explicit flag.
 * `testing` keeps it on by default — which is what lets this file test the behaviour at all — and
 * production and staging refuse relaxation whatever the flag says. All three are asserted, because
 * «the gate is quieter now» must not have been bought with a hole in a live install.
 */
final class PublicShareThrottleTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'A', 'slug' => 'a-throttle', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);

        $client = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-throttle', 'mode' => 'managed', 'status' => 'active']);
        $project = Project::create(['client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
        $report = Report::create([
            'project_id' => $project->id, 'name' => 'R', 'type' => 'project', 'status' => 'completed',
            'period_start' => '2026-08-01', 'period_end' => '2026-08-30', 'currency' => 'USD',
            'data' => ['kpis' => []],
        ]);

        $this->token = 'tok-'.bin2hex(random_bytes(8));
        ReportShare::create([
            'tenant_id' => $tenant->id,
            'report_id' => $report->id,
            'token_hash' => hash('sha256', $this->token),
            'allow_download' => false,
        ]);
    }

    /** The ceiling is real: the sixty-first read in a minute is refused, not served. */
    public function test_the_figures_endpoint_still_refuses_a_flood(): void
    {
        $this->assertFalse(
            ConditionalThrottle::relaxationAllowed(),
            'the testing environment must keep limits on, or this file proves nothing',
        );

        for ($i = 0; $i < 60; $i++) {
            $this->getJson("/api/v1/reports/shared/{$this->token}")->assertOk();
        }

        $this->getJson("/api/v1/reports/shared/{$this->token}")->assertStatus(429);
    }

    /**
     * And with the switch on, in `local`, the same flood is served.
     *
     * This is the gate's case and nothing else's: `relaxationAllowed()` is a positive allow-list, so
     * the environment has to BE local as well as carrying the flag.
     */
    public function test_the_local_gate_switch_relaxes_it(): void
    {
        app()['env'] = 'local';
        Config::set('security.relax_rate_limits', true);

        $this->assertTrue(ConditionalThrottle::relaxationAllowed());

        for ($i = 0; $i < 70; $i++) {
            $this->getJson("/api/v1/reports/shared/{$this->token}")->assertOk();
        }
    }

    /**
     * Production refuses the relaxation whatever the flag says.
     *
     * The flag is set by a checked-in file that a deployment could inherit; this is the line that
     * makes that harmless, and it is the reason the controller was changed to ask rather than to read
     * the config itself.
     */
    public function test_production_ignores_the_flag_entirely(): void
    {
        app()['env'] = 'production';
        Config::set('security.relax_rate_limits', true);

        $this->assertFalse(ConditionalThrottle::relaxationAllowed());
    }
}
