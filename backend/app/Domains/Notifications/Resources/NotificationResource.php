<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Resources;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Notifications\Support\AlertEventText;
use App\Domains\Notifications\Support\AlertVisual;
use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Access\ProjectCapability;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AppNotification */
final class NotificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // An Arabic reader gets the Arabic wording where there is one; everyone else, and every older row, reads what was stored.
        $ar = ($request->user()->locale ?? 'ar') !== 'en';

        return [
            'id' => $this->id,
            'type' => $this->type,
            'severity' => $this->severity,
            'title' => $ar && $this->title_ar ? $this->title_ar : $this->title,
            'message' => $ar && $this->message_ar ? $this->message_ar : $this->message,
            'project_id' => $this->project_id,
            'client_workspace_id' => $this->client_workspace_id,
            'action_url' => $this->action_url,
            'status' => $this->status,
            'read_at' => optional($this->read_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
            // DIGESTS-CADENCE-BUDGETS-001 — the same grammar as the email: chip state, the problem drawn, the next steps.
            'visual' => $this->visual($ar, $request),
        ];
    }

    /** Alert types whose drawing carries an amount or a ratio of amounts. */
    private const MONEY_RULES = ['budget_risk', 'cpa_increase', 'cpl_increase', 'roas_drop', 'no_results', 'metric_anomaly', 'ga4_sales_drop', 'ga4_aov_shift', 'ga4_unmatched_spend'];

    private function moneyWithheld(Request $request): bool
    {
        $user = $request->user();
        if (! $user instanceof User || $this->project_id === null || ! ProjectCapability::exists('money.view')) {
            return false;
        }

        // Asked once per project per request: a page of a hundred notifications is mostly a handful of projects.
        $memo = (array) $request->attributes->get('bell_money_withheld', []);
        $key = (string) $this->project_id;
        if (! array_key_exists($key, $memo)) {
            $memo[$key] = ! app(ProjectAbilities::class)->allows($user, $key, 'money.view');
            $request->attributes->set('bell_money_withheld', $memo);
        }

        return (bool) $memo[$key];
    }

    /** @return array{state: string, viz: array<string,mixed>|null, steps: list<string>} */
    private function visual(bool $ar, Request $request): array
    {
        $event = $this->relationLoaded('alertEvent') ? $this->getRelation('alertEvent') : null;
        $severity = (string) $this->severity;
        if (! $event instanceof AlertEvent) {
            return ['state' => AlertVisual::chip($severity, $ar)['state'], 'viz' => null, 'steps' => []];
        }
        $ctx = (array) ($event->context ?? []);
        $type = (string) $event->type;
        $say = AlertEventText::plain($type, $ctx, $ar);
        $card = AlertVisual::present(['severity' => $severity, 'rule' => $type, 'ctx' => $ctx, 'detail' => '', 'action' => $say['action']], $ar);

        /*
         * MEMBER-VISIBILITY-001 — a member whose membership withholds the money on this project sees the alert's STAGES, never
         * its amounts: a cost before → now, a spend against a budget, or a spend with no results is money by another name.
         */
        if ($this->moneyWithheld($request) && in_array($type, self::MONEY_RULES, true)) {
            $card['viz'] = AlertVisual::stages($type, [], $severity, $ar);
        }

        return ['state' => $card['chip']['state'], 'viz' => $card['viz'], 'steps' => $card['steps']];
    }
}
