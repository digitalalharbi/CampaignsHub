<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ContentIntake\Models\ContentIntakeItem;
use App\Domains\ContentStudio\Models\BrandKit;
use App\Domains\ContentStudio\Models\LayoutFamily;
use App\Domains\ContentStudio\Models\ReadinessScore;
use App\Domains\ContentStudio\Models\StudioOutput;
use App\Domains\ContentStudio\Models\StudioSession;
use App\Domains\Projects\Access\ProjectRole;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCeilingFixture;
use Tests\TestCase;

/**
 * CONTENT-STUDIO-001 batch 8 — the studio's storage: the seven system families, the project brand kit, sessions and
 * saved outputs as ordinary library assets, the hard rules re-checked on the server, ratings, and tenant / project
 * isolation.
 */
final class ContentStudioStorageTest extends TestCase
{
    use BuildsCeilingFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCeilingFixture();
        Storage::fake('local');
    }

    /** @param list<string> $perms */
    private function member(array $perms, bool $clientA = true): User
    {
        $perms = [...$perms, 'projects.view', 'projects.view.all', 'campaigns.view'];
        $user = $this->scopedTo($clientA ? $this->clientA : $this->clientB, $perms);
        ProjectMembership::create(['tenant_id' => $this->agency->id, 'project_id' => $clientA ? $this->projectA->id : $this->projectB->id, 'user_id' => $user->id, 'role' => ProjectRole::MARKETING_MANAGER, 'status' => 'active']);

        return $user;
    }

    private function openStudioSession(User $user): string
    {
        return (string) $this->actingAs($user, 'sanctum')->postJson('/api/v1/content-studio/sessions', ['project_id' => $this->projectA->id])
            ->assertCreated()->json('data.id');
    }

    /** @return array<string, mixed> */
    private function outputPayload(array $over = []): array
    {
        return $over + [
            'file' => UploadedFile::fake()->image('out.png', 1080, 1920),
            'kind' => 'image', 'aspect' => '9x16', 'name' => '21Nov 2026ــSpring offerــbold-hook_9x16',
            'family_key' => 'bold-hook', 'family_version' => 1,
            'hook' => 'Spring is here', 'message' => 'New rings for the season', 'cta' => 'Shop now',
            'readiness' => ['score' => 92, 'hard_fail' => false, 'checks' => [['key' => 'safe_zone', 'status' => 'pass']]],
            'tokens' => ['font_display' => 'Cairo', 'accent' => '#C8A24A'], 'tokens_source' => 'derived',
            'concept_name' => 'Spring offer',
        ];
    }

    public function test_the_seven_system_families_are_seeded_and_mirror_the_frontend(): void
    {
        $this->assertSame(7, LayoutFamily::query()->where('is_system', true)->count());
        $front = glob(base_path('../frontend/src/features/content/studio/families/data/*.json')) ?: [];
        $back = glob(database_path('data/studio_families/*.json')) ?: [];
        if ($front !== []) {
            $this->assertSame(array_map('basename', $front), array_map('basename', $back));
            foreach ($front as $f) {
                $this->assertSame(json_decode((string) file_get_contents($f), true), json_decode((string) file_get_contents(database_path('data/studio_families/'.basename($f))), true), basename($f).' drifted');
            }
        }

        $this->actingAs($this->member(['content.view']), 'sanctum')->getJson('/api/v1/content-studio/families')
            ->assertOk()->assertJsonCount(7, 'data');
    }

    public function test_a_saved_output_becomes_a_draft_library_asset_with_its_score_and_tokens(): void
    {
        $user = $this->member(['content.view', 'content.create']);
        $session = $this->openStudioSession($user);

        $res = $this->actingAs($user, 'sanctum')->post("/api/v1/content-studio/sessions/{$session}/outputs", $this->outputPayload(), ['Accept' => 'application/json'])
            ->assertCreated();

        $item = ContentIntakeItem::query()->findOrFail($res->json('data.intake_item_id'));
        $this->assertSame('draft', $item->status);
        $this->assertSame($this->projectA->id, $item->project_id);
        $this->assertSame('Shop now', $item->cta);
        $this->assertSame('story', $item->placement);
        Storage::disk('local')->assertExists($item->path);
        $this->assertSame(92, $res->json('data.readiness.score'));
        $this->assertSame('bold-hook', $res->json('data.family_key'));
        $this->assertNotNull($res->json('data.concept_id'));
        $this->assertSame(1, ReadinessScore::query()->count());
        $this->assertSame('evaluate', StudioSession::query()->findOrFail($session)->stage);
    }

    public function test_the_server_refuses_what_breaks_a_hard_rule(): void
    {
        $user = $this->member(['content.view', 'content.create']);
        $session = $this->openStudioSession($user);
        $post = fn (array $over) => $this->actingAs($user, 'sanctum')->post("/api/v1/content-studio/sessions/{$session}/outputs", $this->outputPayload($over), ['Accept' => 'application/json']);

        $post(['kind' => 'video', 'duration_ms' => 15_001, 'file' => UploadedFile::fake()->create('v.mp4', 100, 'video/mp4')])
            ->assertStatus(422)->assertJsonPath('errors.refusals.0', 'video_too_long');
        $post(['readiness' => ['score' => 40, 'hard_fail' => true]])->assertStatus(422)->assertJsonPath('errors.refusals.0', 'hard_check_failed');
        $post(['cta' => ''])->assertStatus(422)->assertJsonPath('errors.refusals.0', 'cta_missing');
        $post(['hook' => 'Sale #ramadan'])->assertStatus(422)->assertJsonPath('errors.refusals.0', 'hashtag');
        $post(['message' => 'Best rings 🔥'])->assertStatus(422)->assertJsonPath('errors.refusals.0', 'emoji');
        $post(['name' => 'Untitled 3'])->assertStatus(422)->assertJsonPath('errors.refusals.0', 'name_missing');
        $post(['family_key' => 'no-such-family'])->assertStatus(422)->assertJsonPath('errors.refusals.0', 'unknown_family');

        BrandKit::query()->create(['project_id' => $this->projectA->id, 'forbidden_words' => ['cheap']]);
        $post(['message' => 'Cheap rings today'])->assertStatus(422)->assertJsonPath('errors.refusals.0', 'forbidden_word');

        $this->assertSame(0, StudioOutput::query()->count());
        $this->assertSame(0, ContentIntakeItem::query()->count());
    }

    public function test_a_fifteen_second_video_is_accepted(): void
    {
        $user = $this->member(['content.view', 'content.create']);
        $session = $this->openStudioSession($user);
        $this->actingAs($user, 'sanctum')->post("/api/v1/content-studio/sessions/{$session}/outputs", $this->outputPayload([
            'kind' => 'video', 'duration_ms' => 15_000, 'has_audio' => true, 'family_key' => 'detail-cuts',
            'file' => UploadedFile::fake()->create('v.mp4', 100, 'video/mp4'),
        ]), ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.has_audio', true);
    }

    public function test_brand_kit_is_one_per_project_and_needs_update_permission(): void
    {
        $reader = $this->member(['content.view']);
        $this->actingAs($reader, 'sanctum')->postJson('/api/v1/content-studio/brand-kit', ['project_id' => $this->projectA->id])->assertForbidden();

        $editor = $this->member(['content.view', 'content.update']);
        $payload = ['project_id' => $this->projectA->id, 'font_display' => 'Cairo', 'font_text' => 'Tajawal', 'colors' => ['accent' => '#C8A24A'], 'tone' => 'calm', 'forbidden_words' => ['cheap']];
        $this->actingAs($editor, 'sanctum')->postJson('/api/v1/content-studio/brand-kit', $payload)->assertOk()->assertJsonPath('data.font_display', 'Cairo');
        $this->actingAs($editor, 'sanctum')->postJson('/api/v1/content-studio/brand-kit', ['font_display' => 'Readex Pro'] + $payload)->assertOk();
        $this->assertSame(1, BrandKit::query()->where('project_id', $this->projectA->id)->count());

        $this->actingAs($editor, 'sanctum')->postJson('/api/v1/content-studio/brand-kit', ['colors' => ['accent' => 'gold']] + $payload)->assertStatus(422);
        $this->actingAs($reader, 'sanctum')->getJson('/api/v1/content-studio/brand-kit?project='.$this->projectA->id)
            ->assertOk()->assertJsonPath('data.font_display', 'Readex Pro');
    }

    public function test_project_and_tenant_isolation(): void
    {
        $owner = $this->member(['content.view', 'content.create', 'content.update']);
        $session = $this->openStudioSession($owner);

        // A member of client B reaches neither project A's session nor its brand kit.
        $other = $this->member(['content.view', 'content.create', 'content.update'], clientA: false);
        $this->actingAs($other, 'sanctum')->getJson("/api/v1/content-studio/sessions/{$session}")->assertForbidden();
        $this->actingAs($other, 'sanctum')->post("/api/v1/content-studio/sessions/{$session}/outputs", $this->outputPayload(), ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($other, 'sanctum')->getJson('/api/v1/content-studio/brand-kit?project='.$this->projectA->id)->assertForbidden();
        $this->actingAs($other, 'sanctum')->postJson('/api/v1/content-studio/sessions', ['project_id' => $this->projectA->id])->assertForbidden();

        // A source asset from project B cannot open a session in project A.
        $foreign = ContentIntakeItem::create(['tenant_id' => $this->agency->id, 'project_id' => $this->projectB->id, 'name' => 'B', 'kind' => 'image', 'status' => 'draft', 'source' => 'upload']);
        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/content-studio/sessions', ['project_id' => $this->projectA->id, 'source_item_ids' => [$foreign->id]])->assertStatus(422);

        // Another tenant's session id is a 404.
        $tenant = Tenant::create(['name' => 'Else', 'slug' => 'else-'.uniqid(), 'status' => 'active', 'account_type' => 'agency']);
        app(TenantContext::class)->setTenantId((string) $tenant->id);
        $elsewhere = $this->makeClient('Else client', $tenant);
        $project = Project::create(['tenant_id' => $tenant->id, 'client_workspace_id' => $elsewhere->id, 'name' => 'Else', 'status' => 'active']);
        $foreignSession = StudioSession::query()->create(['project_id' => $project->id]);
        app(TenantContext::class)->setTenantId((string) $this->agency->id);
        $this->actingAs($owner, 'sanctum')->getJson("/api/v1/content-studio/sessions/{$foreignSession->id}")->assertNotFound();
    }

    public function test_rating_is_like_dislike_or_cleared(): void
    {
        $user = $this->member(['content.view', 'content.create']);
        $session = $this->openStudioSession($user);
        $id = $this->actingAs($user, 'sanctum')->post("/api/v1/content-studio/sessions/{$session}/outputs", $this->outputPayload(), ['Accept' => 'application/json'])->json('data.id');

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/content-studio/outputs/{$id}/rating", ['rating' => 1])->assertOk()->assertJsonPath('data.rating', 1);
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/content-studio/outputs/{$id}/rating", ['rating' => -1])->assertOk()->assertJsonPath('data.rating', -1);
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/content-studio/outputs/{$id}/rating", ['rating' => null])->assertOk()->assertJsonPath('data.rating', null);
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/content-studio/outputs/{$id}/rating", ['rating' => 5])->assertStatus(422);
    }

    public function test_reading_needs_content_view(): void
    {
        $none = $this->member([]);
        $this->actingAs($none, 'sanctum')->getJson('/api/v1/content-studio/families')->assertForbidden();
        $this->actingAs($none, 'sanctum')->postJson('/api/v1/content-studio/sessions', ['project_id' => $this->projectA->id])->assertForbidden();
    }
}
