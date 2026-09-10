<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Subscriptions\Services\SubscriptionCheckout;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

/**
 * PAY-AUDIT-003 — this product does not sell a trial, and must not say it does.
 *
 * The owner's decision of 2026-08-09 is recorded as VERIFIED: «no free tier, no free trial, no 7
 * free days — a paid first month at an introductory price on EVERY plan». That row also records the
 * defect it fixed on the way: «the signup badge said «تجربة» for a charge».
 *
 * The same word survived in the two places a CUSTOMER actually reads it, neither of which is the
 * signup badge:
 *
 *   - `SubscriptionCheckout::describe('trial')` built «CampaignsHub trial — {plan}», which is the
 *     description sent to the payment gateway. It is what the checkout page shows while somebody is
 *     entering a card, and what their bank statement carries afterwards. A paid charge described as
 *     a trial is the most expensive place in the product to use that word.
 *   - the subscriptions page rendered the `trialing` status as «فترة تجريبية / Trialing».
 *
 * The stored status stays `trialing`. It is the value in the column, the gateways' own vocabulary,
 * and renaming it would be a migration in service of a caption; what was wrong is the reading.
 */
final class IntroductoryMonthWordingTest extends TestCase
{
    /** The word this product may not use for a charge, in either language. */
    private const TRIAL_WORDS = ['trial', 'تجريبية', 'تجربة'];

    /**
     * The gateway description a customer reads while paying.
     *
     * Asserted through the method rather than the string, because the description is assembled and a
     * grep would pass on a constant that is never used.
     */
    public function test_the_payment_description_does_not_call_a_paid_month_a_trial(): void
    {
        $describe = new ReflectionMethod(SubscriptionCheckout::class, 'describe');
        $describe->setAccessible(true);

        $checkout = $this->app->make(SubscriptionCheckout::class);

        foreach (['trial', 'reactivation', 'plan_change', 'renewal'] as $purpose) {
            $text = (string) $describe->invoke($checkout, $purpose, 'growth');

            foreach (self::TRIAL_WORDS as $word) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $word,
                    $text,
                    "the «{$purpose}» charge is described to the customer as «{$text}»",
                );
            }
        }
    }

    /** And the paid first month is NAMED, so the description says what the money is for. */
    public function test_the_introductory_month_is_named_in_the_description(): void
    {
        $describe = new ReflectionMethod(SubscriptionCheckout::class, 'describe');
        $describe->setAccessible(true);

        $text = (string) $describe->invoke($this->app->make(SubscriptionCheckout::class), 'trial', 'growth');

        $this->assertStringContainsStringIgnoringCase('introductory', $text);
        $this->assertStringContainsString('growth', $text);
    }

    /**
     * The subscriptions page's own status label, read off the source.
     *
     * A frontend assertion would live in vitest; this is here because the RULE is commercial and
     * belongs beside the payment description it travels with — one test failing tells whoever
     * reintroduces the word both places to look.
     */
    public function test_the_subscriptions_page_does_not_label_the_status_a_trial(): void
    {
        $page = File::get(base_path('../frontend/src/features/subscriptions/SubscriptionsPage.tsx'));

        preg_match_all("/st_trialing:\s*'([^']*)'/", $page, $matches);

        $this->assertNotEmpty($matches[1], 'the status label was renamed or removed — update this guard with it');

        foreach ($matches[1] as $label) {
            foreach (self::TRIAL_WORDS as $word) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $word,
                    $label,
                    "the subscriptions page calls a paid introductory month «{$label}»",
                );
            }
        }
    }
}
