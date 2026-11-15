<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Metrics\Fx\CurrencyCoverage;
use App\Domains\Metrics\Fx\FxEngine;
use App\Domains\Metrics\Fx\FxMonitor;
use App\Domains\Metrics\Fx\FxPolicy;
use App\Domains\Metrics\Fx\FxRecompute;
use App\Domains\Metrics\Fx\MoneyGuard;
use App\Domains\Metrics\Fx\RateLedger;
use App\Domains\Metrics\Models\CurrencyRate;
use App\Domains\Metrics\Rates\CurrencyRateFeed;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * FX-FEED-001 — the platform operator's view of where rates come from, and the way to enter one.
 *
 * ## Why this is on the platform console and not in a tenant's settings
 *
 * A rate is not one customer's opinion. The same USD→SAR quote converts every tenant's spend on the
 * same day, and a tenant able to set it could move their own reported ROAS by editing a number. It
 * belongs to whoever runs the deployment, next to the other supply-side settings.
 *
 * ## Entering a rate by hand is a first-class path, not a fallback
 *
 * An operator IS a legitimate source — a treasury desk publishes rates on paper long before anybody
 * buys an API. What matters is that the figure is ATTRIBUTABLE, so a hand-entered rate is stamped
 * `manual:<email>` and audited. A conversion made at it can then be traced to a person and a moment,
 * which is the whole point of storing `rate_source` rather than just a number.
 *
 * ## FX-ENGINE-001 — every rate is managed here
 *
 * The page /admin/settings/currency-rates reads ONE payload (`index`): the feed, the currencies actually used by connected
 * ad accounts with their status, the live rates (kind, version, note, who), the policy (display currency USD/SAR, the free
 * automatic source — off by default), the per-workspace display choice and the open FX alerts. Writes go through
 * RateLedger (versioned, audited). `preview` answers «253 EUR on 2026-11-10 = ? USD, at which rate»; `recompute`
 * re-converts stored figures from their originals for a bounded period (dry run first).
 */
final class PlatformCurrencyRateController extends Controller
{
    public function __construct(private readonly CurrencyRateFeed $feed) {}

    /** GET /admin/fx/rates — everything the rates page shows, in one read. */
    public function index(CurrencyCoverage $coverage, FxPolicy $policy): JsonResponse
    {
        $rates = CurrencyRate::query()
            ->orderByDesc('rate_date')
            ->orderBy('base_currency')
            ->limit(200)
            ->get()
            ->map(static fn (CurrencyRate $r): array => self::present($r))
            ->all();

        return ApiResponse::success([
            'feed' => $this->feed->status(),
            /*
             * The conversions this deployment has already failed to make — the figure that makes a missing rate concrete.
             */
            'unmet_pairs' => $this->feed->unmetPairs(),
            'rates' => $rates,
            'currencies' => $coverage->rows(),
            'policy' => $policy->current(),
            'workspaces' => $policy->workspaces(),
        ] + $this->safetyNet(), 'Currency rates.');
    }

    /** POST /admin/fx/rates — record a rate by hand (or a peg). An existing pair/day becomes a NEW version. */
    public function store(Request $request, RateLedger $ledger): JsonResponse
    {
        $data = $request->validate([
            'base' => ['required', 'string', 'size:3', 'alpha'],
            'quote' => ['required', 'string', 'size:3', 'alpha'],
            // Strictly positive: a zero rate converts a real amount into «earned nothing».
            'rate' => ['required', 'numeric', 'gt:0'],
            // A rate cannot be published for a day that has not happened: the lookup takes the nearest earlier rate, so a
            // future-dated typo would silently become tomorrow's answer.
            'rate_date' => ['required', 'date', 'before_or_equal:today'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:rate_date'],
            'note' => ['nullable', 'string', 'max:500'],
            'kind' => ['nullable', Rule::in([CurrencyRate::MANUAL, CurrencyRate::PEGGED])],
        ]);

        $base = strtoupper($data['base']);
        $quote = strtoupper($data['quote']);

        if ($base === $quote) {
            return ApiResponse::error(
                'A currency is already at par with itself; no rate is needed.',
                errors: ['quote' => ['Same as base.']],
                status: 422,
            );
        }

        $kind = $data['kind'] ?? CurrencyRate::MANUAL;
        $result = $ledger->record(
            base: $base,
            quote: $quote,
            rate: (float) $data['rate'],
            date: Carbon::parse($data['rate_date']),
            kind: $kind,
            source: ($kind === CurrencyRate::PEGGED ? 'pegged:' : 'manual:').$request->user()->email,
            effectiveTo: $data['effective_to'] ?? null,
            note: $data['note'] ?? null,
            by: $request->user(),
        );

        return ApiResponse::success(self::present($result['rate']) + ['outcome' => $result['outcome']], 'Rate recorded.', status: 201);
    }

    /** GET /admin/fx/rates/history?base=EUR&quote=USD — every version of a pair, newest first. */
    public function history(Request $request, RateLedger $ledger): JsonResponse
    {
        $data = $request->validate([
            'base' => ['required', 'string', 'size:3', 'alpha'],
            'quote' => ['required', 'string', 'size:3', 'alpha'],
        ]);

        return ApiResponse::success(['versions' => $ledger->history($data['base'], $data['quote'])], 'Rate history.');
    }

    /** POST /admin/fx/preview — «253 EUR on 2026-11-10 = ? USD», and which rate was used. Writes nothing. */
    public function preview(Request $request, FxEngine $fx): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0', 'max:1000000000000'],
            'from' => ['required', 'string', 'size:3', 'alpha'],
            'to' => ['required', 'string', 'size:3', 'alpha'],
            'date' => ['required', 'date'],
        ]);

        $c = $fx->convert((float) $data['amount'], strtoupper($data['from']), strtoupper($data['to']), Carbon::parse($data['date']));
        $out = $c->toArray();
        $out['converted_amount'] = $c->converted === null ? null : round($c->converted, 2);

        return ApiResponse::success($out, 'Preview.');
    }

    /** POST /admin/fx/recompute — re-convert stored figures for a period from their originals. Dry run unless `apply`. */
    public function recompute(Request $request, FxRecompute $recompute, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'apply' => ['sometimes', 'boolean'],
        ]);

        try {
            $summary = $recompute->run(Carbon::parse($data['from']), Carbon::parse($data['to']), (bool) ($data['apply'] ?? false));
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), errors: ['from' => [$e->getMessage()]], status: 422);
        }

        if ($summary['applied']) {
            $audit->log('fx.recompute.applied', 'currency_rates', null, after: [
                'from' => $summary['from'], 'to' => $summary['to'], 'changed' => $summary['changed'],
                'released' => $summary['released'], 'withheld' => $summary['withheld'], 'budgets_refreshed' => $summary['budgets_refreshed'],
            ], userId: $request->user()?->id);
        }

        return ApiResponse::success($summary, $summary['applied'] ? 'Recomputed.' : 'Dry run — nothing written.');
    }

    /** PUT /admin/fx/policy — platform display currency (USD|SAR) and the free automatic source switch. */
    public function policy(Request $request, FxPolicy $policy): JsonResponse
    {
        $data = $request->validate([
            'display_currency' => ['sometimes', Rule::in(FxPolicy::DISPLAY)],
            'auto_source_enabled' => ['sometimes', 'boolean'],
        ]);

        return ApiResponse::success($policy->update($data, $request->user()), 'Policy saved.');
    }

    /** PUT /admin/fx/workspaces/{tenant}/display-currency — a workspace's own display currency, or null for the default. */
    public function workspaceDisplay(Request $request, string $tenant, FxPolicy $policy): JsonResponse
    {
        $data = $request->validate(['display_currency' => ['present', 'nullable', Rule::in(FxPolicy::DISPLAY)]]);
        $model = Tenant::query()->findOrFail($tenant);
        $policy->setWorkspaceDisplay($model, $data['display_currency'], $request->user());

        return ApiResponse::success([
            'id' => (string) $model->id, 'name' => (string) $model->name,
            'display_currency' => $data['display_currency'], 'effective' => $policy->displayCurrencyFor((string) $model->id),
        ], 'Workspace display currency saved.');
    }

    /**
     * The safety-net block of the page: the open FX alerts (no rate / stale) and the last daily guard run.
     *
     * @return array{alerts: list<array<string, mixed>>, guard: array<string, mixed>|null}
     */
    private function safetyNet(): array
    {
        return ['alerts' => app(FxMonitor::class)->open(), 'guard' => MoneyGuard::lastRun()];
    }

    /** @return array<string, mixed> */
    private static function present(CurrencyRate $r): array
    {
        return [
            'base' => $r->base_currency,
            'quote' => $r->quote_currency,
            'rate' => (float) $r->rate,
            'rate_date' => Carbon::parse((string) $r->rate_date)->toDateString(),
            'effective_to' => $r->effective_to === null ? null : Carbon::parse((string) $r->effective_to)->toDateString(),
            'source' => $r->source,
            'kind' => (string) ($r->kind ?? CurrencyRate::kindOf($r->source)),
            'note' => $r->note,
            'recorded_by' => $r->recorded_by_email,
            'version' => (int) ($r->version ?? 1),
        ];
    }
}
