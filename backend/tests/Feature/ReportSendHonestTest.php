<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportRecipient;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * REPORT-SEND-HONEST-001 — «لا تعتبر الرسائل مرسلة دون دليل».
 *
 * The endpoint hands nothing to a mail transport on this base, so it may not claim otherwise: no
 * «sent» answer, no `last_sent_at`, no recipient row stamped as sent, no `report.sent` audit row.
 */
final class ReportSendHonestTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c', 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
    }

    private function report(string $audience = 'client'): Report
    {
        return Report::create([
            'project_id' => $this->project->id, 'name' => 'R', 'type' => 'executive', 'audience' => $audience,
            'status' => 'completed', 'currency' => 'SAR', 'data' => ['currency' => 'SAR'],
        ]);
    }

    private function sendUrl(Report $report): string
    {
        return "/api/v1/projects/{$this->project->id}/reports/{$report->id}/send";
    }

    public function test_it_refuses_and_records_no_send_of_any_kind(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->owner);
        $report = $this->report();

        $this->postJson($this->sendUrl($report), ['recipients' => ['client@external.test']])
            ->assertStatus(501)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.code', 'report_send_unavailable');

        $this->assertNull($report->fresh()->last_sent_at);
        $this->assertSame(0, ReportRecipient::query()->where('report_id', $report->id)->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'report.sent')->count());
        Mail::assertNothingOutgoing();
    }

    public function test_a_report_sent_earlier_keeps_the_value_it_already_holds(): void
    {
        Sanctum::actingAs($this->owner);
        $report = $this->report();
        $report->update(['last_sent_at' => '2026-10-01 10:00:00+00']);
        $before = $report->fresh()->last_sent_at?->toIso8601String();

        $this->postJson($this->sendUrl($report), ['recipients' => ['client@external.test']])->assertStatus(501);

        $this->assertSame($before, $report->fresh()->last_sent_at?->toIso8601String());
    }

    public function test_the_refusal_is_in_arabic_by_default_and_in_english_on_request(): void
    {
        Sanctum::actingAs($this->owner);
        $report = $this->report();

        $arabic = $this->postJson($this->sendUrl($report), ['recipients' => ['client@external.test']])->assertStatus(501);
        $this->assertStringContainsString('لم يُرسل', (string) $arabic->json('message'));

        $english = $this->postJson($this->sendUrl($report), ['recipients' => ['client@external.test']], ['Accept-Language' => 'en'])->assertStatus(501);
        $this->assertStringContainsString('was not sent', (string) $english->json('message'));
    }

    public function test_input_validation_still_answers_before_the_refusal(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson($this->sendUrl($this->report()), ['recipients' => ['not-an-email']])->assertStatus(422);
    }

    public function test_a_member_without_the_permission_is_still_forbidden(): void
    {
        $viewerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'V', 'slug' => 'v']);
        $viewerRole->givePermissionTo('reports.view');
        $viewer = User::create(['name' => 'V', 'email' => 'v@a.test', 'password' => 'secret123']);
        $this->grantMembership($viewer, $this->tenant);
        $viewer->assignRole($viewerRole);
        Sanctum::actingAs($viewer);

        $this->postJson($this->sendUrl($this->report()), ['recipients' => ['client@external.test']])->assertStatus(403);
    }
}
