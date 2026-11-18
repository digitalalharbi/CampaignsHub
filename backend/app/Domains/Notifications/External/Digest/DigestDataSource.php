<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Digest;

/**
 * The figures of ONE project over ONE complete-days window, already in the recipient's display currency (spec §10, §12).
 *
 * Shape (every money figure converted through FxEngine BEFORE any sum; null = not measured, never 0):
 * - `complete` bool, `missing` list<string> — incomplete data defers the message (2 h, once), then «قيد التحديث»;
 * - `currency` (display), `fx` list of {from,to,rate,rate_date,kind,withheld}; any `withheld` → review;
 * - `spend`, `previous_spend`;
 * - `objectives` list of {objective, spend, result, result_key, cost_per_result, revenue?, roas?, previous_result,
 *   previous_cost_per_result} — one row per objective family; cost per result is NEVER summed across rows;
 * - `platforms` list of {provider, spend, previous_spend};
 * - `series` / `previous_series` list of {date, spend};
 * - `campaigns` top three {name, objective, spend, result, result_key, cost_per_result};
 * - `budget` {has_budget, currency, approved, spent, remaining, used_ratio, pace_per_day, run_out_date, original?};
 * - `suggestions` list of {text_ar, text_en, approved}; `achievements` list of {key, value, previous};
 * - `data_project_ids` every project whose rows contributed (the privacy check compares it with the subscription).
 */
interface DigestDataSource
{
    /** @return array<string, mixed> */
    public function project(string $tenantId, string $projectId, string $from, string $to, string $displayCurrency): array;
}
