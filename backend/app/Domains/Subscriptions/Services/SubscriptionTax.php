<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Services;

use App\Domains\Billing\Support\TaxTreatment;
use App\Domains\Subscriptions\Models\SubscriptionPlan;

/**
 * PRICING-VAT-001 — the VAT on a subscription charge, decided in one place.
 *
 * A plan's catalogue edition says whether its prices include VAT. A VAT-exclusive price (the 2026-11 SAR catalogue) is charged
 * with 15% added; an all-in price (the launch catalogue) is charged exactly as before and its VAT is the part of it the invoice
 * shows as included. Either way `gross` is what the customer is asked to pay and the invoice total equals it.
 */
final class SubscriptionTax
{
    /**
     * @return array{net: string, tax: string, gross: string, treatment: string, rate: float, prices_include_vat: bool}
     */
    public function split(?string $planCode, string $amount): array
    {
        $treatment = TaxTreatment::DEFAULT;
        $rate = TaxTreatment::rate($treatment);
        $inclusive = $this->pricesIncludeVat($planCode);
        $value = round((float) $amount, 2);

        if ($inclusive) {
            $net = round($value / (1 + $rate), 2);
            $tax = round($value - $net, 2);
            $gross = $value;
        } else {
            $net = $value;
            $tax = TaxTreatment::taxFor($treatment, $net);
            $gross = round($net + $tax, 2);
        }

        return [
            'net' => $this->money($net), 'tax' => $this->money($tax), 'gross' => $this->money($gross),
            'treatment' => $treatment, 'rate' => $rate, 'prices_include_vat' => $inclusive,
        ];
    }

    /** Unknown plan or edition: all-in — never add a tax nobody priced in. */
    public function pricesIncludeVat(?string $planCode): bool
    {
        if ($planCode === null) {
            return true;
        }
        $plan = SubscriptionPlan::query()->with('catalogueVersion')->where('code', $planCode)->first();

        return $plan?->catalogueVersion === null || (bool) $plan->catalogueVersion->prices_include_vat;
    }

    private function money(float $v): string
    {
        return number_format($v, 2, '.', '');
    }
}
