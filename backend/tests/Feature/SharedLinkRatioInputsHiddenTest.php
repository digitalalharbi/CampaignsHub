<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLiveReportWorld;
use Tests\TestCase;

final class SharedLinkRatioInputsHiddenTest extends TestCase
{
    use BuildsLiveReportWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeWorld();
        $this->buildWorld();
    }

    protected function tearDown(): void
    {
        $this->thawWorld();
        parent::tearDown();
    }

    /**
     * A live link over the whole world that publishes its content, with these stored flags.
     *
     * @param  array<string, bool>  $flags
     */
    private function storedLink(array $flags): string
    {
        [$share, $raw] = $this->liveLink();
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        app(ProjectContext::class)->setProjectId((string) $this->project->id);
        $share = ReportShare::query()->findOrFail($share->id);
        $share->forceFill([
            'settings' => ['creatives' => ['creatives' => true, 'comparison' => true, 'insights' => true]] + (array) $share->settings,
        ] + $flags)->save();
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        return $raw;
    }

    /**
     * HIDDEN-MONEY-RATIO-INPUTS-001 — a content row's `ratio_inputs` is the hidden figure under another name.
     *
     * Every content row carries `ratio_inputs`, metric => [numerator, denominator]: the two numbers each ratio was divided from.
     * For `cpc`, `cpm`, `cpa`, `cpl` the numerator IS the spend, for `roas` the denominator is, and for `roas` and `aov` the
     * numerator is the revenue. A link that hid spend removed `spend` and `cpc` and published `"cpc": [2007.94, 3200]`.
     *
     * The guard above could not see it: the open link prints the same number under `ratio_inputs` too, a key it does not call
     * money, so the figure was set aside as «also stated under something the link does not hide»; and its world has no ads, so
     * no row there was ever filled from the ad grain and none carried the map at all. This one reads the structure, over
     * `BuildsLiveReportWorld` (both grains, three providers):
     * on every surface that carries content rows, no pair survives whose ratio is made of a hidden figure, and no number under
     * `ratio_inputs` equals a spend or revenue the open link states. The lists are written here, not read from the sanitiser.
     *
     * @return array<string, array<string, mixed>> surface => decoded payload
     */
    private function contentSurfaces(string $live): array
    {
        $get = fn (string $path): array => (array) $this->getJson("/api/v1/reports/shared/{$live}{$path}")->assertOk()->json('data');
        $out = ['/live' => $get('/live')];

        foreach (['meta', 'snapchat', 'tiktok'] as $provider) {
            $out["/live/platform/{$provider}"] = $get("/live/platform/{$provider}");
        }
        foreach (collect($out['/live']['ads_roster'] ?? [])->pluck('content_key')->filter()->take(3) as $key) {
            $out["/live/content/{$key}"] = $get("/live/content/{$key}");
        }

        $out['/creatives'] = $get('/creatives');
        $out['/creatives/summary'] = $get('/creatives/summary');
        $ids = collect($out['/creatives']['creatives'] ?? [])->pluck('id')->filter()->take(2)->values()->all();
        $this->assertCount(2, $ids, 'the link lists no content, so its content endpoints prove nothing');
        $out['/creatives/{id}'] = $get("/creatives/{$ids[0]}");
        $out['/creatives/comparison'] = $get('/creatives/comparison?'.http_build_query(['creative_ids' => $ids]));

        return $out;
    }

    /**
     * Every `ratio_inputs` map below a node, by where it sits.
     *
     * @param  array<string, array<string, mixed>>  $found
     */
    private function ratioInputMaps(mixed $node, string $path, array &$found): void
    {
        if (! is_array($node)) {
            return;
        }
        foreach ($node as $key => $value) {
            if ($key === 'ratio_inputs' && is_array($value)) {
                $found["{$path}.ratio_inputs"] = $value;

                continue;
            }
            $this->ratioInputMaps($value, "{$path}.{$key}", $found);
        }
    }

    /** Every number stated under one of these keys, at any depth. @param list<string> $keys @param array<string, float> $out */
    private function statedUnder(mixed $node, array $keys, array &$out): void
    {
        if (! is_array($node)) {
            return;
        }
        foreach ($node as $key => $value) {
            if (is_string($key) && in_array($key, $keys, true) && is_numeric($value) && abs((float) $value) >= 1) {
                $out[(string) round((float) $value, 2)] = (float) $value;
            }
            $this->statedUnder($value, $keys, $out);
        }
    }

    /**
     * Every number stated under a key that is neither one of these nor inside a `ratio_inputs` map.
     *
     * @param  list<string>  $hidden
     * @return array<string, true>
     */
    private function statedElsewhere(mixed $node, array $hidden, bool $below = false): array
    {
        if (! is_array($node)) {
            return $below && is_numeric($node) ? [(string) round((float) $node, 2) => true] : [];
        }
        $out = [];
        foreach ($node as $key => $value) {
            if ($key === 'ratio_inputs' || (is_string($key) && in_array($key, $hidden, true))) {
                continue;
            }
            $out += $this->statedElsewhere($value, $hidden, true);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $flags
     * @param  list<string>  $ratios  the ratios made of the hidden figure
     * @param  list<string>  $amounts  the keys the hidden figure itself is stated under
     * @param  list<string>  $kept  ratios made of nothing hidden, which the link still carries
     */
    private function assertRatioInputsHide(array $flags, array $ratios, array $amounts, array $kept): void
    {
        $open = $this->contentSurfaces($this->storedLink([]));
        $stated = [];
        $this->statedUnder($open, $amounts, $stated);
        // A count that happens to equal an amount (40 conversions, 40 riyals) states nothing hidden: set aside what the open link
        // also prints under a figure this link keeps. `ratio_inputs` itself is not such a figure — that reading is the blind spot.
        $stated = array_diff_key($stated, $this->statedElsewhere($open, [...$amounts, ...$ratios]));
        $this->assertGreaterThan(2, count($stated), 'the open link states almost no such figure, so the hidden link proves nothing');

        $leaks = [];
        $carried = [];
        foreach ($this->contentSurfaces($this->storedLink($flags)) as $surface => $payload) {
            $maps = [];
            $this->ratioInputMaps($payload, $surface, $maps);
            foreach ($maps as $path => $map) {
                foreach ($map as $metric => $pair) {
                    $carried[$surface][(string) $metric] = true;
                    if (in_array($metric, $ratios, true)) {
                        $leaks[] = "{$path}.{$metric} = ".json_encode($pair);

                        continue;
                    }
                    foreach ((array) $pair as $side) {
                        if (is_numeric($side) && isset($stated[(string) round((float) $side, 2)])) {
                            $leaks[] = "{$path}.{$metric} = ".json_encode($pair).' states a hidden amount';
                        }
                    }
                }
            }
        }

        $this->assertSame([], $leaks, 'a hidden figure reached a client under ratio_inputs');

        // …and the link was narrowed, not emptied: the surfaces whose rows carry the map still carry the ratios made of nothing hidden.
        foreach (['/live', '/creatives'] as $surface) {
            foreach ($kept as $metric) {
                $this->assertArrayHasKey($metric, $carried[$surface] ?? [], "«{$surface}» lost the inputs of {$metric}, which is not money");
            }
        }
    }

    public function test_a_link_hiding_spend_states_no_spend_under_ratio_inputs_on_any_content_surface(): void
    {
        $this->assertRatioInputsHide(
            ['hide_spend' => true],
            ['cpc', 'cpm', 'cpa', 'cpl', 'cpi', 'cpe', 'cost_per_view', 'cost_per_lpv', 'roas'],
            ['spend', 'spend_original'],
            ['ctr', 'conversion_rate'],
        );
    }

    public function test_a_link_hiding_revenue_states_no_revenue_under_ratio_inputs_on_any_content_surface(): void
    {
        $this->assertRatioInputsHide(
            ['hide_revenue' => true],
            ['roas', 'aov'],
            ['revenue', 'revenue_original'],
            ['ctr', 'conversion_rate'],
        );
    }

    /**
     * The content endpoints are narrowed by the link's CONTENT permissions, which show no money unless the operator ticked it.
     * A link that hides nothing at the link level and never ticked «spend» for its content removed `metrics.spend` from every
     * row there, and left the same pairs.
     */
    public function test_content_permissions_that_show_no_money_state_none_under_ratio_inputs_on_the_content_endpoints(): void
    {
        $leaks = [];
        $kept = [];
        foreach ($this->contentSurfaces($this->storedLink([])) as $surface => $payload) {
            if (! str_starts_with($surface, '/creatives')) {
                continue;
            }
            $this->assertStringNotContainsString('"spend"', (string) json_encode($payload['creatives'][0]['metrics'] ?? []), 'this link shows content spend, so it is not the link this test is about');
            $maps = [];
            $this->ratioInputMaps($payload, $surface, $maps);
            foreach ($maps as $path => $map) {
                foreach ($map as $metric => $pair) {
                    $kept[(string) $metric] = true;
                    if (in_array($metric, ['cpc', 'cpm', 'cpa', 'cpl', 'cpi', 'cpe', 'cost_per_view', 'cost_per_lpv', 'roas', 'aov'], true)) {
                        $leaks[] = "{$path}.{$metric} = ".json_encode($pair);
                    }
                }
            }
        }

        $this->assertSame([], $leaks, 'a figure the content permissions withhold reached a client under ratio_inputs');
        $this->assertArrayHasKey('ctr', $kept, 'the content endpoints lost the inputs of ctr, which is not money');
    }
}
