<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FundingFixtures;
use Tests\TestCase;

/** Nov overhaul, task 7 — every client at once with what needs an action, and the failure reason on a source. */
final class NovIntegrationsOverviewTest extends TestCase
{
    use FundingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFunding(['integrations.view', 'clients.view', 'clients.view_all', 'projects.view.all']);
    }

    /** @return array<string, array<string, mixed>> keyed by client name */
    private function overview(): array
    {
        $rows = $this->actingAs($this->finance, 'sanctum')->getJson('/api/v1/integrations/clients-overview')->assertOk()->json('data');

        return array_column($rows, null, 'client_name');
    }

    public function test_each_client_says_whether_a_source_needs_an_action_and_why(): void
    {
        $failed = $this->newProject('P1', 'Alpha');
        $healthy = $this->newProject('P2', 'Beta');
        $old = $this->newProject('P3', 'Gamma');
        $this->newProject('P4', 'Delta');                                  // no source at all

        foreach ([[$failed, 'meta'], [$healthy, 'snapchat'], [$old, 'tiktok']] as [$project, $provider]) {
            $this->limitSpend($project, 1, 10, provider: $provider);          // selects the account for the project
        }
        $this->account($failed, 'meta')->forceFill(['last_synced_at' => now()->subMinutes(10), 'last_sync_error_category' => 'token_invalidated'])->save();
        $this->account($healthy, 'snapchat')->forceFill(['last_synced_at' => now()->subMinutes(10), 'last_sync_error_category' => null])->save();
        $this->account($old, 'tiktok')->forceFill(['last_synced_at' => now()->subHours(5), 'last_sync_error_category' => null])->save();

        $by = $this->overview();

        $this->assertTrue($by['Alpha']['needs_action']);
        $this->assertSame('failed', $by['Alpha']['issues'][0]['reason']);
        $this->assertSame('token_invalidated', $by['Alpha']['issues'][0]['error_category']);
        $this->assertSame('meta', $by['Alpha']['issues'][0]['provider']);

        $this->assertFalse($by['Beta']['needs_action']);
        $this->assertSame(1, $by['Beta']['sources']);
        $this->assertNotNull($by['Beta']['last_success_at']);

        $this->assertTrue($by['Gamma']['needs_action']);
        $this->assertSame('old', $by['Gamma']['issues'][0]['reason']);

        $this->assertSame(0, $by['Delta']['sources'], 'a client with no source is listed, never hidden');
        $this->assertFalse($by['Delta']['needs_action']);
    }

    public function test_the_meta_counts_the_clients_that_need_an_action(): void
    {
        $p = $this->newProject('P1', 'Alpha');
        $this->limitSpend($p, 1, 10, provider: 'meta');
        $this->account($p, 'meta')->forceFill(['last_synced_at' => now()->subMinutes(5), 'last_sync_error_category' => 'provider_error'])->save();
        $this->newProject('P2', 'Beta');

        $this->actingAs($this->finance, 'sanctum')->getJson('/api/v1/integrations/clients-overview')->assertOk()
            ->assertJsonPath('meta.needs_action', 1)->assertJsonPath('meta.total', 2);
    }

    public function test_it_needs_the_integrations_permission(): void
    {
        $viewer = $this->userWith(['clients.view', 'clients.view_all']);

        $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/integrations/clients-overview')->assertForbidden();
    }

    public function test_the_hub_names_the_category_of_a_failing_source_and_never_a_provider_sentence(): void
    {
        $owner = $this->owner();
        $project = $this->newProject('P1', 'Alpha');
        $this->limitSpend($project, 1, 10, provider: 'meta');
        $this->account($project, 'meta')->forceFill(['last_synced_at' => now()->subHour(), 'last_sync_attempt_at' => now(), 'last_sync_error_category' => 'insights_not_authorised'])->save();

        $row = collect($this->actingAs($owner, 'sanctum')->getJson('/api/v1/integrations/hub?project_id='.$project->id)->assertOk()->json('data.connections'))->first();

        $this->assertNotNull($row);
        $this->assertTrue($row['needs_attention']);
        $this->assertSame('insights_not_authorised', $row['last_error_category']);
    }
}
