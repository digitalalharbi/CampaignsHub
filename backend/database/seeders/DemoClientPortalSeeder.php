<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Quote;
use App\Domains\Billing\Support\TaxTreatment;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Messaging\Models\Message;
use App\Domains\Messaging\Models\MessageThread;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestFile;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;

/**
 * ONE client's portal, filled — the eight sections `/portal` actually reads (DEMO-PORTAL-001).
 *
 * `client@demo-portal.local` could sign in, and then every section it landed on was empty: zero
 * requests, zero quotes, zero invoices, zero messages, zero files, zero reports. A portal that opens
 * onto eight empty states cannot be reviewed, demonstrated, or usefully tested — an empty list and a
 * broken query look identical from the outside, which is precisely the confusion the contract's
 * «صفر Placeholder» rule exists to prevent.
 *
 * ONE client on purpose. The demo agency has six client spaces; this fills the single one the portal
 * account is scoped to (`demo-managed` — «Acme (Managed) — Demo») and deliberately leaves Nova, Zahra
 * and Northwind empty. That asymmetry is the point: signing in and seeing Acme's four requests and
 * NOT the other five spaces' is the isolation working, visible without reading a line of code.
 *
 * Every row is demo data and says so:
 *   - `external_requests`, `reports`, `report_shares` and `daily_metrics` carry `is_demo = true`;
 *   - quotes, invoices, threads and messages have no such column, so they are keyed by the reserved
 *     prefixes below — `Q-DEMO-`, `INV-DEMO-`, `REQ-DEMO-` — which `demo:remove` matches exactly and
 *     which no issued document of a real client can collide with.
 *
 * Deterministic and idempotent: re-running produces the same numbers and the same rows, never a
 * second copy. DEVELOPMENT ONLY — `run()` refuses outside local/testing/demo.
 */
final class DemoClientPortalSeeder extends Seeder
{
    /** The one client space this seeder fills, and the account that reaches it. */
    public const WORKSPACE_SLUG = 'demo-managed';

    public const CONTACT_EMAIL = 'client@demo-portal.local';

    /** Reserved keys — `demo:remove` deletes by these, so they must never match a real document. */
    public const REQUEST_PREFIX = 'REQ-DEMO-P';

    public const QUOTE_PREFIX = 'Q-DEMO-';

    public const INVOICE_PREFIX = 'INV-DEMO-';

    /**
     * Conversations have no number to prefix, so their subjects are the key `demo:remove` matches.
     * Declared here rather than duplicated there, so a subject cannot be reworded in one place and
     * silently left behind in the other.
     *
     * @var list<string>
     */
    public const THREAD_SUBJECTS = [
        'استفسار عن أداء حملة اليوم الوطني',
        'تسليم تقرير الربع الثالث',
    ];

    /** Thirty days of delivery so the client's campaign cards are not a row of zeros. */
    private const METRIC_DAYS = 30;

    private const CURRENCY = 'SAR';

    public function run(): void
    {
        if (! App::environment(['local', 'testing', 'demo'])) {
            $this->command?->warn('Client-portal demo data is development-only — skipped.');

            return;
        }

        $tenant = Tenant::query()->withoutGlobalScopes()->where('slug', 'demo-agency')->first();
        if ($tenant === null) {
            $this->command?->warn('No demo-agency tenant — the client portal demo data was skipped.');

            return;
        }

        app(TenantContext::class)->setTenantId((string) $tenant->getKey());

        $workspace = ClientWorkspace::query()->where('slug', self::WORKSPACE_SLUG)->first();
        if ($workspace === null) {
            $this->command?->warn('No «'.self::WORKSPACE_SLUG.'» client space — the client portal demo data was skipped.');
            app(TenantContext::class)->forget();

            return;
        }

        $project = Project::firstOrCreate(
            ['client_workspace_id' => $workspace->getKey(), 'name' => 'Q3 Launch — Demo'],
            ['status' => 'active', 'setup_completion' => 100],
        );

        $requests = $this->requests($workspace, $project);
        $this->files($requests);
        $quotes = $this->quotes($workspace, $project, $requests);
        $this->invoices($workspace, $quotes);
        $this->conversations($workspace, $project);
        $campaigns = $this->campaigns($workspace, $project);
        $this->delivery($tenant, $project, $campaigns);
        $this->sharedReport($project);

        app(TenantContext::class)->forget();

        $this->command?->info('Demo: «'.$workspace->name.'» now has a full client portal — requests, quotes, invoices, messages, files, campaigns and a shared report.');
    }

    // ---- 1. Requests --------------------------------------------------------------------------

    /**
     * Four requests at four different points of the journey.
     *
     * One is deliberately `waiting_client`: the portal's home counts «طلبات مفتوحة», and a demo where
     * every request is finished never shows that counter doing anything.
     *
     * @return array<string, ExternalRequest> keyed by reference suffix
     */
    private function requests(ClientWorkspace $workspace, Project $project): array
    {
        $types = RequestType::query()->pluck('id', 'key');
        $statuses = RequestStatus::query()->pluck('id', 'key');
        $today = Carbon::today();

        $rows = [
            '1' => ['paid_campaign_launch', 'in_progress', 24, 'إطلاق حملة اليوم الوطني', 45000.00, 'delivery'],
            '2' => ['performance_optimization', 'waiting_client', 9, 'تحسين أداء حملات المبيعات', 18000.00, 'qualification'],
            '3' => ['build_report', 'completed', 41, 'تقرير أداء الربع الثالث', 6000.00, 'delivery'],
            '4' => ['tracking_setup', 'quoted', 5, 'إعداد التتبع والتحويلات للمتجر', 12000.00, 'quotation'],
        ];

        $out = [];
        foreach ($rows as $suffix => [$type, $status, $daysAgo, $title, $budget, $stage]) {
            $submitted = $today->copy()->subDays($daysAgo);

            $request = ExternalRequest::updateOrCreate(
                ['reference' => self::REQUEST_PREFIX.$suffix],
                [
                    'tenant_id' => $workspace->tenant_id,
                    'module' => 'requests',
                    'type_id' => $types[$type] ?? $types->first(),
                    'status_id' => $statuses[$status] ?? $statuses->first(),
                    'priority' => $suffix === '1' ? 'high' : 'normal',
                    'source' => 'portal',
                    'client_id' => $workspace->getKey(),
                    'project_id' => $project->getKey(),
                    'contact_name' => 'Demo Client',
                    'contact_email' => self::CONTACT_EMAIL,
                    'contact_phone' => '+966500000001',
                    'company_name' => 'Acme',
                    'objective' => $title,
                    'budget' => $budget,
                    'currency' => self::CURRENCY,
                    'journey_stage' => $stage,
                    'is_external' => true,
                    'is_demo' => true,
                    'submitted_at' => $submitted,
                    'last_activity_at' => $today->copy()->subDays(max(0, $daysAgo - 3)),
                ],
            );

            $out[$suffix] = $request;
        }

        return $out;
    }

    // ---- 2. Files -----------------------------------------------------------------------------

    /**
     * Two client-visible attachments, written to disk for real.
     *
     * A `request_files` row whose file does not exist is a download button that 500s on click — a
     * dead control, which the contract forbids as plainly as a placeholder. The bytes are tiny and
     * deterministic, and re-running overwrites rather than accumulating.
     *
     * @param  array<string, ExternalRequest>  $requests
     */
    private function files(array $requests): void
    {
        $files = [
            ['1', 'خطة-حملة-اليوم-الوطني.txt', "خطة حملة اليوم الوطني — نسخة تجريبية\nالمنصات: Meta · Google · TikTok\nالميزانية: 45,000 SAR\n"],
            ['3', 'تقرير-الربع-الثالث.txt', "تقرير أداء الربع الثالث — نسخة تجريبية\nالفترة: 2026-07-01 إلى 2026-07-31\n"],
        ];

        foreach ($files as [$suffix, $name, $body]) {
            $request = $requests[$suffix] ?? null;
            if ($request === null) {
                continue;
            }

            $path = 'demo/portal/'.$request->reference.'/'.md5($name).'.txt';
            Storage::disk('local')->put($path, $body);

            RequestFile::updateOrCreate(
                ['request_id' => $request->getKey(), 'original_name' => $name],
                [
                    'disk' => 'local',
                    'path' => $path,
                    'mime' => 'text/plain',
                    'size' => strlen($body),
                    'is_client_visible' => true,
                    'checksum' => hash('sha256', $body),
                ],
            );
        }
    }

    // ---- 3. Quotes ----------------------------------------------------------------------------

    /**
     * Three quotes: one awaiting the client's answer, one they approved, one they declined.
     *
     * The `sent` one is what makes the portal's «عروض بانتظار ردّك» counter non-zero and gives the
     * Approve/Reject buttons something real to act on — those endpoints exist and are tested, and a
     * demo with nothing in `sent` can never exercise them by hand.
     *
     * @param  array<string, ExternalRequest>  $requests
     * @return array<string, Quote>
     */
    private function quotes(ClientWorkspace $workspace, Project $project, array $requests): array
    {
        $today = Carbon::today();

        $rows = [
            '1001' => ['sent', '4', 12000.00, 14, [
                ['description' => 'إعداد التتبع والتحويلات', 'qty' => 1, 'unit_price' => 8000],
                ['description' => 'مراجعة وضبط الأحداث', 'qty' => 1, 'unit_price' => 4000],
            ]],
            '1002' => ['approved', '1', 45000.00, -20, [
                ['description' => 'إدارة حملة اليوم الوطني — شهر', 'qty' => 1, 'unit_price' => 30000],
                ['description' => 'إنتاج محتوى إعلاني', 'qty' => 3, 'unit_price' => 5000],
            ]],
            '1003' => ['rejected', '2', 26000.00, -35, [
                ['description' => 'باقة تحسين الأداء الموسّعة', 'qty' => 1, 'unit_price' => 26000],
            ]],
        ];

        $out = [];
        foreach ($rows as $number => [$status, $requestSuffix, $subtotal, $validInDays, $lineItems]) {
            $tax = TaxTreatment::taxFor(TaxTreatment::DEFAULT, $subtotal);

            $out[$number] = Quote::updateOrCreate(
                ['number' => self::QUOTE_PREFIX.$number],
                [
                    'tenant_id' => $workspace->tenant_id,
                    'client_workspace_id' => $workspace->getKey(),
                    'external_request_id' => $requests[$requestSuffix]->getKey() ?? null,
                    'project_id' => $project->getKey(),
                    'currency' => self::CURRENCY,
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'tax_treatment' => TaxTreatment::DEFAULT,
                    'discount' => 0,
                    'total' => $subtotal + $tax,
                    'line_items' => $lineItems,
                    'status' => $status,
                    'valid_until' => $today->copy()->addDays($validInDays),
                    'notes' => 'عرض سعر تجريبي — Demo.',
                ],
            );
        }

        return $out;
    }

    // ---- 4. Invoices --------------------------------------------------------------------------

    /**
     * Three invoices: paid, part-paid, and one still due.
     *
     * `amount_paid` is a real partial figure rather than a rounded story, so the payment-status
     * derivation (`partially_paid`) has something to derive from. Nothing here is marked paid by a
     * payment the product did not take: these are seeded historical states, not settlements, and the
     * live payment path still refuses to invent a success without a verified webhook.
     *
     * @param  array<string, Quote>  $quotes
     */
    private function invoices(ClientWorkspace $workspace, array $quotes): void
    {
        $today = Carbon::today();

        $rows = [
            '2001' => ['paid', 45000.00, 45000.00, -18, -12],
            '2002' => ['issued', 12000.00, 0.00, 9, -6],
            '2003' => ['partially_paid', 26000.00, 10000.00, -2, -30],
        ];

        $quoteFor = ['2001' => '1002', '2002' => '1001', '2003' => '1003'];

        foreach ($rows as $number => [$status, $subtotal, $paidBase, $dueInDays, $issuedDaysAgo]) {
            $tax = TaxTreatment::taxFor(TaxTreatment::DEFAULT, $subtotal);
            $total = $subtotal + $tax;
            $paid = $status === 'paid' ? $total : $paidBase;

            Invoice::updateOrCreate(
                ['number' => self::INVOICE_PREFIX.$number],
                [
                    'tenant_id' => $workspace->tenant_id,
                    'client_workspace_id' => $workspace->getKey(),
                    'quote_id' => $quotes[$quoteFor[$number]]->getKey() ?? null,
                    'currency' => self::CURRENCY,
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'tax_treatment' => TaxTreatment::DEFAULT,
                    'discount' => 0,
                    'total' => $total,
                    'line_items' => $quotes[$quoteFor[$number]]->line_items ?? [],
                    'amount_paid' => $paid,
                    'status' => $status,
                    'due_date' => $today->copy()->addDays($dueInDays),
                    'issued_at' => $today->copy()->addDays($issuedDaysAgo),
                    'paid_at' => $status === 'paid' ? $today->copy()->addDays($issuedDaysAgo + 4) : null,
                ],
            );
        }
    }

    // ---- 5. Messages --------------------------------------------------------------------------

    /**
     * Two conversations, one of them ending on an UNREAD message from the team.
     *
     * Unread is derived (`read_by_client_at IS NULL`), never stored as a counter, so the only way to
     * demonstrate the portal's «رسائل غير مقروءة» badge is to leave a message genuinely unread.
     *
     * A thread is NOT tied back to its request here, even though the conversations are about them:
     * `message_threads.request_id` is a `uuid` column and external requests are ULIDs, so the link is
     * unwritable (`MessagingController` validates that field as `uuid` and would refuse one). Seeding
     * the subject line rather than forcing a value keeps the demo honest about what the schema
     * currently supports.
     */
    private function conversations(ClientWorkspace $workspace, Project $project): void
    {
        $now = Carbon::now();

        $threads = [
            [
                'subject' => self::THREAD_SUBJECTS[0],
                'messages' => [
                    ['client', 'مرحبًا، هل يمكن مراجعة أداء حملة اليوم الوطني هذا الأسبوع؟', 4, true],
                    ['team', 'أهلًا بك. راجعنا الحملة اليوم ورفعنا ميزانية الإعلانات الأفضل أداءً.', 3, true],
                    ['team', 'أضفنا تقرير الأسبوع في قسم التقارير — النتائج ارتفعت 18% مقارنة بالأسبوع الماضي.', 1, false],
                ],
            ],
            [
                'subject' => self::THREAD_SUBJECTS[1],
                'messages' => [
                    ['team', 'تم تسليم تقرير الربع الثالث، والملف متاح في قسم الملفات.', 12, true],
                    ['client', 'شكرًا لكم، وصلني الملف.', 11, true],
                ],
            ],
        ];

        foreach ($threads as $row) {
            $thread = MessageThread::updateOrCreate(
                ['client_workspace_id' => $workspace->getKey(), 'subject' => $row['subject']],
                [
                    'tenant_id' => $workspace->tenant_id,
                    'project_id' => $project->getKey(),
                    'status' => 'open',
                    'last_message_at' => $now->copy()->subDays((int) $row['messages'][count($row['messages']) - 1][2]),
                ],
            );

            foreach ($row['messages'] as [$author, $body, $daysAgo, $readByClient]) {
                $at = $now->copy()->subDays($daysAgo);

                $message = Message::updateOrCreate(
                    ['thread_id' => $thread->getKey(), 'body' => $body],
                    [
                        'tenant_id' => $workspace->tenant_id,
                        'author_type' => $author,
                        // A message the client wrote is read by the client by definition; the team's
                        // last one is left unread on purpose.
                        'read_by_client_at' => $author === 'client' || $readByClient ? $at : null,
                        'read_by_team_at' => $author === 'team' ? $at : $at->copy()->addHours(2),
                    ],
                );

                $message->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
            }
        }
    }

    // ---- 6. Campaigns -------------------------------------------------------------------------

    /**
     * Three campaigns on three platforms and three objectives.
     *
     * The objectives differ deliberately: the client's campaign list is the surface where «هذه حملة
     * وعي، لا تُقاس بتكلفة الطلب» has to be legible, and a demo where everything is `sales` cannot
     * show it. `objective_source` stays `platform` because that is where these values would come
     * from on a live account.
     *
     * @return list<UnifiedCampaign>
     */
    private function campaigns(ClientWorkspace $workspace, Project $project): array
    {
        $rows = [
            ['National Day Sale — Demo', 'sales', 'active', 'meta', 'OUTCOME_SALES', 120000.00],
            ['Brand Awareness — Demo', 'awareness', 'active', 'tiktok', 'REACH', 60000.00],
            ['Store Traffic — Demo', 'traffic', 'paused', 'google', 'SEARCH_TRAFFIC', 70000.00],
        ];

        $out = [];
        foreach ($rows as [$name, $objective, $status, $platform, $platformValue, $budget]) {
            $campaign = UnifiedCampaign::updateOrCreate(
                ['project_id' => $project->getKey(), 'name' => $name],
                [
                    'tenant_id' => $workspace->tenant_id,
                    'client_workspace_id' => $workspace->getKey(),
                    'objective' => $objective,
                    'objective_source' => 'platform',
                    'objective_platform_value' => $platformValue,
                    'status' => $status,
                    'total_budget' => $budget,
                    'budget_currency' => self::CURRENCY,
                    'platforms' => [$platform],
                    'meta' => ['is_demo' => true, 'primary_platform' => $platform],
                ],
            );

            $out[] = $campaign;
        }

        return $out;
    }

    // ---- 7. Delivery --------------------------------------------------------------------------

    /**
     * Thirty days of metrics through the SAME upsert path live data uses.
     *
     * Not inserted straight into `daily_metrics`: the client's campaign cards read through
     * `MetricsAggregator`, and demo rows that skipped normalisation would be the one place in the
     * product where the numbers came from somewhere else. Deterministic — no `rand()` — so the demo
     * reads the same on every install.
     *
     * @param  list<UnifiedCampaign>  $campaigns
     */
    private function delivery(Tenant $tenant, Project $project, array $campaigns): void
    {
        // Per campaign: [provider, daily impressions, ctr, cpc, cvr, average order value]
        $shape = [
            ['meta', 42000, 0.021, 1.35, 0.048, 340.0],
            ['tiktok', 96000, 0.009, 0.55, 0.0, 0.0],   // awareness: reach, and no orders to divide by
            ['google', 18000, 0.061, 0.95, 0.036, 380.0],
        ];

        $today = Carbon::today();
        $start = $today->copy()->subDays(self::METRIC_DAYS - 1);
        $metrics = [];

        foreach ($campaigns as $i => $campaign) {
            [$provider, $baseImpressions, $ctr, $cpc, $cvr, $aov] = $shape[$i] ?? $shape[0];
            $externalCampaignId = (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, 'campaignshub-demo-portal:camp:'.$campaign->name);
            $externalAccountId = (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, 'campaignshub-demo-portal:acct:'.$provider);

            for ($d = 0; $d < self::METRIC_DAYS; $d++) {
                $date = $start->copy()->addDays($d);

                // A gentle weekly rhythm and an upward trend — enough shape to make a chart worth reading.
                $rhythm = 1 + 0.22 * sin(($d + $i * 5) / 5.0) + 0.10 * ($d / self::METRIC_DAYS);
                $impressions = round($baseImpressions * $rhythm);
                $clicks = round($impressions * $ctr);
                // Whole orders. A store's conversion is a purchase, and «1,231.05 طلبًا» on a client's
                // card reads as a rounding bug rather than as modelled precision. The formatter is
                // left alone so a platform that genuinely reports fractional conversions still shows
                // exactly what it reported.
                $conversions = round($clicks * $cvr);
                $spend = round($clicks * $cpc, 2);
                $revenue = round($conversions * $aov, 2);

                $add = function (string $key, float $value, bool $money = false) use (
                    &$metrics, $tenant, $project, $campaign, $provider, $date, $externalAccountId, $externalCampaignId
                ): void {
                    $metrics[] = new NormalizedMetric(
                        tenantId: (string) $tenant->getKey(),
                        projectId: (string) $project->getKey(),
                        externalAccountId: $externalAccountId,
                        externalCampaignId: $externalCampaignId,
                        provider: $provider,
                        metricKey: $key,
                        metricDate: $date,
                        value: $value,
                        unifiedCampaignId: (string) $campaign->getKey(),
                        originalCurrency: $money ? self::CURRENCY : null,
                        projectCurrency: $money ? self::CURRENCY : null,
                        originalAmount: $money ? $value : null,
                        convertedAmount: $money ? $value : null,
                        exchangeRate: $money ? 1.0 : null,
                        originalTimezone: 'UTC',
                        projectTimezone: 'Asia/Riyadh',
                        attributionWindow: '7d_click_1d_view',
                        sourceType: 'api',
                        dataFreshnessAt: $date->copy()->endOfDay(),
                        isDemo: true,
                    );
                };

                $add('impressions', $impressions);
                $add('clicks', $clicks);
                $add('reach', round($impressions * 0.62));
                $add('spend', $spend, true);

                // An awareness campaign reports no orders and no revenue. Writing zeros instead would
                // put a real 0 into the sales figures, which is the mixing REPORT-OBJECTIVE-14 forbids.
                if ($conversions > 0) {
                    $add('conversions', $conversions);
                    $add('purchases', $conversions);
                    $add('revenue', $revenue, true);
                }
            }
        }

        app(UpsertDailyMetrics::class)->handle($metrics);
    }

    // ---- 8. A report the client can actually see ----------------------------------------------

    /**
     * One client-facing report, generated from the metrics above and shared over an active link.
     *
     * `/client/reports` returns a report only when it is `audience = client` AND currently shared —
     * both conditions, deliberately, so an internal draft can never reach the portal by being listed
     * on the wrong project. Seeding a report without a share therefore proves nothing: the list stays
     * empty and looks identical to a broken query.
     *
     * The snapshot comes from `ReportGenerator`, not from a hand-written array. A demo report full of
     * invented figures would be the independent data source the contract forbids
     * («إنشاء مصدر بيانات مستقل للتقارير») — this one is the same pipeline the dashboard reads.
     */
    private function sharedReport(Project $project): void
    {
        $today = Carbon::today();

        $report = Report::updateOrCreate(
            ['project_id' => $project->getKey(), 'name' => 'تقرير الأداء الشهري — Demo'],
            [
                'tenant_id' => $project->tenant_id,
                'type' => 'monthly',
                'form' => 'detailed',
                'audience' => 'client',
                'status' => 'processing',
                'currency' => self::CURRENCY,
                'period_start' => $today->copy()->subDays(self::METRIC_DAYS - 1),
                'period_end' => $today,
                'is_demo' => true,
            ],
        );

        $data = app(ReportGenerator::class)->generate($report);
        $report->forceFill([
            'data' => $data,
            'status' => 'completed',
            'generated_at' => Carbon::now(),
        ])->save();

        // One active share, and only one: re-running must not leave a trail of live links behind.
        $shared = ReportShare::query()
            ->where('report_id', $report->getKey())
            ->whereNull('revoked_at')
            ->exists();

        if (! $shared) {
            app(ShareService::class)->create($report, ['allow_download' => true], null);
        }
    }
}
