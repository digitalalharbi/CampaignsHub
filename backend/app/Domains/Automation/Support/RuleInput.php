<?php

declare(strict_types=1);

namespace App\Domains\Automation\Support;

use App\Domains\Automation\Models\AutomationRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The ONE definition of a valid rule — AUTO-RULES-BUILDER-003, AUTO-RULES-AGENTS-009.
 *
 * The builder's endpoint and an agent's draft both pass through here, so an agent cannot save a rule
 * a person could not have saved: the same metrics, operators, windows, actions and limits, and the
 * same refusals (a budget change needs a percentage; an ad has no budget of its own).
 */
final class RuleInput
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function validate(array $input): array
    {
        $metrics = array_keys(RuleCatalog::METRICS);
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:160'],
            'enabled' => ['required', 'boolean'],
            'mode' => ['required', Rule::in(AutomationRule::MODES)],
            'level' => ['required', Rule::in(AutomationRule::LEVELS)],
            'logic' => ['required', Rule::in(['all', 'any'])],
            'scope' => ['nullable', 'array'],
            'scope.platforms' => ['nullable', 'array'], 'scope.platforms.*' => ['string', 'max:30'],
            'scope.account_ids' => ['nullable', 'array'], 'scope.account_ids.*' => ['uuid'],
            'scope.campaign_ids' => ['nullable', 'array'], 'scope.campaign_ids.*' => ['uuid'],
            'scope.ad_set_ids' => ['nullable', 'array'], 'scope.ad_set_ids.*' => ['uuid'],
            'scope.ad_ids' => ['nullable', 'array'], 'scope.ad_ids.*' => ['uuid'],
            'conditions' => ['required', 'array', 'min:1', 'max:10'],
            'conditions.*.metric' => ['required', Rule::in($metrics)],
            'conditions.*.operator' => ['required', Rule::in(RuleCatalog::OPERATORS)],
            'conditions.*.window_days' => ['required', 'integer', Rule::in(RuleCatalog::WINDOWS)],
            'conditions.*.value' => ['required'],
            'action' => ['required', 'array'],
            'action.type' => ['required', Rule::in(array_keys(RuleCatalog::ACTIONS))],
            'action.percent' => ['nullable', 'numeric', 'min:1', 'max:50'],
            'schedule' => ['required', 'array'],
            'schedule.frequency' => ['required', Rule::in(array_keys(RuleCatalog::FREQUENCIES))],
            'schedule.at' => ['nullable', 'regex:/^\d{2}:\d{2}$/'],
            'schedule.timezone' => ['nullable', 'timezone'],
            'cooldown_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'min_spend' => ['nullable', 'numeric', 'min:0'],
        ])->validate();
        foreach ($data['conditions'] as $i => $c) {
            $isStatus = RuleCatalog::METRICS[$c['metric']][2] === 'status';
            abort_if($isStatus ? ! in_array($c['value'], ['active', 'paused'], true) || $c['operator'] !== 'eq' : ! is_numeric($c['value']), 422, "Condition {$i}: the value does not fit the metric.");
        }
        if (in_array($data['action']['type'], ['budget_increase', 'budget_decrease'], true)) {
            abort_if(! isset($data['action']['percent']), 422, 'A budget change needs a percentage.');
            abort_if($data['level'] === 'ad', 422, 'An ad has no budget of its own.');
        }

        return $data;
    }
}
