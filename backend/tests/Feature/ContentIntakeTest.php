<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\ContentIntake\Analysis\AiProviderAnalyzer;
use App\Domains\ContentIntake\Models\ContentIntakeEvent;
use App\Domains\ContentIntake\Models\ContentIntakeItem;
use App\Domains\ContentIntake\Services\DriveIntakeImporter;
use App\Domains\ContentIntake\Services\IntakeAnalysis;
use App\Domains\ContentIntake\Services\IntakeTransitionException;
use App\Domains\ContentIntake\Services\IntakeTransitions;
use App\Domains\ContentIntake\Services\Mp4Probe;
use App\Domains\Drive\Models\DriveLink;
use App\Domains\Drive\Providers\DriveProvider;
use App\Domains\Projects\Access\ProjectRole;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\Concerns\BuildsCeilingFixture;
use Tests\TestCase;

/**
 * CONTENT-INTAKE-001 — permissions, tenant and project isolation, the single transition function with its immutable
 * audit trail, the deterministic pre-checks, the approval flow, the parked AI adapter and idempotent Drive import.
 */
final class ContentIntakeTest extends TestCase
{
    use BuildsCeilingFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCeilingFixture();
        Storage::fake('local');
    }

    private function item(array $over = []): ContentIntakeItem
    {
        return ContentIntakeItem::create($over + [
            'tenant_id' => $this->agency->id, 'project_id' => $this->projectA->id, 'name' => 'Spring offer', 'kind' => 'image',
            'provider' => 'snapchat', 'objective' => 'sales', 'placement' => 'story', 'status' => 'draft', 'source' => 'upload',
            'mime' => 'image/png', 'size_bytes' => 1_000_000, 'width' => 1080, 'height' => 1920, 'path' => 'x.png', 'disk' => 'local',
            'destination_url' => 'https://shop.test/?utm_source=snap&utm_medium=paid&utm_campaign=spring',
        ]);
    }

    /** @param list<string> $perms */
    private function member(array $perms, bool $clientA = true): User
    {
        $perms = [...$perms, 'projects.view', 'projects.view.all', 'campaigns.view'];

        $user = $clientA ? $this->scopedTo($this->clientA, $perms) : $this->scopedTo($this->clientB, $perms);
        // A client-scoped member reaches a project only through a membership of it (the ceiling outranks the role).
        ProjectMembership::create(['tenant_id' => $this->agency->id, 'project_id' => $clientA ? $this->projectA->id : $this->projectB->id, 'user_id' => $user->id, 'role' => ProjectRole::MARKETING_MANAGER, 'status' => 'active']);

        return $user;
    }

    private function analysed(array $over = []): ContentIntakeItem
    {
        return app(IntakeAnalysis::class)->run($this->item($over));
    }

    // ───────────────────────────── pre-checks ─────────────────────────────

    public function test_a_valid_snapchat_story_passes_and_reports_measured_values(): void
    {
        $item = $this->analysed();
        $by = collect($item->checks)->keyBy('key');
        $this->assertSame('pass', $item->check_result);
        $this->assertSame('pass', $by['aspect_ratio']['status']);
        $this->assertSame('1080x1920', $by['aspect_ratio']['params']['measured']);
        $this->assertStringContainsString('1080x1920', $by['aspect_ratio']['reason_en']);
        $this->assertNotSame('content_intake.checks.aspect_ratio.pass', $by['aspect_ratio']['reason_ar']);
        $this->assertSame('not_measured', $by['naming']['status']);
        $this->assertSame(100, $item->readiness_score);
    }

    public function test_wrong_ratio_size_and_duration_fail_with_the_exact_reason(): void
    {
        $item = $this->analysed(['kind' => 'video', 'mime' => 'video/mp4', 'width' => 1920, 'height' => 1080, 'duration_ms' => 400_000, 'size_bytes' => 2_000_000_000]);
        $by = collect($item->checks)->keyBy('key');
        $this->assertSame('fail', $item->check_result);
        $this->assertSame('fail', $by['aspect_ratio']['status']);
        $this->assertSame('fail', $by['file_size']['status']);
        $this->assertSame('fail', $by['duration']['status']);
        $this->assertEquals(400.0, $by['duration']['params']['measured']);
        $this->assertLessThan(100, $item->readiness_score);
    }

    public function test_unmeasured_dimensions_are_not_a_pass(): void
    {
        $item = $this->analysed(['source' => 'drive', 'path' => null, 'disk' => null, 'drive_file_id' => 'd1', 'width' => null, 'height' => null, 'mime' => null, 'size_bytes' => null]);
        $by = collect($item->checks)->keyBy('key');
        $this->assertSame('not_measured', $by['aspect_ratio']['status']);
        $this->assertSame('not_measured', $by['format']['status']);
        $this->assertContains($item->check_result, ['pass', 'warn']);
        $cov = $item->analysis['coverage'];
        $this->assertLessThan($cov['total'], $cov['measured'], 'unmeasured checks are listed but not scored');
    }

    public function test_missing_target_utm_and_copy_length_are_reported(): void
    {
        $item = $this->analysed(['provider' => null, 'objective' => null, 'destination_url' => 'https://shop.test/?utm_source=a', 'headline' => str_repeat('x', 50), 'provider' => 'meta', 'placement' => null, 'objective' => null]);
        $by = collect($item->checks)->keyBy('key');
        $this->assertSame('fail', $by['target']['status']);
        $this->assertSame('warn', $by['utm']['status']);
        $this->assertStringContainsString('utm_medium', $by['utm']['reason_en']);
        $this->assertSame('warn', $by['copy_length']['status']);
    }

    public function test_a_platform_without_a_registered_spec_is_not_measured_not_passed(): void
    {
        $item = $this->analysed(['provider' => 'linkedin']);
        $by = collect($item->checks)->keyBy('key');
        $this->assertSame('not_measured', $by['spec']['status']);
        $this->assertArrayNotHasKey('aspect_ratio', $by->all());
    }

    public function test_duplicate_by_hash_fails_in_the_same_project_and_warns_across_projects(): void
    {
        $this->item(['content_hash' => str_repeat('a', 64), 'status' => 'approved', 'name' => 'Original']);
        $same = $this->analysed(['content_hash' => str_repeat('a', 64), 'name' => 'Copy']);
        $this->assertSame('fail', collect($same->checks)->keyBy('key')['duplicate']['status']);

        $other = $this->analysed(['content_hash' => str_repeat('a', 64), 'name' => 'Elsewhere', 'project_id' => $this->projectB->id]);
        $this->assertSame('warn', collect($other->checks)->keyBy('key')['duplicate']['status']);
    }

    public function test_upload_measures_the_real_file_and_stores_it_privately(): void
    {
        $user = $this->member(['content.view', 'content.create']);
        $res = $this->actingAs($user, 'sanctum')->post('/api/v1/content-intake', [
            'project_id' => $this->projectA->id, 'name' => 'Story', 'provider' => 'snapchat', 'objective' => 'sales', 'placement' => 'story',
            'file' => UploadedFile::fake()->image('story.png', 1080, 1920),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(1080, $res->json('data.width'));
        $this->assertSame(1920, $res->json('data.height'));
        $this->assertSame('upload', $res->json('data.source'));
        $this->assertArrayNotHasKey('path', $res->json('data'));
        $item = ContentIntakeItem::withoutGlobalScopes()->findOrFail($res->json('data.id'));
        Storage::disk('local')->assertExists($item->path);
        $this->assertSame(64, strlen((string) $item->content_hash));
        $this->assertSame('created', ContentIntakeEvent::withoutGlobalScopes()->where('item_id', $item->id)->firstOrFail()->action);
    }

    public function test_mp4_header_probe_reads_duration_and_frame_size(): void
    {
        $box = static fn (string $t, string $p): string => pack('N', 8 + strlen($p)).$t.$p;
        $mvhd = $box('mvhd', pack('N', 0).pack('N2', 0, 0).pack('N', 1000).pack('N', 12_500).str_repeat("\0", 80));
        $tkhd = $box('tkhd', pack('N', 0).str_repeat("\0", 72).pack('N', 1080 << 16).pack('N', 1920 << 16));
        $moov = $box('moov', $mvhd.$box('trak', $tkhd));
        $path = tempnam(sys_get_temp_dir(), 'mp4');
        file_put_contents($path, $box('ftyp', 'isom'."\0\0\0\0").$box('mdat', str_repeat('z', 64)).$moov);

        $r = (new Mp4Probe)->probe($path);
        unlink($path);
        $this->assertSame(12_500, $r['duration_ms']);
        $this->assertSame(1080, $r['width']);
        $this->assertSame(1920, $r['height']);
        $this->assertSame(['duration_ms' => null, 'width' => null, 'height' => null], (new Mp4Probe)->probe('/nonexistent/file.mp4'));
    }

    // ───────────────────────────── transitions ─────────────────────────────

    public function test_the_full_flow_writes_an_immutable_audit_trail_with_snapshots(): void
    {
        $uploader = $this->member(['content.view', 'content.create', 'content.update']);
        $approver = $this->member(['content.view', 'content.approve', 'content.reject']);
        $item = $this->analysed(['uploaded_by' => $uploader->id]);
        $t = app(IntakeTransitions::class);

        $t->apply($item, 'submit', $uploader);
        $item = $t->apply($item->fresh(), 'approve', $approver, ['notes' => 'Good']);
        $this->assertSame('approved', $item->status);
        $this->assertSame($approver->id, $item->decided_by);
        $item = $t->apply($item->fresh(), 'mark_live', $uploader);
        $this->assertSame('live', $item->status);

        $events = $item->events()->get();
        $this->assertSame(['submit', 'approve', 'mark_live'], $events->pluck('action')->all());
        $this->assertSame($approver->name, $events[1]->actor_name);
        $this->assertSame('Good', $events[1]->notes);
        $this->assertSame('Spring offer', $events[1]->item_name);

        $item->update(['name' => 'Renamed']);
        $this->assertSame('Spring offer', $events[1]->fresh()->item_name, 'the snapshot does not follow a rename');

        $this->expectException(LogicException::class);
        $events[0]->update(['reason' => 'tampered']);
    }

    public function test_events_cannot_be_deleted(): void
    {
        $item = $this->analysed();
        app(IntakeTransitions::class)->record($item, 'rechecked', null, 'draft', 'draft');
        $this->expectException(LogicException::class);
        ContentIntakeEvent::query()->firstOrFail()->delete();
    }

    public function test_reject_needs_a_reason_and_an_unallowed_move_is_refused(): void
    {
        $approver = $this->member(['content.view', 'content.approve', 'content.reject']);
        $uploader = $this->member(['content.view', 'content.create']);
        $item = $this->analysed(['uploaded_by' => $uploader->id]);
        $t = app(IntakeTransitions::class);

        try {
            $t->apply($item, 'approve', $approver);
            $this->fail('approving a draft must be refused');
        } catch (IntakeTransitionException $e) {
            $this->assertSame('not_allowed_from_state', $e->reason);
        }
        $t->apply($item, 'submit', $uploader);
        try {
            $t->apply($item->fresh(), 'reject', $approver);
            $this->fail('reject without a reason must be refused');
        } catch (IntakeTransitionException $e) {
            $this->assertSame('reason_required', $e->reason);
        }
        $rejected = $t->apply($item->fresh(), 'reject', $approver, ['reason' => 'Off brand']);
        $this->assertSame('Off brand', $rejected->decision_reason);
        $reopened = $t->apply($rejected->fresh(), 'reopen', $uploader);
        $this->assertSame('draft', $reopened->status);
        $this->assertNull($reopened->decision_reason, 'the old decision is cleared; the trail keeps it');
    }

    public function test_an_item_with_a_failing_check_cannot_be_submitted(): void
    {
        $uploader = $this->member(['content.view', 'content.create']);
        $item = $this->analysed(['uploaded_by' => $uploader->id, 'width' => 1920, 'height' => 1080]);
        $this->assertSame('fail', $item->check_result);
        $this->actingAs($uploader, 'sanctum')->postJson("/api/v1/content-intake/{$item->id}/transition", ['action' => 'submit'])
            ->assertStatus(422)->assertJsonPath('errors.reason', 'checks_failed')->assertJsonPath('errors.failed.0.key', 'aspect_ratio');
        $this->assertSame('draft', $item->fresh()->status);
    }

    public function test_approval_needs_the_permission_and_an_uploader_cannot_approve_their_own_unless_owner(): void
    {
        $both = $this->member(['content.view', 'content.create', 'content.approve']);
        $plain = $this->member(['content.view', 'content.create']);
        $item = $this->analysed(['uploaded_by' => $both->id]);
        $t = app(IntakeTransitions::class);
        $t->apply($item, 'submit', $both);

        try {
            $t->apply($item->fresh(), 'approve', $plain);
            $this->fail();
        } catch (IntakeTransitionException $e) {
            $this->assertSame('forbidden', $e->reason);
        }
        try {
            $t->apply($item->fresh(), 'approve', $both);
            $this->fail();
        } catch (IntakeTransitionException $e) {
            $this->assertSame('self_approval', $e->reason);
        }
        $this->assertNotContains('approve', $t->available($item->fresh(), $both));

        $owner = $this->owner();
        $this->assertContains('approve', $t->available($item->fresh(), $owner));
        $this->assertSame('approved', $t->apply($item->fresh(), 'approve', $owner)->status);
    }

    public function test_a_decision_notifies_the_uploader_alone_through_the_engine(): void
    {
        $uploader = $this->member(['content.view', 'content.create']);
        $approver = $this->member(['content.view', 'content.approve']);
        $item = $this->analysed(['uploaded_by' => $uploader->id]);
        $t = app(IntakeTransitions::class);
        $t->apply($item, 'submit', $uploader);
        $t->apply($item->fresh(), 'approve', $approver);

        $rows = DB::table('notification_outbox')->where('event_key', 'content.decided')->get();
        $this->assertGreaterThan(0, $rows->count());
        $this->assertSame([$uploader->id], $rows->pluck('recipient_user_id')->map(fn ($v): int => (int) $v)->unique()->values()->all());
        $this->assertSame('approve', json_decode((string) $rows->first()->payload, true)['variant']);
    }

    // ───────────────────────────── API: isolation ─────────────────────────────

    public function test_reading_needs_content_view_and_project_reach(): void
    {
        $item = $this->item();
        $none = $this->member(['campaigns.view']);
        $this->actingAs($none, 'sanctum')->getJson('/api/v1/content-intake')->assertForbidden();

        $reader = $this->member(['content.view'], false); // scoped to client B
        $this->actingAs($reader, 'sanctum')->getJson('/api/v1/content-intake')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($reader, 'sanctum')->getJson("/api/v1/content-intake/{$item->id}")->assertNotFound();
        $this->actingAs($reader, 'sanctum')->getJson("/api/v1/content-intake/{$item->id}/file")->assertNotFound();

        $this->actingAs($this->owner(), 'sanctum')->getJson('/api/v1/content-intake')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_member_cannot_upload_into_a_project_they_do_not_reach(): void
    {
        $user = $this->member(['content.view', 'content.create'], false);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/content-intake', ['project_id' => $this->projectA->id, 'kind' => 'copy', 'copy_text' => 'x'])->assertForbidden();
        $this->assertSame(0, ContentIntakeItem::count());
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/content-intake', ['project_id' => $this->projectB->id, 'kind' => 'copy', 'copy_text' => 'x'])->assertCreated();
    }

    public function test_another_workspaces_item_is_a_404(): void
    {
        $item = $this->item();
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'status' => 'active', 'account_type' => 'agency', 'enabled_modules' => ['paid_media'], 'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
        $intruder = $this->operatorIn($other);
        app(TenantContext::class)->setTenantId((string) $other->id);
        $this->actingAs($intruder, 'sanctum')->getJson("/api/v1/content-intake/{$item->id}")->assertNotFound();
        $this->actingAs($intruder, 'sanctum')->postJson("/api/v1/content-intake/{$item->id}/transition", ['action' => 'submit'])->assertNotFound();
    }

    public function test_an_edit_is_only_possible_while_draft_or_needs_changes(): void
    {
        $user = $this->member(['content.view', 'content.create', 'content.update']);
        $item = $this->analysed(['uploaded_by' => $user->id]);
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/content-intake/{$item->id}", ['headline' => 'New'])->assertOk()->assertJsonPath('data.headline', 'New');
        $item->forceFill(['status' => 'approved'])->save();
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/content-intake/{$item->id}", ['headline' => 'Again'])->assertStatus(409);
    }

    public function test_follow_up_lists_the_queues(): void
    {
        $this->item(['status' => 'in_review', 'name' => 'a']);
        $this->item(['status' => 'rejected', 'decision_reason' => 'Off brand', 'name' => 'b']);
        $this->item(['status' => 'approved', 'name' => 'c']);
        $res = $this->actingAs($this->owner(), 'sanctum')->getJson('/api/v1/content-intake/follow-up')->assertOk();
        $this->assertCount(1, $res->json('data.awaiting_review'));
        $this->assertSame('Off brand', $res->json('data.rejected.0.decision_reason'));
        $this->assertCount(1, $res->json('data.approved_not_launched'));
        $this->assertSame([], $res->json('data.live_fatigue'));
    }

    // ───────────────────────────── AI adapter ─────────────────────────────

    public function test_the_ai_adapter_is_awaiting_credentials_and_returns_no_text(): void
    {
        $out = app(AiProviderAnalyzer::class)->analyze($this->item());
        $this->assertSame('awaiting_credentials', $out['state']);
        $this->assertSame(AiProviderAnalyzer::FIELDS, array_keys($out['fields']));
        $this->assertSame([], array_filter($out['fields'], static fn ($v): bool => $v !== null));

        $item = $this->analysed();
        $this->assertSame('awaiting_credentials', $item->ai['state']);
    }

    // ───────────────────────────── Drive ─────────────────────────────

    private function fakeDrive(array &$files, bool $configured = true): void
    {
        $provider = new class($files, $configured) implements DriveProvider
        {
            public function __construct(private array &$files, private bool $configured) {}

            public function name(): string
            {
                return 'fake';
            }

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function listFolder(string $folderId): array
            {
                return $this->files;
            }

            public function fileMetadata(string $fileId): array
            {
                return [];
            }

            public function authUrl(): ?string
            {
                return null;
            }
        };
        $this->app->instance('fake-drive', $provider);
        config()->set('drive.providers.fake', 'fake-drive');
        config()->set('drive.default', 'fake');
    }

    private function driveFile(string $id, string $mime, string $modified): array
    {
        return ['file_id' => $id, 'name' => $id.'.png', 'mime' => $mime, 'size' => 1000, 'thumbnail_link' => null, 'web_view_link' => null, 'modified_time' => $modified, 'version' => '1'];
    }

    public function test_drive_import_is_idempotent_and_a_changed_file_goes_back_to_needs_changes(): void
    {
        $files = [$this->driveFile('f1', 'image/png', '2026-11-01T10:00:00+00:00'), $this->driveFile('f2', 'application/pdf', '2026-11-01T10:00:00+00:00')];
        $this->fakeDrive($files);
        $link = DriveLink::create(['tenant_id' => $this->agency->id, 'scope' => 'project', 'scope_id' => $this->projectA->id, 'folder_id' => 'fold', 'folder_name' => 'Assets']);
        $importer = app(DriveIntakeImporter::class);

        $first = $importer->sync($link);
        $this->assertEquals(['created' => 1, 'skipped' => 1, 'updated' => 0, 'unchanged' => 0], array_intersect_key($first, array_flip(['created', 'skipped', 'updated', 'unchanged'])));
        $second = $importer->sync($link->fresh());
        $this->assertEquals(['created' => 0, 'unchanged' => 1], array_intersect_key($second, array_flip(['created', 'unchanged'])));
        $this->assertSame(1, ContentIntakeItem::count());

        $item = ContentIntakeItem::firstOrFail();
        $this->assertSame('drive', $item->source);
        $this->assertSame('f1', $item->drive_file_id);
        $this->assertNull($item->path, 'Drive bytes are never stored');
        $this->assertSame('not_measured', collect($item->checks)->keyBy('key')['aspect_ratio']['status'] ?? 'not_measured');

        $item->forceFill(['status' => 'approved'])->save();
        $files[0]['modified_time'] = '2026-11-02T10:00:00+00:00';
        $third = $importer->sync($link->fresh());
        $this->assertSame(1, $third['updated']);
        $this->assertSame('needs_changes', $item->fresh()->status);
        $this->assertSame('source_changed', $item->events()->get()->last()->action);
        $this->assertNotNull($link->fresh()->last_synced_at);
        $this->assertNull($link->fresh()->last_error);
        $this->assertSame(1, ContentIntakeItem::count());
    }

    public function test_drive_without_credentials_records_the_state_and_imports_nothing(): void
    {
        $link = DriveLink::create(['tenant_id' => $this->agency->id, 'scope' => 'project', 'scope_id' => $this->projectA->id, 'folder_id' => 'fold', 'folder_name' => 'Assets']);
        $r = app(DriveIntakeImporter::class)->sync($link);
        $this->assertSame('awaiting_credentials', $r['state']);
        $this->assertSame('awaiting_credentials', $link->fresh()->sync_state);
        $this->assertSame(0, ContentIntakeItem::count());
    }

    public function test_a_provider_failure_is_recorded_redacted_not_thrown(): void
    {
        $files = [];
        $this->fakeDrive($files);
        $this->app->instance('fake-drive', new class implements DriveProvider
        {
            public function name(): string
            {
                return 'fake';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function listFolder(string $folderId): array
            {
                throw new \RuntimeException('401 token ya29.a0AfH6SMBx1234567890abcdefghijklmnopqrstuv expired');
            }

            public function fileMetadata(string $fileId): array
            {
                return [];
            }

            public function authUrl(): ?string
            {
                return null;
            }
        });
        $link = DriveLink::create(['tenant_id' => $this->agency->id, 'scope' => 'project', 'scope_id' => $this->projectA->id, 'folder_id' => 'fold', 'folder_name' => 'Assets']);
        $r = app(DriveIntakeImporter::class)->sync($link);
        $this->assertSame('error', $r['state']);
        $fresh = $link->fresh();
        $this->assertSame('error', $fresh->sync_state);
        $this->assertStringNotContainsString('ya29', (string) $fresh->last_error);
    }

    public function test_the_drive_sync_route_needs_the_project_in_reach(): void
    {
        $linkA = DriveLink::create(['tenant_id' => $this->agency->id, 'scope' => 'project', 'scope_id' => $this->projectA->id, 'folder_id' => 'fa', 'folder_name' => 'A']);
        $b = $this->member(['drive.view', 'drive.manage', 'content.create'], false);
        $this->actingAs($b, 'sanctum')->postJson("/api/v1/content-intake/drive/{$linkA->id}/sync")->assertForbidden();
        $this->actingAs($this->owner(), 'sanctum')->postJson("/api/v1/content-intake/drive/{$linkA->id}/sync")->assertOk()->assertJsonPath('data.state', 'awaiting_credentials');
    }

    // ───────────────────────────── library overview ─────────────────────────────

    public function test_the_overview_needs_campaign_view_and_groups_the_scope_by_platform_and_objective(): void
    {
        $this->actingAs($this->member(['content.view']), 'sanctum')->getJson('/api/v1/content-overview')->assertOk()
            ->assertJsonPath('data.read', 0)->assertJsonPath('data.matrix', []);

        foreach (['a', 'b'] as $n) {
            ExternalCreative::create(['tenant_id' => $this->agency->id, 'project_id' => $this->projectA->id, 'provider' => 'meta', 'external_creative_id' => $n, 'name' => $n, 'format' => 'image', 'last_synced_at' => now()]);
        }
        $this->actingAs($this->owner(), 'sanctum')->getJson('/api/v1/content-overview?from='.now()->subDays(29)->toDateString().'&to='.now()->toDateString())->assertOk()
            ->assertJsonStructure(['data' => ['matrix', 'fatigue', 'flagged', 'read', 'in_scope', 'window']]);
    }
}
