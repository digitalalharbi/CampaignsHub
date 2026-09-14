<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Http\Controllers;

use App\Domains\Metrics\Enums\SpendLimitScope;
use App\Domains\Metrics\Models\SpendLimit;
use App\Domains\Metrics\Services\SpendLimitGovernor;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * The workspace's own spend limits — BUDGET-GOVERNANCE-001.
 *
 * Every response carries `enforcement: internal_monitoring` beside the figures, and the envelope
 * repeats it once in `meta` for a reader who takes the list rather than a row. CampaignsHub cannot
 * stop an ad platform from spending; a surface that let somebody believe otherwise would be worse
 * than having no limits at all, because they would stop checking.
 */
final class SpendLimitController extends Controller
{
    /**
     * Writing a limit is a budget decision, so it takes the budget permission this product already
     * has — `campaigns.budget.change`. A new permission would have to be granted to every existing
     * role before anybody could use the feature, and «who may decide what we are allowed to spend»
     * is the same question that permission was created to answer. Reading takes `campaigns.view`,
     * like every other spend figure.
     */
    private const MANAGE = 'campaigns.budget.change';

    public function __construct(private readonly SpendLimitGovernor $governor) {}

    /** Every limit on this project, read against today. */
    public function index(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);

        $today = Carbon::today();

        $limits = SpendLimit::query()
            ->where('project_id', $project)
            ->where('active', true)
            ->orderBy('scope')
            ->orderBy('starts_on')
            ->get()
            ->map(fn (SpendLimit $l): array => $this->governor->read($l, $today))
            ->all();

        return ApiResponse::success($limits, 'Spend limits.', [
            'enforcement' => SpendLimit::ENFORCEMENT,
            /*
             * Said in words, once, for the reader who takes `meta` and not a row. It is not a
             * translation key: a client copying this payload into a report should carry the sentence
             * with it, and the two locales are what this product renders.
             */
            'enforcement_note_en' => 'CampaignsHub watches spend against this limit and warns. It does not stop delivery on any ad platform.',
            'enforcement_note_ar' => 'يراقب CampaignsHub الإنفاق مقابل هذا الحد ويُنبّه — ولا يوقف عرض الإعلانات على أي منصة.',
            'today' => $today->toDateString(),
        ]);
    }

    public function store(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission(self::MANAGE), 403);

        $data = $this->validated($request);

        $limit = SpendLimit::create([
            'tenant_id' => app(TenantContext::class)->tenantId(),
            'project_id' => $project,
            'scope' => $data['scope'],
            'scope_id' => $data['scope_id'] ?? null,
            'amount' => $data['amount'],
            'currency' => strtoupper((string) $data['currency']),
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'thresholds' => $data['thresholds'] ?? null,
            'active' => true,
            'created_by' => Auth::id(),
        ]);

        return ApiResponse::success(
            $this->governor->read($limit, Carbon::today()),
            'Spend limit created.',
            ['enforcement' => SpendLimit::ENFORCEMENT],
            status: 201,
        );
    }

    public function update(Request $request, string $project, SpendLimit $spendLimit): JsonResponse
    {
        abort_unless($request->user()?->hasPermission(self::MANAGE), 403);
        abort_unless($spendLimit->project_id === $project, 404);

        $spendLimit->update($this->validated($request, partial: true));

        return ApiResponse::success($this->governor->read($spendLimit->refresh(), Carbon::today()), 'Spend limit updated.');
    }

    /**
     * Deactivate rather than delete.
     *
     * The events written against a limit are an audit trail, and a trail whose subject can vanish is
     * not one. `active: false` takes it off the operational surface and leaves last quarter's limit
     * beside what was actually spent against it, which is the history half of this requirement.
     */
    public function destroy(Request $request, string $project, SpendLimit $spendLimit): JsonResponse
    {
        abort_unless($request->user()?->hasPermission(self::MANAGE), 403);
        abort_unless($spendLimit->project_id === $project, 404);

        $spendLimit->update(['active' => false]);

        return ApiResponse::success(null, 'Spend limit deactivated.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'scope' => [$required, Rule::in(array_column(SpendLimitScope::cases(), 'value'))],
            'scope_id' => ['nullable', 'string', 'max:191'],
            'amount' => [$required, 'numeric', 'min:0.01'],
            'currency' => [$required, 'string', 'size:3'],
            'starts_on' => [$required, 'date'],
            'ends_on' => [$required, 'date', 'after_or_equal:starts_on'],
            'thresholds' => ['nullable', 'array', 'max:6'],
            'thresholds.*' => ['integer', 'min:1', 'max:100'],
            'active' => ['sometimes', 'boolean'],
        ]);

        /*
         * A scope that needs an identifier and was not given one would silently become «the whole
         * project»: a 4,000 TikTok cap measured against every platform's spend, reading «over» on the
         * first day and teaching its owner to ignore it.
         */
        $scope = isset($data['scope']) ? SpendLimitScope::from((string) $data['scope']) : null;

        if ($scope?->needsIdentifier() && ($data['scope_id'] ?? null) === null) {
            abort(422, "A {$scope->value} limit needs the {$scope->value} it applies to.");
        }

        if (isset($data['currency'])) {
            $data['currency'] = strtoupper((string) $data['currency']);
        }

        return $data;
    }
}
