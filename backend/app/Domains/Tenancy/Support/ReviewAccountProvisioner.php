<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Support;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Services\TransitionAccountState;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Metrics\Models\SpendLimit;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Ramsey\Uuid\Uuid;

/**
 * SALLA-REVIEW-001 — the isolated review workspace and its demo data.
 *
 * Creates (or refreshes) the tenant «Salla Review», its single owner `review@campaignshub.io` with a password
 * the CALLER supplies — never one written here, never printed — and a demo project of thirty days across six
 * platforms and seventeen campaigns with budgets, a project spend limit and a weekly report. Everything is
 * marked demo and lives in this tenant only; nothing is read from or written to any other. Running it again
 * refreshes the demo window and the password and leaves the expiry alone unless one is passed.
 *
 * The figures are deterministic (no randomness), written through the ordinary metrics upsert so every
 * dashboard and report reads them exactly as it reads live data.
 */
final class ReviewAccountProvisioner
{
    public const SLUG = 'salla-review';

    public const EMAIL = 'review@campaignshub.io';

    private const DAYS = 30;

    /** [provider, name, impressions/day, ctr, cpc SAR, cvr, aov SAR, objective] */
    private const CAMPAIGNS = [
        ['snapchat', 'Snap — Story Ads', 42000, 0.014, 0.9, 0.055, 300, 'sales'],
        ['snapchat', 'Snap — Collection', 36000, 0.016, 1.1, 0.060, 320, 'sales'],
        ['snapchat', 'Snap — Dynamic', 30000, 0.013, 1.0, 0.048, 290, 'sales'],
        ['tiktok', 'TikTok — Spark Ads', 64000, 0.030, 0.7, 0.018, 260, 'sales'],
        ['tiktok', 'TikTok — TopView', 90000, 0.045, 0.8, 0.012, 240, 'awareness'],
        ['tiktok', 'TikTok — Retargeting', 22000, 0.026, 0.75, 0.028, 270, 'sales'],
        ['meta', 'Meta — Advantage+ Shopping', 58000, 0.019, 1.3, 0.052, 340, 'sales'],
        ['meta', 'Meta — Retargeting', 26000, 0.024, 1.5, 0.070, 360, 'sales'],
        ['meta', 'Meta — Prospecting Broad', 48000, 0.015, 1.1, 0.038, 320, 'sales'],
        ['meta', 'Meta — Brand Awareness', 80000, 0.010, 0.6, 0.004, 200, 'awareness'],
        ['google', 'Google Search — Brand', 14000, 0.085, 0.9, 0.075, 420, 'sales'],
        ['google', 'Google Shopping — Catalog', 20000, 0.020, 0.72, 0.045, 380, 'sales'],
        ['google', 'Google PMax — Prospecting', 28000, 0.012, 0.58, 0.030, 350, 'sales'],
        ['x', 'X — Promoted Posts', 52000, 0.011, 0.5, 0.010, 230, 'awareness'],
        ['x', 'X — Website Traffic', 24000, 0.016, 0.6, 0.020, 250, 'traffic'],
        ['linkedin', 'LinkedIn — Lead Gen', 9000, 0.006, 4.5, 0.040, 900, 'leads'],
        ['linkedin', 'LinkedIn — Sponsored Content', 12000, 0.007, 3.8, 0.015, 700, 'traffic'],
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly TransitionAccountState $transitions,
        private readonly GrantMembership $grants,
        private readonly UpsertDailyMetrics $upsert,
        private readonly ReportGenerator $reports,
    ) {}

    /**
     * @return array{tenant: Tenant, user: User, created: bool, expires_at: string}
     */
    public function provision(string $password, ?Carbon $expiresAt = null): array
    {
        if (mb_strlen($password) < 12) {
            throw new \InvalidArgumentException('The review password must be at least 12 characters.');
        }

        $previous = $this->context->tenantId();

        try {
            $tenant = Tenant::withTrashed()->where('slug', self::SLUG)->first();
            $created = $tenant === null;
            if ($tenant !== null && $tenant->trashed()) {
                $tenant->restore();
            }

            if ($tenant === null) {
                $tenant = Tenant::create([
                    'name' => 'Salla Review',
                    'slug' => self::SLUG,
                    'status' => 'inactive',
                    'account_type' => 'self_serve_company',
                    'subscription_plan' => 'salla-trial',
                    'enabled_modules' => ['paid_media'],
                    'onboarding_step' => 'done',
                    'onboarding_completed_at' => now(),
                ]);
                $this->transitions->provision($tenant, AccountState::Active, 'Provisioned as the Salla app-store review workspace.');
            }

            $existing = ReviewAccess::settingsOf($tenant);
            $expires = $expiresAt
                ?? ($existing !== null && $existing['expires_at'] !== null ? Carbon::parse($existing['expires_at']) : $this->defaultExpiry());
            $tenant->forceFill(['settings' => array_merge((array) ($tenant->settings ?? []), [
                'review' => ['active' => true, 'expires_at' => $expires->toIso8601String()],
            ])])->save();
            $this->context->setTenantId((string) $tenant->id);

            Workspace::firstOrCreate(['tenant_id' => $tenant->id, 'slug' => 'default'], ['name' => 'Default Workspace']);

            $user = User::query()->where('email', self::EMAIL)->first() ?? new User(['email' => self::EMAIL, 'name' => 'Salla Reviewer']);
            $user->password = Hash::make($password);
            $user->save();
            $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();

            $role = Role::firstOrCreate(['tenant_id' => $tenant->id, 'slug' => 'tenant-owner'], ['name' => 'Tenant Owner', 'is_system' => true]);
            $role->givePermissionTo(...Permission::pluck('key')->all());
            $user->assignRole($role);
            $this->grants->execute(new MembershipGrant(user: $user, tenant: $tenant, portal: Portal::forAccountType('self_serve_company'), role: 'owner'));

            $this->seedDemo($tenant, $user);

            return ['tenant' => $tenant->refresh(), 'user' => $user, 'created' => $created, 'expires_at' => $expires->toIso8601String()];
        } finally {
            $this->context->setTenantId($previous);
        }
    }

    /** `REVIEW_EXPIRES_AT` (any date string) or fourteen days from now. */
    public function defaultExpiry(): Carbon
    {
        $configured = config('app.review_expires_at');

        return is_string($configured) && trim($configured) !== '' ? Carbon::parse($configured) : Carbon::now()->addDays(14);
    }

    private function seedDemo(Tenant $tenant, User $owner): void
    {
        $client = ClientWorkspace::withTrashed()->firstOrNew(['tenant_id' => $tenant->id, 'slug' => 'salla-review-store']);
        $client->fill(['name' => 'Salla Review Store', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active', 'owner_id' => $owner->id, 'branding' => ['brand_name' => 'Salla Review Store']]);
        if ($client->trashed()) {
            $client->restore();
        }
        $client->save();

        $project = Project::firstOrCreate(
            ['client_workspace_id' => $client->id, 'name' => 'Salla Review — Demo Store'],
            ['status' => 'active', 'setup_completion' => 100],
        );

        $today = Carbon::today();
        $start = $today->copy()->subDays(self::DAYS - 1);
        $perCampaignBudget = 60000 / count(self::CAMPAIGNS);
        $metrics = [];

        foreach (self::CAMPAIGNS as $i => [$provider, $name, $impressions, $ctr, $cpc, $cvr, $aov, $objective]) {
            $campaign = UnifiedCampaign::firstOrCreate(
                ['project_id' => $project->id, 'name' => $name],
                [
                    'client_workspace_id' => $client->id,
                    'objective' => $objective,
                    'status' => $i % 8 === 7 ? 'paused' : 'active',
                    'total_budget' => round($perCampaignBudget, 2),
                    'budget_currency' => 'SAR',
                    'meta' => ['is_demo' => true, 'primary_platform' => $provider],
                ],
            );
            $account = Uuid::uuid5(Uuid::NAMESPACE_URL, "salla-review:acct:{$provider}")->toString();
            $external = Uuid::uuid5(Uuid::NAMESPACE_URL, "salla-review:camp:{$name}")->toString();

            for ($d = 0; $d < self::DAYS; $d++) {
                $date = $start->copy()->addDays($d);
                // A gentle weekly rhythm and a slow ramp, deterministic: the same figures on every run.
                $wave = 1 + 0.18 * sin(($d + $i) * 2 * M_PI / 7) + 0.004 * $d;
                $impr = (float) round($impressions * $wave);
                $clicks = (float) round($impr * $ctr);
                $conv = (float) round($clicks * $cvr * 0.5);
                $spend = round($clicks * $cpc, 2);
                $revenue = round($conv * $aov, 2);

                $add = function (string $key, float $value, bool $money = false) use (&$metrics, $tenant, $project, $account, $external, $campaign, $provider, $date): void {
                    $metrics[] = new NormalizedMetric(
                        tenantId: (string) $tenant->id,
                        projectId: (string) $project->id,
                        externalAccountId: $account,
                        externalCampaignId: $external,
                        provider: $provider,
                        metricKey: $key,
                        metricDate: $date,
                        value: round($value, 4),
                        unifiedCampaignId: (string) $campaign->id,
                        originalCurrency: $money ? 'SAR' : null,
                        projectCurrency: $money ? 'SAR' : null,
                        originalAmount: $money ? round($value, 4) : null,
                        convertedAmount: $money ? round($value, 4) : null,
                        exchangeRate: $money ? 1.0 : null,
                        originalTimezone: 'UTC',
                        projectTimezone: 'Asia/Riyadh',
                        attributionWindow: '7d_click_1d_view',
                        sourceType: 'api',
                        dataFreshnessAt: $date->copy()->endOfDay(),
                        raw: ['note' => 'salla-review-demo'],
                        isDemo: true,
                    );
                };

                $add('impressions', $impr);
                $add('clicks', $clicks);
                $add('conversions', $conv);
                $add('purchases', $conv);
                $add('spend', $spend, true);
                $add('revenue', $revenue, true);
            }
        }

        DB::transaction(function () use ($metrics): void {
            $this->upsert->handle($metrics);
        });

        SpendLimit::firstOrCreate(
            ['project_id' => $project->id, 'scope' => 'project', 'scope_id' => null],
            ['tenant_id' => $tenant->id, 'amount' => 60000, 'currency' => 'SAR', 'starts_on' => $start->toDateString(), 'ends_on' => $today->copy()->addDays(30)->toDateString(), 'thresholds' => [50, 80, 100], 'active' => true, 'created_by' => $owner->id],
        );

        $report = Report::updateOrCreate(
            ['project_id' => $project->id, 'name' => 'Weekly report — Salla Review store'],
            ['type' => 'weekly', 'status' => 'processing', 'period_start' => $today->copy()->subDays(6), 'period_end' => $today, 'currency' => 'SAR', 'timezone' => 'Asia/Riyadh', 'attribution_window' => '7d_click_1d_view', 'created_by' => $owner->id, 'is_demo' => true],
        );
        $report->update(['data' => $this->reports->generate($report->fresh()), 'status' => 'completed', 'generated_at' => now()]);
    }
}
