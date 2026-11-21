<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Catalogue;

/**
 * ORCH-100 §4 — what each provider's account hierarchy ACTUALLY looks like.
 *
 * ## Why this is a capability model and not a shared abstraction
 *
 * The wizard is one flow for every provider, and the temptation with one flow is to give every
 * provider the same shape so the interface never has to branch. That would mean inventing a parent
 * level for providers that do not publish one — a step asking the customer to choose a «business»
 * that does not exist, populated with something derived to fill the box.
 *
 * So the answer is taken from what the ADAPTER actually returns, which is the only thing that can be
 * true. A provider whose discovery emits `parent_external_id` has a real parent level and gets the
 * step; one that does not, does not, and the wizard collapses that step for it.
 *
 * Today exactly two do:
 *
 * - **Snapchat** — `me/organizations?with_ad_accounts=true` returns the organisation with each ad
 *   account, and the adapter carries both its id and its name. This is the live case: 309 accounts
 *   arrived under their organisations and must be chosen inside them, not from one flat list.
 * - **Google Ads** — `customer_client` gives the manager an account is reached through
 *   (GADS-HIERARCHY-001), which the adapter records as the account's parent.
 *
 * TikTok has Business Centre and LinkedIn has organisations — but neither is in what our discovery
 * currently returns, and declaring a step we cannot populate would be the same invention from the
 * other direction. When an adapter starts returning one, it is declared and the wizard gains the step
 * without any interface change.
 *
 * Meta's discovery DOES record the business an account was reached through (META-BUSINESS-ACCOUNTS-001),
 * and GA4's records the Analytics account above a property. Both declare that parent; neither has ever
 * had a wizard step, so `parentStep` stays false for them. This class answers about the STEP.
 *
 * ## Where the answer lives
 *
 * INTEG-CAPABILITY-CONTRACT-001 — the map that stood here moved onto each provider's declaration in
 * `ProviderCatalogue`, because a new provider should not need an edit to a second file. This class
 * keeps its name and its answers. The key is looked up exactly as it was: `google_ads` has never had
 * a parent step here and does not gain one.
 *
 * ChatGPT Ads is absent for a stronger reason than the others: OpenAI publishes no Business Centre,
 * Portfolio or Manager Account for advertising at all, and its key is scoped to a single ad account.
 * There is no layer that could start being returned.
 */
final class ProviderHierarchy
{
    /** Whether this provider's wizard shows a parent-selection step at all. */
    public static function hasParent(string $provider): bool
    {
        return self::parent($provider) !== null;
    }

    /**
     * How this provider names its parent step, or null when it has none.
     *
     * The label is the provider's own word. An agency choosing a Snapchat «Organization» should see
     * «Organization», not a house term invented to cover every provider at once.
     *
     * @return array{key: string, label: string, labelAr: string}|null
     */
    public static function parent(string $provider): ?array
    {
        $declared = (ProviderCatalogue::all()[$provider] ?? null)?->capabilities;

        return $declared?->parentStep === true ? $declared->parentLevel : null;
    }

    /**
     * The steps this provider's wizard actually has.
     *
     * Returned rather than hard-coded in the interface so the step numbering, the progress indicator
     * and the «back» target all agree, and so a provider without a parent never renders an empty
     * step the customer has to click past.
     *
     * @return list<string>
     */
    public static function steps(string $provider, bool $agency): array
    {
        return array_values(array_filter([
            // Which client this authorisation belongs to is a security boundary, not a filter, so it
            // is asked FIRST and only where there are clients to choose between (ORCH-100 §G).
            $agency ? 'client_workspace' : null,
            'authorize',
            self::hasParent($provider) ? 'parent' : null,
            'accounts',
            'project',
            'review',
            'sync',
        ], static fn (?string $step) => $step !== null));
    }
}
