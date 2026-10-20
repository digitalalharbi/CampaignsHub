<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Touchpoints;

use App\Domains\Commerce\Models\CommerceOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * TOUCHPOINT-EVIDENCE-001 — the eligible marketing journey of each order, and nothing else.
 *
 * A touchpoint reaches a model only when ALL of these hold:
 *
 *  - its link to the order is `verified` (never merely declared);
 *  - its consent state is `granted` or `not_required` — a refused or unknown decision is not evidence;
 *  - it belongs to the same tenant and project as the order, on both the link and the touchpoint;
 *  - it happened at or before the order was placed, and inside the lookback window;
 *  - when a cut-off is given, it was RECORDED by then — which is what lets a past report be rebuilt from only
 *    what was known at the time, instead of drifting as later evidence arrives.
 *
 * An order with no such touchpoint is absent from the result. It is not given a journey.
 */
final class OrderJourneys
{
    /**
     * @param  Collection<int, CommerceOrder>  $orders
     * @return array<string, list<array{channel: string, at: Carbon, is_paid: bool, method: string, touchpoint_id: string}>> order id => touches, oldest first
     */
    public function forOrders(string $tenantId, string $projectId, Collection $orders, int $lookbackDays, ?Carbon $recordedBy = null): array
    {
        $placed = [];
        foreach ($orders as $order) {
            if ($order->placed_at !== null) {
                $placed[(string) $order->getKey()] = $order->placed_at;
            }
        }
        if ($placed === []) {
            return [];
        }

        $rows = DB::table('order_touchpoint_links as l')
            ->join('marketing_touchpoints as t', 't.id', '=', 'l.touchpoint_id')
            ->where('l.tenant_id', $tenantId)->where('l.project_id', $projectId)
            ->where('t.tenant_id', $tenantId)->where('t.project_id', $projectId)
            ->whereIn('l.commerce_order_id', array_keys($placed))
            ->where('l.grade', OrderTouchpointLink::VERIFIED)
            ->whereIn('t.consent_state', [MarketingTouchpoint::GRANTED, MarketingTouchpoint::NOT_REQUIRED])
            ->when($recordedBy !== null, fn ($q) => $q->where('t.recorded_at', '<=', $recordedBy))
            ->orderBy('t.occurred_at')->orderBy('t.id')
            ->get(['l.commerce_order_id', 'l.method', 't.id as touchpoint_id', 't.channel', 't.is_paid', 't.occurred_at']);

        $out = [];
        foreach ($rows as $row) {
            $orderId = (string) $row->commerce_order_id;
            $at = Carbon::parse($row->occurred_at);
            $orderAt = $placed[$orderId];
            if ($at->gt($orderAt) || $at->lt($orderAt->copy()->subDays($lookbackDays))) {
                continue;
            }
            $out[$orderId][] = [
                'channel' => (string) $row->channel,
                'at' => $at,
                'is_paid' => (bool) $row->is_paid,
                'method' => (string) $row->method,
                'touchpoint_id' => (string) $row->touchpoint_id,
            ];
        }

        return $out;
    }
}
