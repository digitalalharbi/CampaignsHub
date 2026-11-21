<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Services;

use App\Domains\Billing\Support\TaxTreatment;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionPlan;

/**
 * PRICING-VAT-001/002 — the VAT on a subscription charge, decided in one place.
 *
 * The terms are (treatment, whether the price includes VAT). A subscription carries the terms it was sold under; a new sale takes
 * its plan's catalogue edition's, which the console manages. A VAT-exclusive price is charged with the VAT added; an all-in price
 * is charged as it is, its VAT shown as included. Either way `gross` is what the customer is asked for and the invoice total.
 */
final class SubscriptionTax
{
    /**
     * Treatments the console may set for a whole catalogue edition. Legal eligibility (PRICING-VAT-002): a subscription sold in
     * the Kingdom is a standard-rated supply. Zero-rating (export of services), exemption and out-of-scope depend on the CUSTOMER
     * — their residence and evidence of it — and the product records no such per-customer eligibility, so none of them may be
     * applied to an edition. The legacy 5% exists only on old documents.
     */
    public const OFFERED = ['basic_15'];

    /** Treatments that exist but need per-customer eligibility evidence; listed so the console can say why they are not offered. */
    public const NEEDS_CUSTOMER_ELIGIBILITY = ['zero_rated', 'exempt', 'out_of_scope'];

    /**
     * @return array{net: string, tax: string, gross: string, treatment: string, rate: float, prices_include_vat: bool}
     */
    public function split(?string $planCode, string $amount, ?Subscription $subscription = null): array
    {
        ['tax_treatment' => $treatment, 'prices_include_vat' => $inclusive] = $this->terms($planCode, $subscription);
        $rate = TaxTreatment::rate($treatment);
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

    /**
     * The terms for a charge: the subscription's own when it is charged for the plan it holds, else the plan's edition's.
     *
     * @return array{tax_treatment: string, prices_include_vat: bool}
     */
    public function terms(?string $planCode, ?Subscription $subscription = null): array
    {
        if ($subscription !== null && $subscription->tax_treatment !== null
            && ($planCode === null || $subscription->plan?->code === $planCode)) {
            return ['tax_treatment' => (string) $subscription->tax_treatment, 'prices_include_vat' => (bool) ($subscription->prices_include_vat ?? true)];
        }
        $plan = $planCode === null ? null : SubscriptionPlan::query()->with('catalogueVersion')->where('code', $planCode)->first();

        return $this->termsFor($plan);
    }

    /**
     * What a sale of this plan is made under. Unknown plan or edition: all-in at the standard rate — never a tax nobody priced in.
     *
     * @return array{tax_treatment: string, prices_include_vat: bool}
     */
    public function termsFor(?SubscriptionPlan $plan): array
    {
        $edition = $plan?->catalogueVersion;
        if ($edition === null) {
            return ['tax_treatment' => TaxTreatment::DEFAULT, 'prices_include_vat' => true];
        }
        $treatment = TaxTreatment::isValid($edition->tax_treatment ?? null) ? (string) $edition->tax_treatment : TaxTreatment::DEFAULT;

        return ['tax_treatment' => $treatment, 'prices_include_vat' => (bool) $edition->prices_include_vat];
    }

    /** Kept for callers that only ask the question. */
    public function pricesIncludeVat(?string $planCode): bool
    {
        return $this->terms($planCode)['prices_include_vat'];
    }

    private function money(float $v): string
    {
        return number_format($v, 2, '.', '');
    }
}
