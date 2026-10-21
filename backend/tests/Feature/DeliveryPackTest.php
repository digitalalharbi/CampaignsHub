<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Billing\Models\Invoice;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Delivery\Models\DeliveryPack;
use App\Domains\Delivery\Models\PlatformEvidence;
use App\Domains\Delivery\Services\DeliveryPackService;
use App\Domains\Delivery\Services\DeliveryTasks;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportAnnotation;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Tasks\Models\Task;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * WD-PACK-001, WD-DRAFTS-001, WD-WHATSAPP-001, WD-EVIDENCE-001 — the weekly delivery: the previous week is
 * prepared once, nothing reaches the client until every recommendation has a decision and the Owner approves,
 * the WhatsApp text says only what was approved, evidence is attached privately, and the invoice is never moved
 * by the pack.
 */
final class DeliveryPackTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private User $readonly;

    private ClientWorkspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ownerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $ownerRole->givePermissionTo(...Permission::pluck('key')->all());
        $viewRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'ReadOnly', 'slug' => 'readonly']);
        $viewRole->givePermissionTo('clients.view', 'clients.view_all', 'reports.view');
        $this->owner = User::create(['name' => 'Owner', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant, Portal::Agency);
        $this->owner->assignRole($ownerRole);
        $this->readonly = User::create(['name' => 'RO', 'email' => 'ro@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->readonly, $this->tenant, Portal::Agency);
        $this->readonly->assignRole($viewRole);

        $this->workspace = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Razzah', 'slug' => 'razzah', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'name' => 'Store', 'status' => 'active']);
    }

    private function pack(): DeliveryPack
    {
        return app(DeliveryPackService::class)->prepare($this->project, Carbon::parse('2026-10-17'), $this->owner);
    }

    private function annotate(DeliveryPack $pack, string $status, string $text): ReportAnnotation
    {
        return ReportAnnotation::create([
            'tenant_id' => $this->tenant->id, 'report_id' => $pack->report_id, 'annotation_id' => substr(md5($text), 0, 12), 'type' => 'recommendation',
            'text_ar' => $text, 'status' => $status, 'source' => 'auto', 'is_ai_generated' => true, 'priority' => 'high', 'version' => 1,
        ]);
    }

    private function complete(DeliveryPack $pack, array $data = []): void
    {
        Report::withoutGlobalScopes()->whereKey($pack->report_id)->update(['status' => 'completed', 'data' => json_encode($data + ['totals' => ['conversions' => 684, 'impressions' => 7225186, 'spend' => 11979.96, 'spend_withheld_rows' => 0], 'currency' => 'USD'])]);
    }

    public function test_the_previous_iso_week_is_prepared_once_and_its_report_is_queued(): void
    {
        $pack = $this->pack();

        $this->assertSame('2026-10-05', $pack->period_start->toDateString());   // Monday of the week before 17 Oct
        $this->assertSame('2026-10-11', $pack->period_end->toDateString());
        $this->assertSame('draft', $pack->status);
        $this->assertTrue($pack->includes('whatsapp'));
        $this->assertFalse($pack->includes('spend'), 'money in the message is opt-in');
        Queue::assertPushed(GenerateReportJob::class, 1);

        $this->assertSame($pack->id, $this->pack()->id, 'asking again returns the same pack');
        $this->assertSame(1, DeliveryPack::query()->count());
        $report = Report::withoutGlobalScopes()->find($pack->report_id);
        $this->assertSame('weekly', $report->config['period_kind']);
        $this->assertSame('client', $report->audience);
    }

    public function test_a_pack_cannot_be_approved_while_a_recommendation_is_undecided_or_the_report_is_not_ready(): void
    {
        $pack = $this->pack();
        $this->annotate($pack, 'draft', 'Shift budget to Snapchat');
        $this->annotate($pack, 'reviewed', 'Pause the weak ad set');

        $service = app(DeliveryPackService::class);
        try {
            $service->approve($pack, $this->owner);
            $this->fail('approved while the report was still processing');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('not ready', $e->getMessage());
        }

        $this->complete($pack);
        $this->assertSame(2, $service->readiness($pack)['undecided_recommendations']);
        try {
            $service->approve($pack->fresh(), $this->owner);
            $this->fail('approved with undecided recommendations');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('2 recommendation(s)', $e->getMessage());
        }

        ReportAnnotation::query()->where('status', 'draft')->update(['status' => 'approved']);
        ReportAnnotation::query()->where('status', 'reviewed')->update(['status' => 'hidden']);
        $this->assertSame('approved', $service->approve($pack->fresh(), $this->owner)->status);
    }

    public function test_the_whatsapp_text_exists_only_after_approval_and_says_only_what_was_approved(): void
    {
        $pack = $this->pack();
        $this->annotate($pack, 'approved', 'Move budget to Snapchat');
        $this->annotate($pack, 'hidden', 'Secret internal idea');
        $this->complete($pack);
        $service = app(DeliveryPackService::class);

        try {
            $service->whatsappSummary($pack->fresh());
            $this->fail('a summary was written for an unapproved pack');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $service->approve($pack->fresh(), $this->owner);
        $text = $service->whatsappSummary($pack->fresh(), 'en', 'https://campaignshub.io/r/abc');

        $this->assertStringContainsString('Results: 684', $text);
        $this->assertStringContainsString('Move budget to Snapchat', $text);
        $this->assertStringNotContainsString('Secret internal idea', $text);
        $this->assertStringContainsString('https://campaignshub.io/r/abc', $text);
        $this->assertStringNotContainsString('Spend', $text, 'spend is off unless the Owner switches it on');

        $service->setComponents($pack->fresh(), ['spend' => true]);
        $this->assertStringContainsString('Spend: 11,979.96 USD', $service->whatsappSummary($pack->fresh(), 'en'));
    }

    public function test_the_amount_due_is_stated_from_an_issued_invoice_and_sending_never_touches_it(): void
    {
        $pack = $this->pack();
        $this->complete($pack);
        $invoice = Invoice::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'number' => 'INV-W-1', 'currency' => 'SAR',
            'subtotal' => 1000, 'tax' => 150, 'total' => 1150, 'line_items' => [], 'amount_paid' => 150, 'status' => 'issued', 'due_date' => '2026-10-24',
        ]);
        $service = app(DeliveryPackService::class);
        $service->attachInvoice($pack, $invoice);
        $service->approve($pack->fresh(), $this->owner);

        $this->assertStringContainsString('amount due 1,000.00 SAR', $service->whatsappSummary($pack->fresh(), 'en'));

        $service->markSent($pack->fresh());
        $this->assertSame('sent', $pack->fresh()->status);
        $this->assertSame('issued', $invoice->fresh()->status, 'sending a report is not sending a bill');

        $this->expectException(\InvalidArgumentException::class);
        $service->attachInvoice($pack->fresh(), $invoice);   // a sent pack is closed
    }

    public function test_an_invoice_of_another_client_cannot_be_attached(): void
    {
        $other = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Other', 'slug' => 'other', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $invoice = Invoice::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $other->id, 'number' => 'INV-O-1', 'currency' => 'SAR', 'subtotal' => 1, 'tax' => 0, 'total' => 1, 'line_items' => [], 'amount_paid' => 0, 'status' => 'issued']);

        $this->expectException(\InvalidArgumentException::class);
        app(DeliveryPackService::class)->attachInvoice($this->pack(), $invoice);
    }

    public function test_an_edited_recommendation_is_marked_edited_loses_its_approval_and_regenerates_the_report(): void
    {
        $this->actingAs($this->owner, 'sanctum');
        $pack = $this->pack();
        $ann = $this->annotate($pack, 'approved', 'Machine words');
        Queue::fake();

        $this->patchJson("/api/v1/delivery/packs/{$pack->id}/recommendations/{$ann->id}", ['text_ar' => 'My own words'])->assertOk();

        $fresh = $ann->fresh();
        $this->assertSame('My own words', $fresh->text_ar);
        $this->assertSame('edited', $fresh->source);
        $this->assertSame('reviewed', $fresh->status, 'approval belonged to the words as they were read');
        $this->assertNull($fresh->approved_at);
        $this->assertSame(2, $fresh->version);
        Queue::assertPushed(GenerateReportJob::class);
    }

    public function test_the_api_runs_the_flow_and_a_read_only_member_can_look_but_not_decide(): void
    {
        $this->actingAs($this->owner, 'sanctum');
        $id = $this->postJson('/api/v1/delivery/packs', ['project_id' => $this->project->id, 'anchor' => '2026-10-17'])->assertCreated()->json('data.id');
        $pack = DeliveryPack::withoutGlobalScopes()->findOrFail($id);
        $this->complete($pack);

        $this->getJson("/api/v1/delivery/packs/{$id}")->assertOk()->assertJsonPath('data.readiness.ready_to_approve', true);
        $this->postJson("/api/v1/delivery/packs/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->getJson("/api/v1/delivery/packs/{$id}/whatsapp?lang=ar")->assertOk();
        $this->postJson("/api/v1/delivery/packs/{$id}/sent")->assertOk()->assertJsonPath('data.status', 'sent');

        $this->actingAs($this->readonly, 'sanctum');
        $this->getJson("/api/v1/delivery/packs/{$id}")->assertOk();
        $this->postJson("/api/v1/delivery/packs/{$id}/approve")->assertForbidden();
        $this->postJson('/api/v1/delivery/packs', ['project_id' => $this->project->id])->assertForbidden();
    }

    public function test_evidence_is_stored_privately_validated_and_served_only_to_an_authorised_operator(): void
    {
        $this->actingAs($this->owner, 'sanctum');
        $body = [
            'client_workspace_id' => $this->workspace->id, 'project_id' => $this->project->id, 'provider' => 'snapchat_ads',
            'period_start' => '2026-10-05', 'period_end' => '2026-10-11', 'note' => 'Ads Manager, last 7 days',
            'file' => UploadedFile::fake()->image('snap.png', 800, 600),
        ];

        $res = $this->postJson('/api/v1/delivery/evidence', $body, ['Accept' => 'application/json'])->assertCreated();
        $id = $res->json('data.id');
        $this->assertSame('snapchat', $res->json('data.provider'), 'the provider is stored canonically');
        $path = PlatformEvidence::withoutGlobalScopes()->findOrFail($id)->path;
        Storage::disk('local')->assertExists($path);
        $this->assertStringStartsWith("evidence/{$this->tenant->id}/{$this->workspace->id}/", $path);

        $this->postJson('/api/v1/delivery/evidence', ['file' => UploadedFile::fake()->create('x.exe', 10, 'application/x-msdownload')] + $body, ['Accept' => 'application/json'])->assertStatus(422);

        $this->get("/api/v1/delivery/evidence/{$id}/file")->assertOk();
        $this->actingAs($this->readonly, 'sanctum')->get("/api/v1/delivery/evidence/{$id}/file")->assertOk();
        $this->actingAs($this->readonly, 'sanctum')->deleteJson("/api/v1/delivery/evidence/{$id}")->assertForbidden();

        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/v1/delivery/evidence/{$id}")->assertOk();
        Storage::disk('local')->assertMissing($path);
    }

    /** WD-SCHEDULE-001 — the weekly sweep prepares a draft for opted-in clients only, once per week. */
    public function test_the_weekly_sweep_prepares_drafts_for_opted_in_clients_only_and_is_idempotent(): void
    {
        $other = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Henka', 'slug' => 'henka', 'mode' => 'managed', 'status' => 'active']);
        Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $other->id, 'name' => 'Other', 'status' => 'active']);
        $this->workspace->forceFill(['settings' => ['delivery' => ['weekly' => true]]])->save();

        $this->artisan('delivery:prepare-weekly', ['--date' => '2026-10-19'])->expectsOutputToContain('1 client(s) opted in, 1 pack(s) ensured.')->assertSuccessful();
        $this->artisan('delivery:prepare-weekly', ['--date' => '2026-10-19'])->assertSuccessful();

        $packs = DeliveryPack::withoutGlobalScopes()->get();
        $this->assertCount(1, $packs, 'asking again for the same week must not create a second pack');
        $this->assertSame((string) $this->project->id, (string) $packs[0]->project_id);
        $this->assertSame('2026-10-12', $packs[0]->period_start->toDateString());
        $this->assertSame('draft', $packs[0]->status, 'the sweep prepares; the Owner approves and sends');
    }

    /** DELIVERY-TASK-001 — one review task per pack, however many times the week is prepared. */
    public function test_a_prepared_pack_opens_exactly_one_review_task_bound_to_it(): void
    {
        $this->workspace->forceFill(['settings' => ['delivery' => ['weekly' => true]]])->save();
        $this->artisan('delivery:prepare-weekly', ['--date' => '2026-10-19'])->assertSuccessful();
        $this->artisan('delivery:prepare-weekly', ['--date' => '2026-10-19'])->assertSuccessful();
        $pack = DeliveryPack::withoutGlobalScopes()->firstOrFail();
        app(DeliveryPackService::class)->prepare($this->project, Carbon::parse('2026-10-19'), $this->owner);

        $tasks = Task::withoutGlobalScopes()->get();
        $this->assertCount(1, $tasks, 'the sweep, a re-run and a person preparing the same week must not add a second task');
        $task = $tasks[0];
        $this->assertSame(DeliveryTasks::SOURCE, $task->meta['source']);
        $this->assertSame((string) $pack->id, $task->meta['delivery_pack_id']);
        $this->assertSame((string) $this->tenant->id, (string) $task->tenant_id);
        $this->assertSame((string) $this->workspace->id, (string) $task->client_workspace_id);
        $this->assertSame('todo', $task->status);
        $this->assertSame('2026-10-19', $task->due_date->toDateString(), 'due the day after the week it covers');
        $this->assertStringContainsString('Razzah', $task->title);
    }

    /** Only «marked sent» — which needs an approved pack — closes the task; approval alone does not. */
    public function test_the_review_task_closes_only_when_the_pack_is_marked_sent(): void
    {
        $pack = $this->pack();
        $this->complete($pack);
        $service = app(DeliveryPackService::class);

        $service->approve($pack->fresh(), $this->owner);
        $this->assertSame('todo', DeliveryTasks::find($pack)?->status, 'approved is not delivered');

        $service->markSent($pack->fresh());
        $task = DeliveryTasks::find($pack);
        $this->assertSame('completed', $task?->status);
        $this->assertSame('delivery_pack.sent', $task?->meta['closed_by']);
    }

    public function test_a_review_task_someone_cancelled_is_left_cancelled_when_the_pack_is_sent(): void
    {
        $pack = $this->pack();
        $this->complete($pack);
        DeliveryTasks::find($pack)?->forceFill(['status' => 'cancelled'])->save();
        $service = app(DeliveryPackService::class);
        $service->approve($pack->fresh(), $this->owner);
        $service->markSent($pack->fresh());

        $this->assertSame('cancelled', DeliveryTasks::find($pack)?->status);
    }

    public function test_the_tasks_board_names_the_pack_a_task_came_from(): void
    {
        $pack = $this->pack();

        $row = collect($this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/tasks')->assertOk()->json('data'))->first();
        $this->assertSame('delivery', $row['source']['kind']);
        $this->assertSame((string) $pack->id, $row['source']['id']);
        $this->assertSame((string) $this->workspace->id, $row['source']['client_workspace_id']);
        $this->assertSame('draft', $row['source']['status']);
        $this->assertSame('2026-10-05 → 2026-10-11', $row['source']['period']);
    }

    /** DELIV-DASH-001 — one row per client, one next action, and the follow-up signal only where both facts hold. */
    public function test_the_overview_names_one_next_action_and_flags_a_viewed_report_with_an_overdue_invoice(): void
    {
        $this->actingAs($this->owner, 'sanctum');
        Carbon::setTestNow('2026-10-19 09:00:00');

        // Nothing prepared for last week yet.
        $row = $this->getJson('/api/v1/delivery/overview')->assertOk()->json('data.0');
        $this->assertSame('Razzah', $row['client']);
        $this->assertSame('prepare_pack', $row['next_action']);
        $this->assertNull($row['pack']);

        $this->bindTenant();
        $pack = app(DeliveryPackService::class)->prepare($this->project, Carbon::parse('2026-10-19'), $this->owner);
        $this->assertSame('review_pack', $this->getJson('/api/v1/delivery/overview')->json('data.0.next_action'), 'the report is not complete yet');

        $this->bindTenant();
        $this->complete($pack);
        $this->assertSame('approve_pack', $this->getJson('/api/v1/delivery/overview')->json('data.0.next_action'));

        $this->bindTenant();
        app(DeliveryPackService::class)->approve($pack->fresh(), $this->owner);
        $this->assertSame('send_pack', $this->getJson('/api/v1/delivery/overview')->json('data.0.next_action'));

        $this->bindTenant();
        app(DeliveryPackService::class)->markSent($pack->fresh());
        $this->assertSame('none', $this->getJson('/api/v1/delivery/overview')->json('data.0.next_action'));

        // An overdue invoice alone asks for a payment follow-up in its own right, but is not «viewed + overdue».
        $this->bindTenant();
        Invoice::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'number' => 'INV-D-1', 'currency' => 'SAR',
            'subtotal' => 1000, 'tax' => 150, 'total' => 1150, 'line_items' => [], 'amount_paid' => 150, 'status' => 'issued', 'due_date' => '2026-10-10', 'issued_at' => '2026-10-01',
        ]);
        $row = $this->getJson('/api/v1/delivery/overview')->json('data.0');
        $this->assertSame('overdue', $row['invoice']['status']);
        $this->assertEquals(1000, $row['invoice']['amount_due']);
        $this->assertFalse($row['follow_up_required']);
        $this->assertSame('follow_up_payment', $row['next_action']);
        $this->assertSame('urgent', $row['visual']);

        // Once the client has opened the report, it is a conversation to have.
        $this->bindTenant();
        $share = ReportShare::create([
            'tenant_id' => $this->tenant->id, 'report_id' => $pack->report_id, 'token_hash' => hash('sha256', 'x'), 'allow_download' => false,
            'view_count' => 1, 'last_viewed_at' => '2026-10-18 10:00:00',
        ]);
        $this->assertNotNull($share->id);
        $row = $this->getJson('/api/v1/delivery/overview')->json('data.0');
        $this->assertTrue($row['follow_up_required']);
        $this->assertNotNull($row['last_viewed_at']);

        Carbon::setTestNow();
    }

    public function test_a_reader_without_billing_access_sees_the_delivery_side_but_no_invoice(): void
    {
        Invoice::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'number' => 'INV-D-2', 'currency' => 'SAR',
            'subtotal' => 100, 'tax' => 15, 'total' => 115, 'line_items' => [], 'amount_paid' => 0, 'status' => 'issued', 'due_date' => '2026-10-01', 'issued_at' => '2026-09-20',
        ]);
        $this->actingAs($this->readonly, 'sanctum');

        $row = $this->getJson('/api/v1/delivery/overview')->assertOk()->json('data.0');

        $this->assertNull($row['invoice']);
        $this->assertFalse($row['follow_up_required']);
    }

    /** The tenant context is cleared after an HTTP request; direct service calls in the same test need it back. */
    private function bindTenant(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }
}
