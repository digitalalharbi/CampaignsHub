<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Quote;
use App\Domains\Billing\Services\BillingService;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Drive\Models\DriveFile;
use App\Domains\Drive\Models\DriveLink;
use App\Domains\Messaging\Models\MessageThread;
use App\Domains\Messaging\Services\MessagingService;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Requests\Models\ClientPortalToken;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestFile;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * DETERMINISTIC, idempotent demo accounts covering the THREE product experiences, so a fresh install has
 * real, login-tested data for each. Fully additive: it REUSES the existing models/services (no parallel
 * logic) and never touches DemoSeeder's tenant beyond ensuring its two demo logins are healthy.
 *
 *   1) OPERATIONS CONSOLE  — the personal/agency workspace (the existing demo-agency tenant). PERSONAL nav.
 *   2) SaaS WORKSPACE      — a NEW company tenant ("Demo Company") on the Growth plan. COMPANY nav.
 *   3) CLIENT PORTAL       — a verified customer of the demo-agency tenant with a FULL journey
 *                            (request + quote + issued invoice + message thread + files + campaign + report).
 *
 * SAFETY: never runs in production. Registered by DatabaseSeeder under local/testing/demo only, and it also
 * self-guards below so it stays inert if invoked directly. No real credentials or production data are used;
 * every payment stays honest (`awaiting_provider_credentials`) because no real provider is configured.
 */
final class DemoAccountsSeeder extends Seeder
{
    private const DEMO_UUID_NS = 'campaignshub-demo-accounts';

    private const PASSWORD = 'password';

    /** Non-production ONLY: a deterministic client-portal token so tooling can auth via X-Client-Token. */
    private const CLIENT_PORTAL_DEV_TOKEN = 'demo-client-portal-token';

    private const CLIENT_CONTACT_EMAIL = 'customer@demo-client.local';

    private const CLIENT_CONTACT_PHONE = '+966500000009';

    public function run(): void
    {
        if (! App::environment(['local', 'testing', 'demo'])) {
            return;
        }

        $this->ensureOperationsConsole();
        $this->seedSaasWorkspace();
        $this->seedClientPortal();

        $this->printSummary();
    }

    // ============================================================================================
    // 1) OPERATIONS CONSOLE — ensure/repair the existing demo-agency (personal) logins.
    // ============================================================================================

    private function ensureOperationsConsole(): void
    {
        $context = app(TenantContext::class);

        // The demo-agency tenant is owned by DemoSeeder; ensure it exists + is a fully-onboarded PERSONAL
        // (agency) workspace even if this seeder is ever run before/without it.
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'demo-agency'],
            ['name' => 'Demo Agency', 'status' => 'active'],
        );
        $tenant->forceFill([
            'account_type' => 'agency',
            'enabled_modules' => ['paid_media', 'influencer_marketing'],
            'onboarding_step' => 'done',
            'onboarding_completed_at' => $tenant->onboarding_completed_at ?? now(),
        ])->save();

        $context->setTenantId((string) $tenant->id);

        $ownerRole = Role::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'tenant-owner'],
            ['name' => 'Tenant Owner', 'is_system' => true],
        );
        $ownerRole->givePermissionTo(...Permission::pluck('key')->all());

        $analystRole = Role::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'analyst'],
            ['name' => 'Analyst', 'is_system' => true],
        );
        $analystRole->givePermissionTo('campaigns.view', 'projects.view', 'projects.view.all', 'integrations.view', 'reports.view');

        $this->ensureUser('owner@demo-agency.local', 'Demo Owner', $tenant, $ownerRole);
        $this->ensureUser('analyst@demo-agency.local', 'Demo Analyst', $tenant, $analystRole);

        $context->forget();
    }

    // ============================================================================================
    // 2) SaaS WORKSPACE — a NEW company tenant on the Growth plan with visible usage + real project data.
    // ============================================================================================

    private function seedSaasWorkspace(): void
    {
        $context = app(TenantContext::class);

        // A COMPANY account (self_serve_company) → AccountEntitlements returns the SaaS/COMPANY nav.
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'demo-company'],
            ['name' => 'Demo Company', 'status' => 'active'],
        );
        $tenant->forceFill([
            'account_type' => 'self_serve_company',
            'enabled_modules' => ['paid_media'],
            'subscription_plan' => 'growth',
            'onboarding_step' => 'done',
            'onboarding_completed_at' => $tenant->onboarding_completed_at ?? now(),
        ])->save();

        $context->setTenantId((string) $tenant->id);

        Workspace::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'default'],
            ['name' => 'Default Workspace'],
        );

        $ownerRole = Role::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'tenant-owner'],
            ['name' => 'Tenant Owner', 'is_system' => true],
        );
        $ownerRole->givePermissionTo(...Permission::pluck('key')->all());

        $memberRole = Role::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'member'],
            ['name' => 'Team Member', 'is_system' => true],
        );
        $memberRole->givePermissionTo('campaigns.view', 'projects.view', 'analytics.view', 'reports.view', 'connections.view', 'subscriptions.view');

        $this->ensureUser('owner@demo-company.local', 'Company Owner', $tenant, $ownerRole);
        $this->ensureUser('member@demo-company.local', 'Company Member', $tenant, $memberRole);

        // Assign the Growth plan through the real SubscriptionService (idempotent: one row per tenant).
        $growth = SubscriptionPlan::where('code', 'growth')->first();
        $subscriptions = app(SubscriptionService::class);
        if ($growth !== null) {
            $subscriptions->assignPlan($tenant, $growth, 'active', Carbon::now()->addMonth(), 5);
        }

        // Real project data (its own client workspace → project → campaign → metrics) so Dashboard /
        // Campaigns / Analytics / Reports render live numbers. Guarded so re-runs don't stack rows.
        $ws = ClientWorkspace::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'demo-company-workspace'],
            ['name' => 'Demo Company — Workspace', 'mode' => 'self_service', 'status' => 'active', 'client_status' => 'active', 'branding' => ['brand_name' => 'Demo Company']],
        );

        $projectA = Project::firstOrCreate(
            ['tenant_id' => $tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'Growth — Acquisition'],
            ['status' => 'active', 'setup_completion' => 100],
        );
        $projectB = Project::firstOrCreate(
            ['tenant_id' => $tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'Growth — Retention'],
            ['status' => 'active', 'setup_completion' => 80],
        );

        $campaign = UnifiedCampaign::firstOrCreate(
            ['project_id' => $projectA->id, 'name' => 'Always-On — Sales'],
            [
                'tenant_id' => $tenant->id, 'client_workspace_id' => $ws->id, 'objective' => 'sales',
                'status' => 'active', 'total_budget' => 80000, 'budget_currency' => 'SAR',
                'starts_on' => Carbon::today()->subDays(30), 'ends_on' => Carbon::today()->addDays(30),
                'meta' => ['is_demo' => true, 'primary_platform' => 'meta'],
            ],
        );

        $this->seedCampaignMetrics((string) $tenant->id, (string) $projectA->id, (string) $campaign->id, 'meta', 'company');

        // A completed, real-snapshot internal report so the Reports surface has data.
        if ($growth !== null) {
            $this->seedGrowthReport($tenant, $projectA);
        }

        // Make usage/limits visibly non-zero against the Growth caps (projects=25, team_members=15,
        // connections=25, reports_per_month=100). Guarded to the true seeded counts (idempotent).
        $this->setUsage($subscriptions, $tenant, 'projects', 2);
        $this->setUsage($subscriptions, $tenant, 'team_members', 2);
        $this->setUsage($subscriptions, $tenant, 'connections', 4);
        $this->setUsage($subscriptions, $tenant, 'reports_per_month', 6);

        $context->forget();
    }

    // ============================================================================================
    // 3) CLIENT PORTAL — a verified customer of the demo-agency tenant with a full, honest journey.
    // ============================================================================================

    private function seedClientPortal(): void
    {
        $context = app(TenantContext::class);

        $tenant = Tenant::firstOrCreate(
            ['slug' => 'demo-agency'],
            ['name' => 'Demo Agency', 'status' => 'active'],
        );
        $context->setTenantId((string) $tenant->id);

        $owner = User::where('email', 'owner@demo-agency.local')->first();

        $ws = ClientWorkspace::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'demo-client'],
            ['name' => 'Demo Client', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active', 'branding' => ['brand_name' => 'Demo Client']],
        );

        $project = Project::firstOrCreate(
            ['tenant_id' => $tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'Demo Client — Engagement'],
            ['account_manager_id' => $owner?->id, 'status' => 'active', 'setup_completion' => 90],
        );

        $type = RequestType::query()->where('key', 'paid_campaign_launch')->first() ?? RequestType::query()->firstOrFail();
        $status = RequestStatus::query()->where('key', 'in_progress')->first() ?? RequestStatus::query()->firstOrFail();

        // A single external request in a mid-journey stage, tied to the verified contact + this workspace.
        $request = ExternalRequest::updateOrCreate(
            ['tenant_id' => $tenant->id, 'reference' => 'REQ-DEMO-CLIENT-0001'],
            [
                'module' => 'paid_media',
                'type_id' => $type->id,
                'status_id' => $status->id,
                'priority' => 'normal',
                'contact_name' => 'Demo Client',
                'contact_email' => Str::lower(self::CLIENT_CONTACT_EMAIL),
                'contact_phone' => self::CLIENT_CONTACT_PHONE,
                'client_id' => $ws->id,
                'journey_stage' => 'in_progress',
                'is_external' => true,
                'is_demo' => true,
                'submitted_at' => Carbon::now()->subDays(6),
            ],
        );

        // A client-visible upload on the request (the portal /client/files surface).
        if (! RequestFile::where('request_id', $request->id)->exists()) {
            RequestFile::create([
                'request_id' => $request->id,
                'disk' => 'local',
                'path' => 'requests/'.$request->id.'/brief.pdf',
                'original_name' => 'campaign-brief.pdf',
                'mime' => 'application/pdf',
                'size' => 24576,
                'is_client_visible' => true,
                'created_at' => Carbon::now()->subDays(5),
            ]);
        }

        // A read-only Drive file reference scoped to the client's workspace.
        if (! DriveLink::where('tenant_id', $tenant->id)->where('scope', 'client')->where('scope_id', $ws->id)->exists()) {
            $link = DriveLink::create([
                'tenant_id' => $tenant->id, 'scope' => 'client', 'scope_id' => $ws->id,
                'folder_id' => $this->demoUuid('drive-folder:'.$ws->id), 'folder_name' => 'Shared with Demo Client',
            ]);
            DriveFile::create([
                'tenant_id' => $tenant->id, 'drive_link_id' => $link->id,
                'file_id' => $this->demoUuid('drive-file:'.$ws->id), 'name' => 'creative-final-v2.png',
                'mime' => 'image/png', 'size' => 102400,
                'web_view_link' => 'https://drive.example/demo-client/creative-final-v2.png',
                'modified_time' => Carbon::now()->subDays(2),
            ]);
        }

        // A linked campaign for the client (client-safe surface: /client/campaigns).
        $campaign = UnifiedCampaign::firstOrCreate(
            ['project_id' => $project->id, 'name' => 'National Day — Launch'],
            [
                'tenant_id' => $tenant->id, 'client_workspace_id' => $ws->id,
                'client_display_name' => 'National Day Launch', 'objective' => 'sales', 'status' => 'active',
                'total_budget' => 60000, 'budget_currency' => 'SAR',
                'starts_on' => Carbon::today()->subDays(14), 'ends_on' => Carbon::today()->addDays(16),
                'meta' => ['is_demo' => true, 'primary_platform' => 'snapchat'],
            ],
        );
        $this->seedCampaignMetrics((string) $tenant->id, (string) $project->id, (string) $campaign->id, 'snapchat', 'client');

        // Billing: an OPEN quote (sent) the client can approve, plus an approved quote whose issued invoice
        // is pending payment — both via the real BillingService. Guarded (numbers are generated).
        $billing = app(BillingService::class);
        if (! Quote::where('client_workspace_id', $ws->id)->exists()) {
            $billing->createQuote([
                'tenant_id' => $tenant->id, 'client_workspace_id' => $ws->id, 'project_id' => $project->id,
                'subtotal' => 5000, 'tax' => 750, 'total' => 5750, 'currency' => 'SAR', 'status' => 'sent',
                'valid_until' => Carbon::today()->addDays(14), 'created_by' => $owner?->id,
            ]);

            $approved = $billing->createQuote([
                'tenant_id' => $tenant->id, 'client_workspace_id' => $ws->id, 'project_id' => $project->id,
                'subtotal' => 12000, 'tax' => 1800, 'total' => 13800, 'currency' => 'SAR', 'status' => 'sent',
                'created_by' => $owner?->id,
            ]);
            $billing->approveQuote($approved); // → issued invoice, payment_status pending (honest pay flow)
        }

        // Messaging: a thread with a short team/client/team exchange (client-side unread on the last team post).
        $messaging = app(MessagingService::class);
        if (! MessageThread::where('client_workspace_id', $ws->id)->exists()) {
            $thread = $messaging->openThread([
                'tenant_id' => $tenant->id, 'client_workspace_id' => $ws->id, 'project_id' => $project->id,
                'subject' => 'National Day launch — kickoff', 'created_by' => $owner?->id,
            ]);
            $messaging->postMessage($thread, 'team', 'Welcome! We have received your brief and started the setup.', null, $owner?->id);
            $messaging->postMessage($thread, 'client', 'Great — when can we expect the first draft?');
            $messaging->postMessage($thread, 'team', 'First creative drafts land within 3 business days.', null, $owner?->id);
        }

        // Reports: a CLIENT-audience report with an ACTIVE secure share (the only kind the portal exposes).
        if (! Report::where('project_id', $project->id)->where('audience', 'client')->exists()) {
            $report = Report::create([
                'tenant_id' => $tenant->id, 'project_id' => $project->id, 'name' => 'Demo Client — Performance Summary',
                'type' => 'executive', 'audience' => 'client', 'status' => 'completed',
                'period_start' => Carbon::today()->subDays(29), 'period_end' => Carbon::today(),
                'currency' => 'SAR', 'timezone' => 'Asia/Riyadh', 'created_by' => $owner?->id,
                'generated_at' => Carbon::now(), 'is_demo' => true,
            ]);
            ReportShare::create([
                'tenant_id' => $tenant->id, 'report_id' => $report->id,
                'token_hash' => hash('sha256', $this->demoUuid('report-share:'.$report->id)),
                'allow_download' => true, 'watermark' => true,
                'expires_at' => Carbon::now()->addDays(30), 'created_by' => $owner?->id, 'is_demo' => true,
            ]);
        }

        // Pre-seed a portal identity so the demo login works immediately (NON-PRODUCTION ONLY). The browser
        // login flow (OTP) also works — the OTP dev_code is returned in non-prod. Only the token hash is stored.
        if (! App::environment('production')) {
            ClientPortalToken::updateOrCreate(
                ['token_hash' => hash('sha256', self::CLIENT_PORTAL_DEV_TOKEN)],
                [
                    'tenant_id' => $tenant->id,
                    'contact_email' => Str::lower(self::CLIENT_CONTACT_EMAIL),
                    'contact_phone' => self::CLIENT_CONTACT_PHONE,
                    'expires_at' => Carbon::now()->addYear(),
                    'revoked_at' => null,
                ],
            );
        }

        $context->forget();
    }

    // ---- shared helpers ----

    /** Ensure a demo user exists, verified, with the known password and role assigned. Idempotent. */
    private function ensureUser(string $email, string $name, Tenant $tenant, Role $role): User
    {
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make(self::PASSWORD),
                'tenant_id' => $tenant->id,
                'email_verified_at' => now(),
            ],
        );
        $user->assignRole($role);

        return $user;
    }

    /** Meter usage to an exact target for a metric via the real SubscriptionService (idempotent). */
    private function setUsage(SubscriptionService $service, Tenant $tenant, string $metric, int $target): void
    {
        $current = $service->usage($tenant, $metric);
        if ($current < $target) {
            $service->increment($tenant, $metric, $target - $current);
        }
    }

    /**
     * Seed a light, DETERMINISTIC 14-day metric series (SAR — no currency-rate rows needed) through the SAME
     * normalization + upsert path as live data. Upsert keys make it idempotent.
     */
    private function seedCampaignMetrics(string $tenantId, string $projectId, string $campaignId, string $provider, string $tag): void
    {
        $externalAccountId = $this->demoUuid("acct:{$tag}:{$provider}");
        $externalCampaignId = $this->demoUuid("camp:{$tag}:{$campaignId}");
        $start = Carbon::today()->subDays(13);

        $metrics = [];
        for ($d = 0; $d < 14; $d++) {
            $date = $start->copy()->addDays($d);
            // Deterministic daily shape (no rand).
            $impressions = 4000 + 220 * $d + 300 * (($d % 5));
            $clicks = (int) round($impressions * 0.035);
            $conversions = (int) round($clicks * 0.06);
            $spend = round($clicks * 1.25, 2);
            $revenue = round($conversions * 320.0, 2);

            $metrics[] = $this->metric($tenantId, $projectId, $externalAccountId, $externalCampaignId, $campaignId, $provider, 'impressions', $date, (float) $impressions);
            $metrics[] = $this->metric($tenantId, $projectId, $externalAccountId, $externalCampaignId, $campaignId, $provider, 'clicks', $date, (float) $clicks);
            $metrics[] = $this->metric($tenantId, $projectId, $externalAccountId, $externalCampaignId, $campaignId, $provider, 'conversions', $date, (float) $conversions);
            $metrics[] = $this->metric($tenantId, $projectId, $externalAccountId, $externalCampaignId, $campaignId, $provider, 'spend', $date, $spend, $spend);
            $metrics[] = $this->metric($tenantId, $projectId, $externalAccountId, $externalCampaignId, $campaignId, $provider, 'revenue', $date, $revenue, $revenue);
        }

        app(UpsertDailyMetrics::class)->handle($metrics);
    }

    private function metric(
        string $tenantId,
        string $projectId,
        string $externalAccountId,
        string $externalCampaignId,
        string $campaignId,
        string $provider,
        string $key,
        Carbon $date,
        float $value,
        ?float $money = null,
    ): NormalizedMetric {
        $isMoney = $money !== null;

        return new NormalizedMetric(
            tenantId: $tenantId,
            projectId: $projectId,
            externalAccountId: $externalAccountId,
            externalCampaignId: $externalCampaignId,
            provider: $provider,
            metricKey: $key,
            metricDate: $date,
            value: $value,
            unifiedCampaignId: $campaignId,
            originalCurrency: $isMoney ? 'SAR' : null,
            projectCurrency: $isMoney ? 'SAR' : null,
            originalAmount: $isMoney ? $money : null,
            convertedAmount: $isMoney ? $money : null,
            exchangeRate: $isMoney ? 1.0 : null,
            originalTimezone: 'UTC',
            projectTimezone: 'Asia/Riyadh',
            attributionWindow: '7d_click_1d_view',
            sourceType: 'api',
            dataFreshnessAt: $date->copy()->endOfDay(),
            raw: ['note' => 'demo-accounts'],
            isDemo: true,
        );
    }

    /** A completed report with a REAL generated snapshot from the metrics tables (deterministic). */
    private function seedGrowthReport(Tenant $tenant, Project $project): void
    {
        if (Report::where('project_id', $project->id)->exists()) {
            return;
        }
        $generator = app(ReportGenerator::class);
        $report = Report::create([
            'tenant_id' => $tenant->id, 'project_id' => $project->id, 'name' => 'Monthly — Executive Summary',
            'type' => 'monthly', 'audience' => 'internal', 'status' => 'processing',
            'period_start' => Carbon::today()->subDays(29), 'period_end' => Carbon::today(),
            'currency' => 'SAR', 'timezone' => 'Asia/Riyadh', 'attribution_window' => '7d_click_1d_view',
            'is_demo' => true,
        ]);
        $data = $generator->generate($report->fresh());
        $report->update(['data' => $data, 'status' => 'completed', 'generated_at' => now()]);
    }

    /** Stable UUIDv5 so re-seeding reuses the same synthetic ids (idempotent natural key). */
    private function demoUuid(string $seed): string
    {
        $ns = Uuid::uuid5(Uuid::NAMESPACE_DNS, self::DEMO_UUID_NS)->toString();

        return Uuid::uuid5($ns, $seed)->toString();
    }

    /** Machine-readable demo-login summary (one line per account). */
    private function printSummary(): void
    {
        $devToken = App::environment('production') ? '—' : self::CLIENT_PORTAL_DEV_TOKEN;

        $this->command->info('DEMO ACCOUNTS — three experiences (password = "'.self::PASSWORD.'"):');
        $this->command->info('experience=operations_console | url=/app | email=owner@demo-agency.local | password=password | role=owner | workspace=personal(agency) | plan=trial');
        $this->command->info('experience=operations_console | url=/app | email=analyst@demo-agency.local | password=password | role=analyst | workspace=personal(agency) | plan=trial');
        $this->command->info('experience=saas_workspace | url=/app | email=owner@demo-company.local | password=password | role=owner | workspace=company(self_serve_company) | plan=growth');
        $this->command->info('experience=saas_workspace | url=/app | email=member@demo-company.local | password=password | role=member | workspace=company(self_serve_company) | plan=growth');
        $this->command->info('experience=client_portal | url=/client | email='.self::CLIENT_CONTACT_EMAIL.' | phone='.self::CLIENT_CONTACT_PHONE.' | auth=OTP(dev_code in non-prod) or X-Client-Token='.$devToken.' | role=client_contact | workspace=client(Demo Client) | plan=n/a');
    }
}
