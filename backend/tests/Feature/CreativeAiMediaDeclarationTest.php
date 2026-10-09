<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativePresenter;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** SNAP-OCT26-AI-MEDIA-DECLARATION — the declaration is stated when the platform stated it, never guessed. */
final class CreativeAiMediaDeclarationTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $client = ClientWorkspace::create(['tenant_id' => $tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $tenant->id, 'client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
    }

    private function creative(array $raw): ExternalCreative
    {
        return ExternalCreative::create([
            'tenant_id' => $this->project->tenant_id, 'project_id' => $this->project->id, 'provider' => 'snapchat',
            'external_creative_id' => 'c-'.uniqid(), 'name' => 'Story', 'format' => 'video', 'status' => 'active', 'raw' => $raw,
        ]);
    }

    public function test_a_declared_creative_says_so_in_both_languages_and_an_undeclared_one_says_not_declared(): void
    {
        $presenter = app(CreativePresenter::class);

        $declared = $presenter->detail($this->creative(['type' => 'SNAP_AD', 'ai_content_source' => 'USER_AI_GEN']), null)['ai_media'];
        $this->assertTrue($declared['declared']);
        $this->assertSame('USER_AI_GEN', $declared['source']);
        $this->assertStringContainsString('الذكاء الاصطناعي', $declared['label_ar']);
        $this->assertStringContainsString('AI-generated', $declared['label_en']);

        $plain = $presenter->detail($this->creative(['type' => 'SNAP_AD']), null)['ai_media'];
        $this->assertFalse($plain['declared']);
        $this->assertNull($plain['source']);
        $this->assertNull($plain['label_ar']);
    }
}
