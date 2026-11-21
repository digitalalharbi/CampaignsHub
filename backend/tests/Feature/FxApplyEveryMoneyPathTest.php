<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Agents\Models\ActionProposal;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsFxApplyWorld;
use Tests\TestCase;

/**
 * FX-APPLY-001 (builder G) — the Owner's key acceptance: with a EUR ad account (253 EUR spent, budget 253 EUR, an ad group's
 * daily budget 253, an approval request for a 253 EUR daily budget, EUR→USD 1.12 recorded), walk EVERY financial API response
 * and FAIL if 253 appears with «$» or any currency other than its own without having passed through convert().
 *
 * REGISTRY-BASED: the money routes are enumerated from the router, not typed from memory. Every GET route under the money
 * prefixes must be either WALKED here or named in NOT_WALKED with the reason. A new financial endpoint fails this test until
 * somebody walks it or says why it holds no money.
 *
 * The walk rule, on every JSON object in a response:
 *   - an object stamped `via: fx.convert` is a conversion: a EUR→USD conversion of 253 must read 283.36, never 253;
 *   - any other object holding the number 253 must not name a currency other than EUR beside it (`currency`, `*_currency`);
 *   - no string may print 253 with «$», «ر.س», «USD» or «SAR».
 */
final class FxApplyEveryMoneyPathTest extends TestCase
{
    use BuildsFxApplyWorld;
    use RefreshDatabase;

    /** The prefixes whose GET routes are money surfaces (the registry's scope). */
    private const MONEY_ROUTES = '#^api/v1/(projects/\{project\}/(metrics|campaigns|automation|agent-proposals|approved-budget|spend-limits|external-campaigns|campaign-activity)|agency/(overview|client-budgets)|me/dashboard|portfolio/overview|funding|app/clients(/\{client\}(/analytics)?)?$|client/(overview|campaigns))#';

    /** Routes in scope that this walk does not call, each with the reason. Adding one is a visible decision in review. */
    private const NOT_WALKED = [
        'api/v1/client/overview' => 'client portal (another portal and login); client reports carry no spend by the permanent client-report rule',
        'api/v1/client/campaigns' => 'client portal (another portal and login); no spend or budget on client surfaces',
        'api/v1/funding/disclaimer' => 'static wording, no figure',
        'api/v1/funding/rate' => 'the platform USD→SAR rate itself (a rate, not an amount) — FxAdminApiTest',
        'api/v1/funding/export' => 'SAR ledger export with its own currency, amount_sar, rate and rate_date columns — FundingExport tests',
        'api/v1/funding/renew-next-month' => 'a renewal proposal over SAR budgets; no platform-currency amount — funding tests',
        'api/v1/funding/projects/{funded}/months/{month}' => 'one month of the SAR ledger; same rows as funding/projects/{funded}',
        'api/v1/funding/projects/{funded}' => 'one approved budget (SAR ledger, each movement in its own currency with amount_sar and rate) — its spend goes through BudgetFx → FxEngine (FundingSpendRefresher); the list `funding` is walked',
        'api/v1/projects/{project}/automation/catalog' => 'metric and operator vocabulary, no figure',
        'api/v1/projects/{project}/automation/metric-support' => 'which metrics a platform reports, no figure',
        'api/v1/projects/{project}/automation/change-log' => 'history of rule edits; the figures are the rule limits stated in the rule currency',
        'api/v1/projects/{project}/automation/actions' => 'executed actions log; budget before/after are the platform figures in their own currency (labelled)',
        'api/v1/projects/{project}/automation/rules/{rule}/runs' => 'run history; readings are covered by the dry run in FxApplyCasesTest',
        'api/v1/projects/{project}/campaigns/{campaign}/annotations' => 'notes, no figure',
        'api/v1/projects/{project}/campaigns/{campaign}/sync-log' => 'sync runs, no figure',
        'api/v1/projects/{project}/campaigns/{campaign}/provider-writes' => 'audit of platform writes; values are the platform figures with their own currency',
        'api/v1/projects/{project}/campaigns/{campaign}/suggestions' => 'recommendation text from the same converted metrics',
        'api/v1/projects/{project}/campaigns/{campaign}/reports' => 'list of reports, no figure',
        'api/v1/projects/{project}/metrics/freshness' => 'sync times, no figure',
        'api/v1/projects/{project}/metrics/period' => 'the period itself, no figure',
        'api/v1/projects/{project}/metrics/period-coverage' => 'which days reported, no figure',
        'api/v1/projects/{project}/metrics/normalization' => 'catalogue of metric definitions, no figure',
        'api/v1/projects/{project}/metrics/campaign-options' => 'campaign names for a selector, no figure',
    ];

    private string $campaignId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFxApplyWorld();
        $this->rate('EUR', 'USD', 1.12, '2026-11-09');
        $eur = $this->adAccount('EUR');
        [$unified, $external] = $this->campaign($eur, 'Search EU', 253.0, 253.0);
        [, $second] = $this->campaign($eur, 'Display EU', 253.0, 253.0);
        $this->campaignId = (string) $unified->id;
        $this->adSet($external, 253.0);
        foreach ([$external, $second] as $c) {
            $this->moneyRow($eur, $c, 253.0, '2026-11-09');
            $this->countRow($eur, $c, 'clicks', 100, '2026-11-09');
            $this->countRow($eur, $c, 'impressions', 1000, '2026-11-09');
        }
        ActionProposal::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'agent_key' => 'budget', 'provider' => 'google',
            'external_account_id' => $eur->id, 'target_type' => 'campaign', 'target_external_id' => $external->external_id, 'action' => 'set_daily_budget',
            'payload' => ['daily_budget' => 253], 'currency' => 'EUR', 'reason' => 'test', 'evidence' => ['current_daily_budget' => 253], 'status' => 'pending',
            'idempotency_key' => 'fx-apply-1',
        ]);
    }

    /** @return array<string, string> route uri => concrete url */
    private function walked(): array
    {
        $p = (string) $this->project->id;
        $c = $this->campaignId;
        $w = http_build_query($this->window());
        $paths = [
            "projects/{$p}/campaigns", "projects/{$p}/campaigns/{$c}", "projects/{$p}/campaigns/{$c}/structure", "projects/{$p}/campaigns/{$c}/summary?{$w}",
            "projects/{$p}/campaigns/{$c}/platforms?{$w}", "projects/{$p}/campaigns/{$c}/performance?{$w}", "projects/{$p}/campaigns/{$c}/budget?{$w}",
            "projects/{$p}/campaigns/{$c}/creatives?{$w}", "projects/{$p}/campaigns/{$c}/events?{$w}", "projects/{$p}/campaigns/{$c}/funnel?{$w}",
            "projects/{$p}/campaigns/{$c}/external", "projects/{$p}/campaigns/{$c}/related", "projects/{$p}/campaigns/{$c}/activity", "projects/{$p}/campaigns/{$c}/alerts",
            "projects/{$p}/external-campaigns", "projects/{$p}/campaign-activity",
            "projects/{$p}/metrics/summary?{$w}", "projects/{$p}/metrics/campaigns?{$w}", "projects/{$p}/metrics/platforms?{$w}", "projects/{$p}/metrics/accounts?{$w}", "projects/{$p}/metrics/accounts/pulse?{$w}",
            "projects/{$p}/metrics/timeseries?{$w}", "projects/{$p}/metrics/budget?{$w}", "projects/{$p}/metrics/budget-accounts?{$w}", "projects/{$p}/metrics/budget-platforms?{$w}",
            "projects/{$p}/metrics/budget-explanation?{$w}", "projects/{$p}/metrics/funnel?{$w}", "projects/{$p}/metrics/drivers?{$w}", "projects/{$p}/metrics/attribution?{$w}",
            "projects/{$p}/metrics/entities/ad_set?{$w}", "projects/{$p}/metrics/objective-performance?{$w}", "projects/{$p}/metrics/objective-paths?{$w}",
            "projects/{$p}/metrics/objective-leaders?{$w}", "projects/{$p}/metrics/objective-trend?{$w}", "projects/{$p}/metrics/objective-explanations?{$w}",
            "projects/{$p}/metrics/platform-objectives?{$w}", "projects/{$p}/metrics/results?{$w}",
            "projects/{$p}/metrics/compare?{$w}&".http_build_query(['campaign_ids' => [$c, $this->secondCampaign()]]),
            "projects/{$p}/agent-proposals", "projects/{$p}/approved-budget", "projects/{$p}/spend-limits",
            "projects/{$p}/automation/rules", "projects/{$p}/automation/summary", "projects/{$p}/automation/targets",
            'agency/overview', 'agency/client-budgets', 'me/dashboard', 'me/dashboard/performance', 'me/dashboard/watch', 'portfolio/overview', 'funding',
            'app/clients', "app/clients/{$this->client->id}", "app/clients/{$this->client->id}/analytics?{$w}",
        ];
        $out = [];
        foreach ($paths as $path) {
            $out[$this->routeOf($path)] = '/api/v1/'.$path;
        }

        return $out;
    }

    private function secondCampaign(): string
    {
        return (string) UnifiedCampaign::withoutGlobalScopes()->where('project_id', $this->project->id)->where('id', '<>', $this->campaignId)->value('id');
    }

    /** The route template a concrete path belongs to. */
    private function routeOf(string $path): string
    {
        $uri = 'api/v1/'.explode('?', $path)[0];
        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            if (in_array('GET', $route->methods(), true) && preg_match('#^'.preg_replace('#\\\\\{[^}]+\\\\\}#', '[^/]+', preg_quote($route->uri(), '#')).'$#', $uri)) {
                return $route->uri();
            }
        }
        $this->fail("No GET route serves {$uri}");
    }

    public function test_every_money_route_is_walked_or_says_why_not(): void
    {
        $inScope = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $r) => in_array('GET', $r->methods(), true) && preg_match(self::MONEY_ROUTES, $r->uri()) === 1)
            ->map(fn (Route $r) => $r->uri())->unique()->values()->all();
        $walked = array_keys($this->walked());

        $unclassified = array_values(array_diff($inScope, $walked, array_keys(self::NOT_WALKED)));
        $this->assertSame([], $unclassified, 'a money route nobody walks: add it to walked() or to NOT_WALKED with the reason');
        $this->assertSame([], array_values(array_diff(array_keys(self::NOT_WALKED), $inScope)), 'a NOT_WALKED route that no longer exists: remove it');
        $this->assertSame([], array_values(array_intersect($walked, array_keys(self::NOT_WALKED))), 'a route is both walked and excused');
    }

    public function test_no_money_response_shows_253_with_another_currency_without_convert(): void
    {
        $offences = [];
        $converted = 0;
        foreach ($this->walked() as $route => $url) {
            $res = $this->actingAs($this->owner)->getJson($url);
            $this->assertSame(200, $res->status(), "{$route} answered {$res->status()}: ".mb_substr((string) $res->getContent(), 0, 300));
            $this->walk($this->body($res), $route, '$', $offences, $converted);
        }

        $this->assertSame([], $offences, '253 shown with another currency without passing through convert()');
        $this->assertGreaterThan(0, $converted, 'the walk met at least one conversion (the budgets)');
    }

    public function test_the_walk_rule_catches_the_defect_shapes(): void
    {
        $offences = [];
        $n = 0;
        $this->walk(['spend' => 253, 'currency' => 'USD'], 'sample', '$', $offences, $n);
        $this->walk(['total_budget' => 253.0, 'budget_currency' => 'SAR'], 'sample', '$', $offences, $n);
        $this->walk(['label' => 'الإنفاق 253 $'], 'sample', '$', $offences, $n);
        $this->walk(['via' => 'fx.convert', 'original_amount' => 253, 'original_currency' => 'EUR', 'currency' => 'USD', 'converted_amount' => 253.0, 'withheld' => false], 'sample', '$', $offences, $n);
        $this->assertCount(4, $offences);

        $ok = [];
        $this->walk(['total_budget' => 253, 'budget_currency' => 'EUR', 'budget_fx' => ['via' => 'fx.convert', 'original_amount' => 253, 'original_currency' => 'EUR', 'currency' => 'USD', 'converted_amount' => 283.36, 'withheld' => false]], 'sample', '$', $ok, $n);
        $this->walk(['spend' => 283.36, 'currency' => 'USD', 'spend_original' => 253, 'money_original_currency' => 'EUR'], 'sample', '$', $ok, $n);
        $this->assertSame([], $ok);
    }

    /** @return array<mixed> */
    private function body(TestResponse $res): array
    {
        $json = json_decode((string) $res->getContent(), true);

        return is_array($json) ? $json : ['__text' => (string) $res->getContent()];
    }

    /**
     * @param  list<string>  $offences
     */
    private function walk(mixed $node, string $route, string $path, array &$offences, int &$converted): void
    {
        if (is_string($node)) {
            if (preg_match('/(?<![\d.,])253(?:[.,]0+)?\s?(?:\$|ر\.س|USD|SAR)|(?:\$|USD|SAR)\s?253(?![\d.,])/u', $node) === 1) {
                $offences[] = "{$route} {$path}: «".mb_substr($node, 0, 80).'»';
            }

            return;
        }
        if (! is_array($node)) {
            return;
        }

        if (($node['via'] ?? null) === 'fx.convert' && array_key_exists('converted_amount', $node)) {
            $converted++;
            $from = strtoupper((string) ($node['original_currency'] ?? ''));
            $to = strtoupper((string) ($node['currency'] ?? ''));
            $orig = (float) ($node['original_amount'] ?? 0);
            $amount = $node['converted_amount'];
            if ($from !== $to && abs($orig - 253) < 0.005 && $amount !== null && abs((float) $amount - 253) < 0.005) {
                $offences[] = "{$route} {$path}: 253 {$from} «converted» to 253 {$to}";
            } elseif ($from === 'EUR' && $to === 'USD' && abs($orig - 253) < 0.005 && $amount !== null && abs((float) $amount - 283.36) > 0.01) {
                $offences[] = "{$route} {$path}: 253 EUR → {$amount} USD (expected 283.36)";
            }

            return;
        }

        // A 253 is judged against ITS OWN currency key: `budget` → `budget_currency`, `spent` → `spent_currency`, else `currency`.
        foreach ($node as $k => $v) {
            if (! is_string($k) || str_contains($k, 'original') || $k === 'source_amount' || ! (is_int($v) || is_float($v)) || abs((float) $v - 253) >= 0.005) {
                continue;
            }
            $stem = preg_replace('/_(amount|total|value)$/', '', $k);
            $own = null;
            $base = preg_replace('/^(total|daily|lifetime|current)_/', '', (string) $stem);
            foreach (["{$k}_currency", "{$stem}_currency", "{$base}_currency", 'currency'] as $ck) {
                if (array_key_exists($ck, $node) && is_string($node[$ck]) && $node[$ck] !== '') {
                    $own = [$ck, $node[$ck]];
                    break;
                }
            }
            if ($own !== null && strtoupper($own[1]) !== 'EUR') {
                $offences[] = "{$route} {$path}.{$k}: 253 beside {$own[0]}={$own[1]}";
            }
        }

        foreach ($node as $k => $v) {
            $this->walk($v, $route, $path.'.'.$k, $offences, $converted);
        }
    }
}
