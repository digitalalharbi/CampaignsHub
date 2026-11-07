<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Integrations\Measurement\Ga4PropertyDiscovery;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Support\DemoAccountSelection;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * DEMO — a GA4 property for the demo store, labelled demo end to end (GA4-STORE-ANALYTICS-001).
 *
 * No live property exists, so the Analytics store reading, the live link and the saved report have
 * nothing to draw locally without one. This writes the rows a sync WOULD write, in the product's own
 * stored keys (`Ga4Metrics::MAP`, `Ga4Breakdowns`), for the demo store project only. Additive and
 * idempotent: fixed ids, upserts, nothing outside the demo tenant is read or touched. Never runs in
 * production (DatabaseSeeder calls it in local / demo only).
 *
 * The figures are invented and shaped to be legible, not realistic: a paid-social-led store whose
 * platforms claim a little more than GA4 measured for them, so the gap card has something to show.
 */
final class DemoGa4Seeder extends Seeder
{
    private const NS = 'campaignshub-demo-ga4';

    private const DAYS = 60;

    /** source, medium, channel group, share of sessions, purchase rate */
    private const SOURCES = [
        ['snapchat', 'cpc', 'Paid Social', 0.30, 0.022],
        ['facebook', 'paid_social', 'Paid Social', 0.14, 0.018],
        ['instagram', 'cpc', 'Paid Social', 0.08, 0.017],
        ['tiktok', 'cpc', 'Paid Social', 0.10, 0.012],
        ['google', 'cpc', 'Paid Search', 0.08, 0.030],
        ['google', 'organic', 'Organic Search', 0.14, 0.020],
        ['(direct)', '(none)', 'Direct', 0.10, 0.025],
        ['t.co', 'referral', 'Referral', 0.03, 0.010],
        ['newsletter', 'email', 'Email', 0.03, 0.035],
    ];

    private const DEVICES = [['mobile', 0.82], ['desktop', 0.15], ['tablet', 0.03]];

    private const COUNTRIES = [['Saudi Arabia', 0.78], ['Kuwait', 0.07], ['United Arab Emirates', 0.06], ['Qatar', 0.04], ['Bahrain', 0.03], ['Oman', 0.02]];

    private const PAGES = [['/', 0.34], ['/collections/abaya', 0.22], ['/products/black-abaya', 0.14], ['/products/gold-embroidery', 0.09], ['/collections/new', 0.08], ['/products/silk-scarf', 0.06], ['/sale', 0.04], ['/products/leather-bag', 0.03]];

    private const CAMPAIGNS = [['eid_collection', 'snapchat / cpc', 0.18], ['retargeting_30d', 'facebook / paid_social', 0.12], ['abaya_search', 'google / cpc', 0.08], ['new_arrivals', 'tiktok / cpc', 0.07]];

    /** name, share of units, unit price (SAR) */
    private const PRODUCTS = [['عباية سوداء كلاسيكية', 0.34, 420.0], ['عباية بتطريز ذهبي', 0.18, 690.0], ['طرحة حرير', 0.22, 180.0], ['حقيبة يد جلدية', 0.12, 540.0], ['حذاء كعب متوسط', 0.09, 380.0], ['عباية كتان صيفية', 0.05, 350.0]];

    public function run(): void
    {
        $tenant = Tenant::query()->where('slug', 'demo-agency')->first();
        $project = $tenant === null ? null : Project::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('name', 'متجر تجريبي — Demo')->first();
        if ($tenant === null || $project === null) {
            return;
        }

        $property = $this->property($tenant, $project);
        DemoAccountSelection::bind((string) $project->id, (string) $property->id, 'ga4', 'analytics');
        $this->days($tenant, $project, $property, $this->platformCampaigns($project));
        $this->rate();
    }

    private function property(Tenant $tenant, Project $project): ExternalAccount
    {
        $credential = IntegrationCredential::withoutGlobalScopes()->firstOrNew(['id' => $this->uuid('cred')]);
        if (! $credential->exists) {
            $credential->forceFill([
                'id' => $this->uuid('cred'), 'tenant_id' => $tenant->id, 'provider' => 'ga4',
                'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active',
            ]);
            // A labelled placeholder. Never a token shaped like a real one.
            $credential->setPayload('DEMO-PLACEHOLDER-NOT-A-REAL-TOKEN');
            $credential->save();
        }

        $connection = ProviderConnection::withoutGlobalScopes()->updateOrCreate(['id' => $this->uuid('conn')], [
            'tenant_id' => $tenant->id, 'credential_id' => $credential->id, 'provider' => 'ga4',
            'connection_name' => 'GA4 — بيانات تجريبية', 'scope' => 'project_only', 'status' => 'connected',
            'last_health_check_at' => Carbon::now()->subHours(3), 'last_successful_sync_at' => Carbon::now()->subHours(3),
        ]);

        return ExternalAccount::withoutGlobalScopes()->updateOrCreate(['id' => $this->uuid('acct')], [
            'tenant_id' => $tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'provider_connection_id' => $connection->id,
            'provider' => 'ga4', 'account_type' => Ga4PropertyDiscovery::ACCOUNT_TYPE, 'external_id' => '000000000',
            'name' => 'متجر العباءات — GA4 (تجريبي)', 'currency' => 'SAR', 'timezone' => 'Asia/Riyadh', 'status' => 'active',
            'metadata' => ['is_demo' => true], 'discovered_at' => Carbon::now()->subDays(self::DAYS), 'last_synced_at' => Carbon::now()->subHours(3),
        ]);
    }

    /**
     * GA4-ATTRIBUTION-MATCH-001 — the demo project's own platform campaigns, each with the way GA4 will be made to see it:
     * the first of a platform by its UTM name (`utm`), the second by its platform id (`id`), the third not at all
     * (`unmatched`), so every confidence chip and both buckets are visible locally. Clearly demo, like the rest.
     *
     * @return list<array{provider: string, external_id: string, name: string, mode: string, source: string, medium: string}>
     */
    private function platformCampaigns(Project $project): array
    {
        $by = [
            'snapchat' => ['snapchat', 'cpc'], 'meta' => ['facebook', 'paid_social'], 'tiktok' => ['tiktok', 'cpc'], 'google' => ['google', 'cpc'],
        ];
        $out = [];
        $seen = [];
        foreach (ExternalCampaign::withoutGlobalScopes()->where('project_id', $project->id)->orderBy('provider')->orderBy('name')->get(['provider', 'external_id', 'name']) as $c) {
            $p = (string) $c->provider;
            if (! isset($by[$p])) {
                continue;
            }
            $n = $seen[$p] = ($seen[$p] ?? -1) + 1;
            $out[] = ['provider' => $p, 'external_id' => (string) $c->external_id, 'name' => (string) $c->name, 'mode' => ['utm', 'id', 'unmatched'][min($n, 2)], 'source' => $by[$p][0], 'medium' => $by[$p][1]];
        }

        return $out;
    }

    /** @param  list<array{provider: string, external_id: string, name: string, mode: string, source: string, medium: string}>  $platformCampaigns */
    private function days(Tenant $tenant, Project $project, ExternalAccount $property, array $platformCampaigns = []): void
    {
        $daily = [];
        $dims = [];
        $now = Carbon::now();
        $base = ['tenant_id' => $tenant->id, 'project_id' => $project->id, 'external_account_id' => $property->id, 'property_id' => '000000000', 'timezone' => 'Asia/Riyadh', 'created_at' => $now, 'updated_at' => $now];

        for ($d = self::DAYS; $d >= 1; $d--) {
            $date = Carbon::today('Asia/Riyadh')->subDays($d)->toDateString();
            $wave = 1 + 0.25 * sin($d / 4.5) + ($d % 7 === 4 ? 0.2 : 0.0);
            $sessions = (int) round(1400 * $wave);
            $purchases = 0;
            $revenue = 0.0;

            foreach (self::SOURCES as [$source, $medium, $channel, $share, $rate]) {
                $s = (int) round($sessions * $share);
                $p = (int) round($s * $rate * (1 + 0.15 * cos($d / 3)));
                $r = round($p * 465.0, 2);
                $purchases += $p;
                $revenue += $r;
                $m = ['sessions' => $s, 'users' => (int) round($s * 0.82), 'engaged_sessions' => (int) round($s * 0.58), 'key_events' => $p, 'add_to_carts' => $p * 7, 'checkouts' => $p * 3, 'purchases' => $p, 'revenue' => $r];
                $dims[] = $this->dim($base, $date, 'source_medium', $source, $medium, $m);
                $dims[] = $this->dim($base, $date, 'first_user_source_medium', $source, $medium, $m);
                $dims[] = $this->dim($base, $date, 'channel_source', $channel, $source.' / '.$medium, $m);
            }
            $byChannel = [];
            foreach (self::SOURCES as [$source, $medium, $channel, $share, $rate]) {
                $s = (int) round($sessions * $share);
                $p = (int) round($s * $rate * (1 + 0.15 * cos($d / 3)));
                $c = $byChannel[$channel] ?? ['sessions' => 0, 'engaged_sessions' => 0, 'purchases' => 0, 'revenue' => 0.0];
                $byChannel[$channel] = ['sessions' => $c['sessions'] + $s, 'engaged_sessions' => $c['engaged_sessions'] + (int) round($s * 0.58), 'purchases' => $c['purchases'] + $p, 'revenue' => round($c['revenue'] + $p * 465.0, 2)];
            }
            foreach ($byChannel as $channel => $m) {
                $dims[] = $this->dim($base, $date, 'default_channel_group', $channel, '', $m);
            }
            foreach ([['device', self::DEVICES], ['country', self::COUNTRIES], ['landing_page', self::PAGES]] as [$breakdown, $split]) {
                foreach ($split as [$name, $share]) {
                    $p = (int) round($purchases * $share);
                    $dims[] = $this->dim($base, $date, $breakdown, $name, '', [
                        'sessions' => (int) round($sessions * $share), 'users' => (int) round($sessions * $share * 0.82), 'engaged_sessions' => (int) round($sessions * $share * 0.58),
                        'key_events' => $p, 'add_to_carts' => $p * 7, 'checkouts' => $p * 3, 'purchases' => $p, 'revenue' => round($revenue * $share, 2),
                    ]);
                }
            }
            foreach (self::CAMPAIGNS as [$campaign, $sourceMedium, $share]) {
                $p = (int) round($purchases * $share);
                $dims[] = $this->dim($base, $date, 'campaign', $campaign, $sourceMedium, ['sessions' => (int) round($sessions * $share), 'engaged_sessions' => (int) round($sessions * $share * 0.6), 'key_events' => $p, 'add_to_carts' => $p * 7, 'checkouts' => $p * 3, 'purchases' => $p, 'revenue' => round($revenue * $share, 2)]);
            }
            // GA4-ATTRIBUTION-MATCH-001 — the match's keys: campaign ids, click-id families folded with their UTMs, new vs returning.
            $family = ['snapchat' => 'snapchat', 'meta' => 'meta', 'tiktok' => 'tiktok', 'google' => 'google'];
            foreach ($platformCampaigns as $i => $c) {
                if ($c['mode'] === 'unmatched') {
                    continue;
                }
                $label = $c['mode'] === 'id' ? $c['external_id'] : strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $c['name']));
                $share = 0.05 + 0.01 * ($i % 3);
                $p = (int) round($purchases * $share);
                $m = ['sessions' => (int) round($sessions * $share), 'engaged_sessions' => (int) round($sessions * $share * 0.6), 'key_events' => $p, 'add_to_carts' => $p * 7, 'checkouts' => $p * 3, 'purchases' => $p, 'revenue' => round($revenue * $share, 2)];
                $dims[] = $this->dim($base, $date, 'campaign', $label, $c['source'].' / '.$c['medium'], $m);
                $dims[] = $this->dim($base, $date, 'campaign_id', $c['mode'] === 'id' ? $c['external_id'] : '(not set)', $label, $m);
                // Most, not all, of a campaign's sessions carry the platform's click id (demo click ids are never stored: only the family).
                $dims[] = $this->dim($base, $date, 'landing_query', 'family='.$family[$c['provider']].';utm_campaign='.$label.';utm_source='.$c['source'], '', ['sessions' => (int) round($m['sessions'] * 0.7)] + $m);
            }
            $dims[] = $this->dim($base, $date, 'landing_query', '', '', ['sessions' => (int) round($sessions * 0.25), 'purchases' => (int) round($purchases * 0.2), 'revenue' => round($revenue * 0.2, 2)]);
            $dims[] = $this->dim($base, $date, 'user_type', 'new', '', ['sessions' => (int) round($sessions * 0.62), 'purchases' => (int) round($purchases * 0.55), 'revenue' => round($revenue * 0.5, 2)]);
            $dims[] = $this->dim($base, $date, 'user_type', 'returning', '', ['sessions' => (int) round($sessions * 0.38), 'purchases' => (int) round($purchases * 0.45), 'revenue' => round($revenue * 0.5, 2)]);
            foreach ([['عبايات', 0.55], ['إكسسوارات', 0.3], ['أحذية', 0.15]] as [$cat, $share]) {
                $units = (int) round($purchases * 1.3 * $share);
                $dims[] = $this->dim($base, $date, 'product_category', $cat, '', ['items_viewed' => $units * 20, 'items_added_to_cart' => $units * 5, 'items_purchased' => $units, 'item_revenue' => round($units * 420, 2)]);
            }
            foreach (self::PRODUCTS as [$name, $share, $price]) {
                $units = (int) round($purchases * 1.3 * $share);
                $dims[] = $this->dim($base, $date, 'product', $name, '', ['items_viewed' => $units * 22, 'items_added_to_cart' => $units * 5, 'items_purchased' => $units, 'item_revenue' => round($units * $price, 2)]);
            }

            foreach ([
                'sessions' => $sessions, 'users' => (int) round($sessions * 0.82), 'new_users' => (int) round($sessions * 0.55), 'active_users' => (int) round($sessions * 0.8),
                'page_views' => $sessions * 4, 'engaged_sessions' => (int) round($sessions * 0.58), 'engagement_rate' => 0.58, 'bounce_rate' => round(0.42 + 0.04 * sin($d / 5), 4),
                'avg_session_seconds' => round(96 + 12 * cos($d / 6), 2), 'engagement_seconds' => $sessions * 61, 'key_events' => $purchases, 'transactions' => $purchases,
                'revenue' => round($revenue, 2), 'items_purchased' => (int) round($purchases * 1.3), 'product_views' => $sessions * 2, 'add_to_carts' => $purchases * 7, 'checkouts' => $purchases * 3,
            ] as $key => $value) {
                $daily[] = $base + ['id' => $this->uuid("d:$date:$key"), 'metric_date' => $date, 'metric_key' => $key, 'value' => $value, 'currency' => $key === 'revenue' ? 'SAR' : null];
            }
        }

        foreach (array_chunk($daily, 500) as $chunk) {
            DB::table('measurement_daily_metrics')->upsert($chunk, ['external_account_id', 'metric_date', 'metric_key'], ['value', 'currency', 'updated_at']);
        }
        foreach (array_chunk($dims, 500) as $chunk) {
            DB::table('measurement_dimension_rows')->upsert($chunk, ['external_account_id', 'metric_date', 'breakdown', 'dimension_1', 'dimension_2'], ['metrics', 'currency', 'updated_at']);
        }
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, float|int>  $metrics
     * @return array<string, mixed>
     */
    private function dim(array $base, string $date, string $breakdown, string $d1, string $d2, array $metrics): array
    {
        return $base + [
            'id' => $this->uuid("r:$date:$breakdown:$d1:$d2"), 'metric_date' => $date, 'breakdown' => $breakdown, 'dimension_1' => $d1, 'dimension_2' => $d2,
            'metrics' => json_encode($metrics, JSON_THROW_ON_ERROR), 'currency' => isset($metrics['revenue']) || isset($metrics['item_revenue']) ? 'SAR' : null,
        ];
    }

    /** The SAR peg the money rule already uses, only where no SAR→USD rate is stored yet. */
    private function rate(): void
    {
        $exists = DB::table('currency_rates')->where('base_currency', 'SAR')->where('quote_currency', 'USD')->exists()
            || DB::table('currency_rates')->where('base_currency', 'USD')->where('quote_currency', 'SAR')->exists();
        if (! $exists) {
            DB::table('currency_rates')->insert([
                'id' => $this->uuid('rate'), 'base_currency' => 'USD', 'quote_currency' => 'SAR', 'rate' => 3.75,
                'rate_date' => Carbon::today()->subDays(self::DAYS + 30)->toDateString(), 'source' => 'manual', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function uuid(string $key): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, self::NS.':'.$key);
    }
}
