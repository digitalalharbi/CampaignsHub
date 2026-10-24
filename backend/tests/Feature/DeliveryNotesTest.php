<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Delivery\Models\DeliveryPack;
use App\Domains\Delivery\Services\DeliveryPackService;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportAnnotation;
use App\Domains\Reports\Services\ClientReportView;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * DELIVERY-NOTES-001 — the Owner's own notes: add, edit, order, keep internal, delete — and they reach (or never reach) the client
 * through the SAME report data every surface already reads.
 */
final class DeliveryNotesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private User $viewer;

    private Project $project;

    private DeliveryPack $pack;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $owner = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $owner->givePermissionTo(...Permission::pluck('key')->all());
        $view = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'ReadOnly', 'slug' => 'readonly']);
        $view->givePermissionTo('clients.view', 'clients.view_all', 'reports.view');
        $this->owner = User::create(['name' => 'Owner', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant, Portal::Agency);
        $this->owner->assignRole($owner);
        $this->viewer = User::create(['name' => 'RO', 'email' => 'ro@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->viewer, $this->tenant, Portal::Agency);
        $this->viewer->assignRole($view);
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Razzah', 'slug' => 'razzah', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'Store', 'status' => 'active']);
        $this->pack = app(DeliveryPackService::class)->prepare($this->project, Carbon::parse('2026-10-19'), $this->owner);
    }

    private function add(array $body, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner, 'sanctum')->postJson("/api/v1/delivery/packs/{$this->pack->id}/notes", $body);
    }

    /** The report data as the generator writes it, run for real (the queue is faked in this test, so the job is called directly). */
    private function generated(): array
    {
        Report::withoutGlobalScopes()->whereKey($this->pack->report_id)->update(['status' => 'processing']);
        app(GenerateReportJob::class, ['reportId' => (string) $this->pack->report_id])->handle(app(ReportGenerator::class));

        return (array) Report::withoutGlobalScopes()->findOrFail($this->pack->report_id)->data;
    }

    public function test_a_note_is_added_listed_edited_and_deleted_and_each_change_regenerates_the_report(): void
    {
        $id = $this->add(['kind' => 'challenge', 'text_ar' => 'تأخر المحتوى الإبداعي', 'audience' => 'client'])->assertOk()
            ->assertJsonPath('data.kind', 'challenge')->assertJsonPath('data.status', 'approved')->json('data.id');
        Queue::assertPushed(GenerateReportJob::class);

        $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/delivery/packs/{$this->pack->id}")->assertOk()
            ->assertJsonPath('data.notes.0.id', $id);

        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/v1/delivery/packs/{$this->pack->id}/notes/{$id}", ['text_ar' => 'تأخر تسليم المحتوى', 'kind' => 'observation'])->assertOk()
            ->assertJsonPath('data.text_ar', 'تأخر تسليم المحتوى')->assertJsonPath('data.kind', 'observation')->assertJsonPath('data.version', 2);

        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/v1/delivery/packs/{$this->pack->id}/notes/{$id}")->assertOk();
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/delivery/packs/{$this->pack->id}")->assertJsonCount(0, 'data.notes');
    }

    public function test_a_note_needs_text_a_known_kind_and_is_limited(): void
    {
        $this->add(['kind' => 'observation'])->assertStatus(409);
        $this->add(['kind' => 'nonsense', 'text_ar' => 'x'])->assertStatus(422);
        $this->add(['kind' => 'observation', 'text_ar' => str_repeat('ا', 601)])->assertStatus(422);
        $this->add(['kind' => 'observation', 'text_ar' => 'x', 'audience' => 'public'])->assertStatus(422);
    }

    public function test_only_someone_who_may_approve_writes_notes_and_a_sent_pack_is_closed(): void
    {
        $this->add(['kind' => 'observation', 'text_ar' => 'x'], $this->viewer)->assertForbidden();
        $this->assertSame(0, ReportAnnotation::withoutGlobalScopes()->where('source', 'manual')->count());

        $this->pack->forceFill(['status' => 'sent'])->save();
        $this->add(['kind' => 'observation', 'text_ar' => 'x'])->assertStatus(409);
    }

    public function test_notes_can_be_ordered_and_only_the_packs_own_notes_are_touched(): void
    {
        $a = $this->add(['kind' => 'observation', 'text_ar' => 'أ'])->json('data.id');
        $b = $this->add(['kind' => 'opportunity', 'text_ar' => 'ب'])->json('data.id');
        $c = $this->add(['kind' => 'recommendation', 'text_ar' => 'ج'])->json('data.id');

        $r = $this->actingAs($this->owner, 'sanctum')->putJson("/api/v1/delivery/packs/{$this->pack->id}/notes/order", ['ids' => [$c, $a]])->assertOk();
        $this->assertSame([$c, $a, $b], array_column($r->json('data'), 'id'));

        app(TenantContext::class)->setTenantId($this->tenant->id);
        $other = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->project->client_workspace_id, 'name' => 'Other', 'status' => 'active']);
        $otherPack = app(DeliveryPackService::class)->prepare($other, Carbon::parse('2026-10-19'), $this->owner);
        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/v1/delivery/packs/{$otherPack->id}/notes/{$a}")->assertNotFound();
    }

    public function test_the_generated_report_carries_client_notes_and_the_client_view_never_shows_internal_ones(): void
    {
        $this->add(['kind' => 'challenge', 'text_ar' => 'ملاحظة للعميل', 'audience' => 'client']);
        $this->add(['kind' => 'recommendation', 'text_ar' => 'توصية للعميل', 'audience' => 'client']);
        $this->add(['kind' => 'challenge', 'text_ar' => 'ملاحظة داخلية', 'audience' => 'internal']);
        $this->add(['kind' => 'recommendation', 'text_ar' => 'توصية داخلية', 'audience' => 'internal']);

        $data = $this->generated();
        $titles = fn (string $key) => array_column($data[$key] ?? [], 'title');
        $this->assertContains('ملاحظة داخلية', $titles('findings'), 'the agency snapshot keeps every note');

        $client = app(ClientReportView::class)->filter($data);
        $this->assertContains('ملاحظة للعميل', array_column($client['findings'], 'title'));
        $this->assertContains('توصية للعميل', array_column($client['recommendations'], 'title'));
        $this->assertNotContains('ملاحظة داخلية', array_column($client['findings'], 'title'));
        $this->assertNotContains('توصية داخلية', array_column($client['recommendations'], 'title'));
        foreach ($client['next_steps'] ?? [] as $step) {
            $this->assertNotSame('توصية داخلية', $step['action'], 'an internal recommendation is not a next step for the client');
        }
        $this->assertContains('توصية للعميل', array_column($client['next_steps'] ?? [], 'action'));
    }

    public function test_the_whatsapp_text_carries_client_recommendations_in_the_chosen_order_and_never_internal_ones(): void
    {
        $service = app(DeliveryPackService::class);
        $this->add(['kind' => 'recommendation', 'text_ar' => 'الأولى']);
        $second = $this->add(['kind' => 'recommendation', 'text_ar' => 'الثانية'])->json('data.id');
        $this->add(['kind' => 'recommendation', 'text_ar' => 'سرية للوكالة', 'audience' => 'internal']);
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/v1/delivery/packs/{$this->pack->id}/notes/order", ['ids' => [$second]])->assertOk();

        Report::withoutGlobalScopes()->whereKey($this->pack->report_id)->update(['status' => 'completed', 'data' => json_encode(['totals' => ['conversions' => 1], 'currency' => 'USD'])]);
        $service->approve($this->pack->fresh(), $this->owner);
        $text = $service->whatsappSummary($this->pack->fresh(), 'ar');

        $this->assertStringNotContainsString('سرية للوكالة', $text);
        $this->assertLessThan(mb_strpos($text, 'الأولى'), mb_strpos($text, 'الثانية'), 'the order the Owner chose is the order sent');
    }
}
