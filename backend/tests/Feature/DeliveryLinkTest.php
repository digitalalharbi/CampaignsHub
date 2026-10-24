<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Delivery\Models\DeliveryPack;
use App\Domains\Delivery\Services\DeliveryPackService;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * DELIVERY-LINK-001 — the pack mints ONE report link when it is approved, and the WhatsApp message carries it.
 *
 * Before: the message named a link only when the browser passed one in, and nothing did — so the text the Owner copied never
 * contained the report. The link is created at approval, follows the pack's own choices (spend hidden unless switched on, PDF
 * download only if the PDF is part of the delivery), is stored encrypted and never serialised, and a revoked link is replaced
 * rather than sent dead.
 */
final class DeliveryLinkTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'Owner', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant, Portal::Agency);
        $this->owner->assignRole($role);
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Razzah', 'slug' => 'razzah', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'Store', 'status' => 'active']);
    }

    private function approvedPack(array $components = []): DeliveryPack
    {
        $service = app(DeliveryPackService::class);
        $pack = $service->prepare($this->project, Carbon::parse('2026-10-19'), $this->owner);
        Report::withoutGlobalScopes()->whereKey($pack->report_id)->update(['status' => 'completed', 'data' => json_encode(['totals' => ['conversions' => 5, 'impressions' => 100, 'spend' => 10.0, 'spend_withheld_rows' => 0], 'currency' => 'USD'])]);
        if ($components !== []) {
            $service->setComponents($pack->fresh(), $components);
        }
        $service->approve($pack->fresh(), $this->owner);

        return $pack->fresh();
    }

    public function test_approval_mints_one_link_that_follows_the_packs_choices_and_the_message_carries_it(): void
    {
        $pack = $this->approvedPack();
        $service = app(DeliveryPackService::class);

        $this->assertNotNull($pack->share_id);
        $share = ReportShare::withoutGlobalScopes()->findOrFail($pack->share_id);
        $this->assertTrue((bool) $share->hide_spend, 'spend stays hidden unless the Owner switched it on');
        $this->assertTrue((bool) $share->allow_download);

        $url = $service->linkUrl($pack);
        $this->assertNotNull($url);
        $this->assertStringContainsString($url, $service->whatsappSummary($pack, 'en'));
        $this->assertStringContainsString($url, $service->whatsappSummary($pack, 'ar'));

        $service->whatsappSummary($pack, 'en');
        $service->ensureLink($pack);
        $this->assertSame(1, ReportShare::withoutGlobalScopes()->where('report_id', $pack->report_id)->count(), 'asking again never mints a second link');
    }

    public function test_the_token_is_stored_encrypted_and_never_serialised(): void
    {
        $pack = $this->approvedPack();
        $url = app(DeliveryPackService::class)->linkUrl($pack);
        $token = basename((string) $url);

        $raw = (string) DB::table('delivery_packs')->where('id', $pack->id)->value('share_token');
        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString($token, $raw);

        $api = $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/delivery/packs/'.$pack->id)->assertOk();
        $this->assertStringNotContainsString($token, $api->getContent());
        $this->assertArrayNotHasKey('share_token', $pack->toArray());
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/delivery/packs/'.$pack->id.'/whatsapp?lang=en')->assertOk()
            ->assertJsonPath('data.text', fn (string $t) => str_contains($t, $url));
    }

    public function test_a_pack_that_does_not_send_the_link_creates_none_and_says_none(): void
    {
        $pack = $this->approvedPack(['link' => false]);

        $this->assertNull($pack->share_id);
        $this->assertSame(0, ReportShare::withoutGlobalScopes()->where('report_id', $pack->report_id)->count());
        $this->assertStringNotContainsString('/r/', app(DeliveryPackService::class)->whatsappSummary($pack, 'en'));
    }

    public function test_choices_made_after_approval_reach_the_link_and_a_revoked_link_is_never_sent(): void
    {
        $pack = $this->approvedPack();
        $service = app(DeliveryPackService::class);
        $share = ReportShare::withoutGlobalScopes()->findOrFail($pack->share_id);

        $service->setComponents($pack->fresh(), ['spend' => true, 'report_pdf' => false]);
        $share->refresh();
        $this->assertFalse((bool) $share->hide_spend);
        $this->assertFalse((bool) $share->allow_download);

        $old = $service->linkUrl($pack->fresh());
        $share->forceFill(['revoked_at' => Carbon::now()])->save();
        $this->assertNull($service->linkUrl($pack->fresh()));
        $this->assertStringNotContainsString($old, $service->whatsappSummary($pack->fresh(), 'en'), 'a revoked link is not put in the message');

        $fresh = $service->ensureLink($pack->fresh());
        $this->assertNotNull($fresh);
        $this->assertNotSame($old, $fresh);
        $this->assertStringContainsString($fresh, $service->whatsappSummary($pack->fresh(), 'en'));
    }
}
