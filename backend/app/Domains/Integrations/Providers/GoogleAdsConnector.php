<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\Support\PlatformHttp;
use App\Domains\Integrations\ValueObjects\SyncResult;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;

/**
 * Google Ads API (REST).
 *
 * The only one of the six with a query LANGUAGE rather than endpoints: campaigns and metrics both come
 * from `googleAds:searchStream` with GAQL, and the response is a STREAM — a JSON array of chunks, each
 * with its own `results` — so a reader that expects one object silently sees the first chunk and drops
 * the rest. That is the whole reason `stream()` exists below.
 *
 * Money is in micros here too, and the customer id must be sent with the dashes stripped even though
 * every Google console shows it with them.
 *
 * Awaiting credentials — and note that the OAuth client is not enough: Google Ads refuses every call
 * without an approved developer token, which is why it is in the platform's `requires`.
 */
final class GoogleAdsConnector extends ApiAdvertisingConnector implements ReportsEntityGrains, ReportsPeriodReach
{
    private const MICRO = 1_000_000;

    protected function platform(): string
    {
        return 'google';
    }

    /**
     * The registry key stays `google_ads` for compatibility with every stored row that uses it, while
     * the config platform is `google` — `AdPlatforms::canonical()` maps both, and the registry keeps an
     * explicit alias so neither spelling resolves to no connector.
     */
    public function key(): string
    {
        return 'google_ads';
    }

    /**
     * GADS-HIERARCHY-001 — `listAccessibleCustomers` is not a list of ad accounts.
     *
     * Google's own page says what it returns: the accounts the authenticated user can act on
     * DIRECTLY. That includes manager accounts, and excludes the customers underneath them. Its
     * worked example is exactly the agency shape this product serves — a user with rights on manager
     * M1 and account C3 can reach M1, C1, C2 and C3, but the call returns **only M1 and C3**.
     *
     * This connector treated every returned resource name as an ad account. So for any agency working
     * through an MCC we recorded the MANAGER as an ad account and never discovered a single one of the
     * accounts that hold the campaigns and the spend. A manager holds no campaigns, so the one
     * "account" we did create synced cleanly and reported nothing — which reads as a quiet month, not
     * as a broken integration, and is the hardest kind of failure to notice.
     *
     * So the accessible customers are treated as what they are: ENTRY POINTS. Under each one,
     * `customer_client` returns every direct and indirect client — plus the entry point itself at
     * `level = 0` — with the name, currency, timezone, status and whether that client is itself a
     * manager. That last flag is what separates an account from a folder.
     */
    protected function fetchAdAccounts(OAuthTokens $tokens): array
    {
        $body = $this->read(
            $this->api($tokens)->get($this->url('customers:listAccessibleCustomers')),
            'accessible customers',
        );

        $accounts = [];

        foreach ((array) ($body['resourceNames'] ?? []) as $resource) {
            // "customers/1234567890" → "1234567890"
            $entry = $this->plainCustomerId(str_replace('customers/', '', (string) $resource));

            if ($entry === '') {
                continue;
            }

            /*
             * GADS-ROOT-TYPE-001 — ask the root WHAT IT IS before asking what is under it.
             *
             * Every root used to be handed to `clientsUnder()`, which queries `FROM customer_client`
             * with `login-customer-id` set to that root. Google documents `customer_client` as the
             * hierarchy of a MANAGER account, and a plain advertiser is not one — the old comment here
             * asserted that a plain account «answers with its own self link», and that assumption is
             * the defect rather than a description of it. On the owner's production connection the call
             * answered `403 PERMISSION_DENIED` and no account was ever discovered.
             *
             * `ListAccessibleCustomers` cannot tell us which case a root is: it is documented to ignore
             * `login-customer-id` entirely, which is exactly why it is the only call in this flow that
             * still succeeded and why its success proved nothing about the calls after it.
             *
             * So each root is probed on its own `customer` record first, and only a manager is asked
             * for a hierarchy.
             */
            $root = $this->customerRecord($tokens, $entry);

            if ($root === null) {
                continue;
            }

            if (($root['manager'] ?? false) !== true) {
                /*
                 * A directly held advertiser IS the account. It is recorded from its own record, with
                 * no manager above it — «an account held directly has no manager», and inventing one
                 * would send every later campaign and metric query through a path that does not exist.
                 */
                $accounts[$entry] ??= [
                    'external_id' => $entry,
                    'name' => (string) ($root['descriptiveName'] ?? $entry),
                    'currency' => isset($root['currencyCode']) ? (string) $root['currencyCode'] : null,
                    'timezone' => isset($root['timeZone']) ? (string) $root['timeZone'] : null,
                    'status' => strtoupper((string) ($root['status'] ?? 'ENABLED')) === 'ENABLED'
                        ? 'active'
                        : 'inactive',
                    'parent_external_id' => null,
                    'raw' => $root,
                ];

                continue;
            }

            foreach ($this->clientsUnder($tokens, $entry) as $client) {
                $id = $this->plainCustomerId((string) ($client['id'] ?? ''));

                // A manager is a container, not an advertiser. Recording one as an ad account is the
                // defect this method exists to fix, so it is skipped even at level 0.
                if ($id === '' || ($client['manager'] ?? false) === true) {
                    continue;
                }

                /*
                 * Keyed by id so an account reachable through two entry points is discovered ONCE.
                 * That is a real shape — an operator with rights on both the MCC and one of its
                 * clients — and duplicating the account would duplicate its spend on every surface.
                 */
                $accounts[$id] = [
                    'external_id' => $id,
                    'name' => (string) ($client['descriptiveName'] ?? $id),
                    'currency' => isset($client['currencyCode']) ? (string) $client['currencyCode'] : null,
                    'timezone' => isset($client['timeZone']) ? (string) $client['timeZone'] : null,
                    // Reported per client. Calling every discovered row active would put a live badge
                    // on an account the customer has already closed.
                    'status' => strtoupper((string) ($client['status'] ?? 'ENABLED')) === 'ENABLED'
                        ? 'active'
                        : 'inactive',
                    /*
                     * GADS-MCC-001 — the manager this account is genuinely reached THROUGH, read from
                     * the customer's own hierarchy. A `level` of 0 is the entry point's self link, and
                     * an account held directly has no manager above it: null, rather than the
                     * operator's own MCC id, which is what used to be written here for every tenant.
                     */
                    'parent_external_id' => ((int) ($client['level'] ?? 0)) > 0 ? $entry : null,
                    'raw' => $client,
                ];
            }
        }

        return array_values($accounts);
    }

    /**
     * What Google actually said, instead of «HTTP 403».
     *
     * GADS-ROOT-TYPE-001. Every refusal collapsed to one sentence built from the human message, so the
     * three facts that decide what an operator does next were thrown away: the `GoogleAdsFailure`
     * classification, the request id Google asks for when you report a problem, and which customer was
     * being operated on through which login context.
     *
     * `USER_PERMISSION_DENIED` means this identity cannot see that customer. An invalid
     * login-customer/customer combination means the PATH was wrong. A Cloud-project access error means
     * the project is not approved for what it asked. They lead to three different actions, and one
     * message for all of them sent the reader to none of them.
     *
     * Nothing secret is carried: an error code, a request id and customer ids are all identifiers, and
     * the developer token and OAuth secret are never read here.
     */
    private function refusal(Response $response, string $customer): string
    {
        /** @var array<string,mixed> $body */
        $body = $response->json() ?? [];
        /** @var array<string,mixed> $error */
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];

        $codes = [];
        $requestId = null;

        foreach ((array) ($error['details'] ?? []) as $detail) {
            if (! is_array($detail)) {
                continue;
            }

            $requestId ??= isset($detail['requestId']) ? (string) $detail['requestId'] : null;

            foreach ((array) ($detail['errors'] ?? []) as $one) {
                if (! is_array($one) || ! is_array($one['errorCode'] ?? null)) {
                    continue;
                }

                /*
                 * The KEY names the family — `authorizationError`, `queryError`, `quotaError` — and the
                 * value names the member. Both are kept: «authorizationError=USER_PERMISSION_DENIED»
                 * says more than either half, and a family this build has not seen before still reads.
                 */
                foreach ($one['errorCode'] as $family => $member) {
                    if (is_string($member) && $member !== '') {
                        $codes[] = $family.'='.$member;
                    }
                }
            }
        }

        $login = $this->loginCustomerId();

        return implode(' | ', array_filter([
            $this->label().' refused the query',
            $codes === [] ? null : implode(', ', array_unique($codes)),
            PlatformHttp::reason($response),
            'customer '.$customer,
            'login-customer-id '.($login ?? 'omitted'),
            $requestId === null ? null : 'request '.$requestId,
        ]));
    }

    /**
     * One root's own `customer` record — the fact that decides which query may follow.
     *
     * GADS-ROOT-TYPE-001. `customer.manager` is the only thing that separates an advertiser from a
     * folder, and it has to be read BEFORE a resource is chosen: `customer_client` is the manager
     * hierarchy, and asking a plain advertiser for one is a query Google refuses.
     *
     * Queried with `login-customer-id` set to the customer itself, which Google permits explicitly for
     * an individual account reached directly — «omit the login-customer-id header or set it to the same
     * value as CUSTOMER_ID». Using the root as its own login context is also correct for a manager
     * reading its own record, so one call serves both cases without guessing which it is in.
     *
     * Null when the root answers nothing, so a single unreadable root cannot empty the whole discovery.
     *
     * @return array<string,mixed>|null
     */
    private function customerRecord(OAuthTokens $tokens, string $entry): ?array
    {
        $rows = $this->through($entry, fn (): array => $this->stream($tokens, $entry, <<<'GAQL'
            SELECT customer.id, customer.descriptive_name, customer.currency_code,
                   customer.time_zone, customer.manager, customer.status
            FROM customer
            GAQL));

        foreach ($rows as $row) {
            if (isset($row['customer']) && is_array($row['customer'])) {
                return $row['customer'];
            }
        }

        return null;
    }

    /**
     * Every client under one accessible customer, from `customer_client`.
     *
     * The query is made THROUGH that entry point, because for a manager the hierarchy is only
     * readable from the manager itself. `customer_client` rows exist only for managers; a plain
     * account answers with its own self link, which is exactly the row we want for it anyway.
     *
     * Hidden accounts are excluded in the query rather than filtered afterwards: they are hidden in
     * the customer's own interface, and surfacing them here would show an agency accounts their own
     * Google Ads screen does not.
     *
     * @return list<array<string,mixed>>
     */
    private function clientsUnder(OAuthTokens $tokens, string $entry): array
    {
        $rows = $this->through($entry, fn (): array => $this->stream($tokens, $entry, <<<'GAQL'
            SELECT customer_client.id, customer_client.descriptive_name, customer_client.currency_code,
                   customer_client.time_zone, customer_client.manager, customer_client.status,
                   customer_client.level
            FROM customer_client
            WHERE customer_client.hidden = FALSE
            GAQL));

        $clients = [];

        foreach ($rows as $row) {
            if (isset($row['customerClient']) && is_array($row['customerClient'])) {
                $clients[] = $row['customerClient'];
            }
        }

        return $clients;
    }

    protected function fetchCampaigns(OAuthTokens $tokens, string $adAccountId): array
    {
        $rows = $this->stream($tokens, $adAccountId, <<<'GAQL'
            SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type,
                   campaign_budget.amount_micros, campaign_budget.total_amount_micros
            FROM campaign
            WHERE campaign.status != 'REMOVED'
            GAQL);

        $campaigns = [];

        foreach ($rows as $row) {
            /** @var array<string,mixed> $campaign */
            $campaign = (array) ($row['campaign'] ?? []);
            /** @var array<string,mixed> $budget */
            $budget = (array) ($row['campaignBudget'] ?? []);

            if (($campaign['id'] ?? null) === null) {
                continue;
            }

            $campaigns[] = [
                'external_id' => (string) $campaign['id'],
                'name' => (string) ($campaign['name'] ?? $campaign['id']),
                'status' => strtolower((string) ($campaign['status'] ?? 'unknown')),
                'objective' => isset($campaign['advertisingChannelType'])
                    ? (string) $campaign['advertisingChannelType']
                    : null,
                'daily_budget' => isset($budget['amountMicros']) ? (float) $budget['amountMicros'] / self::MICRO : null,
                'lifetime_budget' => isset($budget['totalAmountMicros'])
                    ? (float) $budget['totalAmountMicros'] / self::MICRO
                    : null,
                'currency' => null,
                'raw' => $row,
            ];
        }

        return $campaigns;
    }

    protected function fetchAdSets(OAuthTokens $tokens, string $adAccountId): array
    {
        $rows = $this->stream($tokens, $adAccountId, <<<'GAQL'
            SELECT ad_group.id, ad_group.name, ad_group.status, ad_group.type,
                   ad_group.cpc_bid_micros, ad_group.target_cpa_micros, campaign.id
            FROM ad_group
            WHERE ad_group.status != 'REMOVED'
            GAQL);

        $groups = [];

        foreach ($rows as $row) {
            /** @var array<string,mixed> $group */
            $group = (array) ($row['adGroup'] ?? []);
            /** @var array<string,mixed> $campaign */
            $campaign = (array) ($row['campaign'] ?? []);

            if (($group['id'] ?? null) === null || ($campaign['id'] ?? null) === null) {
                continue;
            }

            $groups[] = [
                'external_id' => (string) $group['id'],
                'campaign_external_id' => (string) $campaign['id'],
                'name' => (string) ($group['name'] ?? $group['id']),
                'status' => strtolower((string) ($group['status'] ?? 'unknown')),
                // Google states the optimisation target on the campaign's bidding strategy, not on the
                // ad group — so this level genuinely has none, and null is the true answer rather than
                // `ad_group.type` dressed up as a goal.
                'optimization_goal' => null,
                'bid_strategy' => isset($group['targetCpaMicros']) ? 'target_cpa' : (isset($group['cpcBidMicros']) ? 'manual_cpc' : null),
                /*
                 * Google budgets a CAMPAIGN, never an ad group.
                 *
                 * Copying the campaign's budget down would show the same figure on every ad group
                 * beneath it, and an operator reading four ad groups at «100 ر.س / يوم» would conclude
                 * the campaign spends four hundred.
                 */
                'daily_budget' => null,
                'lifetime_budget' => null,
                'currency' => null,
                'targeting' => null, // criteria are separate resources; not fetched at this level
                'raw' => $row,
            ];
        }

        return $groups;
    }

    protected function fetchAds(OAuthTokens $tokens, string $adAccountId): array
    {
        $rows = $this->stream($tokens, $adAccountId, <<<'GAQL'
            SELECT ad_group_ad.ad.id, ad_group_ad.ad.name, ad_group_ad.ad.type,
                   ad_group_ad.ad.final_urls, ad_group_ad.status,
                   ad_group_ad.policy_summary.approval_status, ad_group.id, campaign.id
            FROM ad_group_ad
            WHERE ad_group_ad.status != 'REMOVED'
            GAQL);

        $ads = [];

        foreach ($rows as $row) {
            /** @var array<string,mixed> $adGroupAd */
            $adGroupAd = (array) ($row['adGroupAd'] ?? []);
            /** @var array<string,mixed> $ad */
            $ad = (array) ($adGroupAd['ad'] ?? []);

            if (($ad['id'] ?? null) === null) {
                continue;
            }

            $finalUrls = array_values(array_filter((array) ($ad['finalUrls'] ?? []), 'is_string'));

            $ads[] = array_filter([
                'external_id' => (string) $ad['id'],
                'ad_set_external_id' => isset($row['adGroup']['id']) ? (string) $row['adGroup']['id'] : null,
                'campaign_external_id' => isset($row['campaign']['id']) ? (string) $row['campaign']['id'] : null,
                // Google's responsive ads are frequently unnamed; the id is the only honest label.
                'name' => (string) ($ad['name'] ?? $ad['id']),
                'status' => strtolower((string) ($adGroupAd['status'] ?? 'unknown')),
                'review_status' => match (strtoupper((string) (($adGroupAd['policySummary']['approvalStatus'] ?? '')))) {
                    'APPROVED' => 'approved',
                    'APPROVED_LIMITED', 'AREA_OF_INTEREST_ONLY' => 'pending',
                    'DISAPPROVED' => 'rejected',
                    default => null,
                },
                'destination_url' => $finalUrls[0] ?? null,
                /*
                 * Google has no creative object beneath an ad — the ad IS the creative. So there is no
                 * separate row to make, and inventing one per ad would double every ad in the panel
                 * for no information at all. The ad's type is kept in `raw`.
                 */
                'creative' => null,
                'raw' => $row,
            ], static fn ($v) => $v !== null);
        }

        return $ads;
    }

    protected function fetchInsights(OAuthTokens $tokens, string $adAccountId, string $from, string $to): array
    {
        $rows = $this->stream($tokens, $adAccountId, <<<GAQL
            SELECT campaign.id, segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks,
                   metrics.conversions, metrics.video_views, metrics.engagements
            FROM campaign
            WHERE segments.date BETWEEN '{$from}' AND '{$to}'
            GAQL);

        // The sales, asked for separately and by category — see the note on the method.
        $sales = $this->purchasesByCampaignDay($tokens, $adAccountId, $from, $to);

        $this->countRawInsightRows(count($rows));

        $insights = [];

        foreach ($rows as $row) {
            /** @var array<string,mixed> $campaign */
            $campaign = (array) ($row['campaign'] ?? []);
            /** @var array<string,mixed> $metrics */
            $metrics = (array) ($row['metrics'] ?? []);
            /** @var array<string,mixed> $segments */
            $segments = (array) ($row['segments'] ?? []);

            $campaignId = (string) ($campaign['id'] ?? '');

            if ($campaignId === '') {
                continue;
            }

            $date = (string) ($segments['date'] ?? $from);
            $sale = $sales["{$campaignId}|{$date}"] ?? [];

            $insights[] = array_filter([
                'campaign_id' => $campaignId,
                'date' => $date,
                'spend' => isset($metrics['costMicros']) ? (float) $metrics['costMicros'] / self::MICRO : null,
                'impressions' => isset($metrics['impressions']) ? (float) $metrics['impressions'] : null,
                'clicks' => isset($metrics['clicks']) ? (float) $metrics['clicks'] : null,
                /*
                 * `metrics.conversions` is EVERY conversion action the account counts — a call, a
                 * form, a signup, a store visit, a sale. It is Google's own «conversions» figure and
                 * is carried as such, but it is not the sale, and `purchases` below comes from the
                 * PURCHASE category instead. Before GADS-001 the two were the same number and a
                 * lead-generation account read its enquiries as orders.
                 */
                'conversions' => isset($metrics['conversions']) ? (float) $metrics['conversions'] : null,
                'video_views' => isset($metrics['videoViews']) ? (float) $metrics['videoViews'] : null,
                'engagements' => isset($metrics['engagements']) ? (float) $metrics['engagements'] : null,
                'purchases' => $sale['purchases'] ?? null,
                'revenue' => $sale['revenue'] ?? null,
            ], static fn ($v) => $v !== null);
        }

        return $insights;
    }

    /**
     * Sales per campaign per day, from the PURCHASE conversion category alone (GADS-001).
     *
     * Google Ads has no purchase metric. It has `metrics.conversions`, which counts whichever
     * conversion ACTIONS the account has told it to count — a phone call, a form submission, a
     * newsletter signup, a store visit, a sale — and `metrics.conversions_value`, which is the value
     * assigned to all of them. This connector reported both as `conversions` and `revenue`, so a
     * lead-generation account read its enquiries as orders and whatever value it had assigned to a
     * lead as money taken.
     *
     * The only honest way to get sales out of this API is to ask for them by category, which is what
     * this second query does. It is a second round trip and worth it: the alternative is a purchase
     * figure that is right only for accounts that happen to count nothing else.
     *
     * The category is filtered in the QUERY and checked again HERE. Not distrust of Google — a guard
     * against this method being pointed at an unfiltered query later and silently counting
     * everything again, which is precisely the defect it exists to fix.
     *
     * A campaign with no purchase-category conversions appears in neither map, so `purchases` and
     * `revenue` are ABSENT for it rather than zero: an account that does not measure sales has not
     * measured zero sales.
     *
     * @return array<string, array{purchases: float, revenue: float}> keyed «campaignId|date»
     */
    private function purchasesByCampaignDay(OAuthTokens $tokens, string $adAccountId, string $from, string $to): array
    {
        $rows = $this->stream($tokens, $adAccountId, <<<GAQL
            SELECT campaign.id, segments.date, segments.conversion_action_category,
                   metrics.conversions, metrics.conversions_value
            FROM campaign
            WHERE segments.date BETWEEN '{$from}' AND '{$to}'
              AND segments.conversion_action_category = 'PURCHASE'
            GAQL);

        $sales = [];

        foreach ($rows as $row) {
            /** @var array<string,mixed> $segments */
            $segments = (array) ($row['segments'] ?? []);

            if ((string) ($segments['conversionActionCategory'] ?? '') !== 'PURCHASE') {
                continue;
            }

            $campaignId = (string) (((array) ($row['campaign'] ?? []))['id'] ?? '');

            if ($campaignId === '') {
                continue;
            }

            /** @var array<string,mixed> $metrics */
            $metrics = (array) ($row['metrics'] ?? []);
            $key = $campaignId.'|'.(string) ($segments['date'] ?? $from);

            // Several conversion ACTIONS can share the PURCHASE category — two checkout flows, web
            // and app. Those are different sales, so within the category they are added.
            $sales[$key] ??= ['purchases' => 0.0, 'revenue' => 0.0];
            $sales[$key]['purchases'] += (float) ($metrics['conversions'] ?? 0);
            $sales[$key]['revenue'] += (float) ($metrics['conversionsValue'] ?? 0);
        }

        return $sales;
    }

    /**
     * Run a GAQL query and flatten the stream.
     *
     * `searchStream` answers with a JSON ARRAY of chunks, each `{ results: [...] }`. Reading
     * `$body['results']` — the shape the non-streaming `search` endpoint returns — finds nothing at
     * all here, and an integration that returns no rows without an error is the hardest kind to
     * notice, because everything downstream looks like a quiet day.
     *
     * @return list<array<string,mixed>>
     */
    private function stream(OAuthTokens $tokens, string $customerId, string $query): array
    {
        $customer = $this->plainCustomerId($customerId);

        /*
         * GADS-MCC-001 — which manager this query goes through.
         *
         * Google requires `login-customer-id` to be the manager the caller reaches this client
         * account through, and refuses with `USER_PERMISSION_DENIED` when it is missing for an
         * account held that way. Inside `through()` the manager is already fixed — we are walking its
         * hierarchy. Otherwise it is the parent recorded for this account at discovery: the
         * CUSTOMER's manager, not the platform operator's.
         *
         * Restored in `finally` so one customer's manager can never ride along on the next query.
         */
        $outer = $this->loginCustomerId;
        $this->loginCustomerId = $outer ?? $this->managerOf($customer);

        try {
            $response = $this->api($tokens)->post($this->url("customers/{$customer}/googleAds:searchStream"), [
                'query' => trim($query),
            ]);
        } finally {
            $this->loginCustomerId = $outer;
        }

        if (! $response->successful()) {
            throw new \RuntimeException($this->refusal($response, $customer));
        }

        /** @var array<int,array<string,mixed>> $chunks */
        $chunks = $response->json() ?? [];

        // Recorded by hand because a stream is a JSON ARRAY, so it cannot go through `read()` — which
        // takes an object — and a platform whose payloads were never retained would be the one nobody
        // could audit (INTEG-RAW-001).
        $this->rawResponses[] = ['stream' => $chunks];

        $results = [];

        foreach ($chunks as $chunk) {
            foreach ((array) ($chunk['results'] ?? []) as $row) {
                /** @var array<string,mixed> $row */
                $results[] = $row;
            }
        }

        return $results;
    }

    /**
     * The manager account the current call is being made through — read by the base class when it
     * assembles Google's headers.
     *
     * Deliberately NOT a credential. See GADS-MCC-001: it is the customer's manager, not ours.
     */
    protected function loginCustomerId(): ?string
    {
        return $this->loginCustomerId;
    }

    /** Set while a query is deliberately being made through a known manager. */
    private ?string $loginCustomerId = null;

    /**
     * Run something with the manager fixed — used while walking one entry point's hierarchy, where
     * the manager is the entry point itself rather than anything we have discovered yet.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function through(string $manager, callable $work): mixed
    {
        $outer = $this->loginCustomerId;
        $this->loginCustomerId = $manager;

        try {
            return $work();
        } finally {
            $this->loginCustomerId = $outer;
        }
    }

    /**
     * The manager we recorded this customer as sitting under, from the discovery that found it.
     *
     * Null for an account the authorised identity holds directly — Google then defaults
     * `login-customer-id` to the operating customer, which is the correct answer for that case.
     *
     * `withoutGlobalScopes` because a sync runs on a queue with no tenant context; the query is
     * already confined to the bound connection, which belongs to exactly one tenant.
     */
    private function managerOf(string $customerId): ?string
    {
        if ($this->connection === null) {
            return null;
        }

        $parent = ExternalAccount::withoutGlobalScopes()
            ->where('provider_connection_id', $this->connection->getKey())
            ->where('external_id', $customerId)
            ->value('parent_external_id');

        return is_string($parent) && $parent !== '' ? $parent : null;
    }

    /** Google shows `123-456-7890` everywhere and accepts only `1234567890`. */
    private function plainCustomerId(string $id): string
    {
        return preg_replace('/\D+/', '', $id) ?? $id;
    }

    /** The first refusal of the last grain sweep, kept so the run log can say WHY the table is empty. */
    private ?string $entityFailure = null;

    public function lastEntityFailure(): ?string
    {
        return $this->entityFailure;
    }

    /**
     * GADS-ENTITY-GRAIN-001 — the two rungs between a campaign and a creative, for Google.
     *
     * ## Why this exists
     *
     * `AccountMetricsSyncer` fills `entity_daily_metrics` for any connector that declares it can
     * answer, and Google did not declare it — so an operator's Google ad groups and ads showed «—»
     * for spend, clicks, CPC, CPM and CPA, which Google reports perfectly well. The codebase has
     * written that sentence once already about Meta: «the product printed our silence as the
     * platform's». This is the same silence, one provider over.
     *
     * It also decides whether a Google CREATIVE can ever show a figure. Nothing writes
     * `creative_daily_metrics` for Google, so `CreativeMetrics` falls back to summing the ads that
     * carry a creative — and that fallback has nothing to sum until this table is filled.
     *
     * ## The query
     *
     * `ad_group` and `ad_group_ad` are ordinary GAQL resources and the metrics are the same ones the
     * campaign sweep already selects, so this is the existing query one level down rather than a new
     * integration. `segments.date` gives the daily grain the table stores, and Google answers for the
     * whole account at once — so `$campaignExternalIds` is ignored, exactly as the interface says a
     * provider that can do this may.
     *
     * ## What is NOT claimed
     *
     * This is written against Google's documented GAQL shape and the parser this connector already
     * uses for campaign metrics. No Google account is reachable from this install, so it has not been
     * run against Google — the same standing this repo gave Meta's ad-set grain, and it is recorded
     * as IMPLEMENTED_NOT_VERIFIED rather than dressed up.
     *
     * @param  ReportsEntityGrains::AD_SET|ReportsEntityGrains::AD  $grain
     * @param  list<string>  $campaignExternalIds
     */
    public function entityInsights(
        string $adAccountId,
        string $grain,
        array $campaignExternalIds,
        string $from,
        string $to,
    ): SyncResult {
        $this->entityFailure = null;

        $isAdSet = $grain === ReportsEntityGrains::AD_SET;

        /*
         * Google's own names for the two rungs. An ad GROUP is what this product calls an ad set, and
         * `ad_group_ad` is the ad — the resource that carries the creative this library shows.
         */
        $resource = $isAdSet ? 'ad_group' : 'ad_group_ad';
        $idField = $isAdSet ? 'ad_group.id' : 'ad_group_ad.ad.id';

        try {
            $rows = $this->stream($this->tokens(), $adAccountId, <<<GAQL
                SELECT {$idField}, ad_group.id, campaign.id, segments.date,
                       metrics.cost_micros, metrics.impressions, metrics.clicks,
                       metrics.conversions, metrics.conversions_value,
                       metrics.video_views, metrics.engagements
                FROM {$resource}
                WHERE segments.date BETWEEN '{$from}' AND '{$to}'
                GAQL);
        } catch (\Throwable $e) {
            /*
             * The message, not a boolean. An empty grain has two entirely different causes — nothing
             * swept yet, or the platform refused — and only the refusal tells an operator what to do.
             */
            $this->entityFailure = $e->getMessage();

            return SyncResult::failed($e->getMessage());
        }

        $out = [];

        foreach ($rows as $row) {
            /** @var array<string,mixed> $metrics */
            $metrics = (array) ($row['metrics'] ?? []);
            /** @var array<string,mixed> $segments */
            $segments = (array) ($row['segments'] ?? []);
            /** @var array<string,mixed> $adGroup */
            $adGroup = (array) ($row['adGroup'] ?? []);
            /** @var array<string,mixed> $campaign */
            $campaign = (array) ($row['campaign'] ?? []);

            $entityId = $isAdSet
                ? (string) ($adGroup['id'] ?? '')
                : (string) (((array) ($row['adGroupAd'] ?? []))['ad']['id'] ?? '');

            if ($entityId === '') {
                continue;
            }

            /*
             * `array_filter` on `!== null`, as the campaign sweep does: a metric Google did not
             * return stays ABSENT rather than becoming a zero the reader would take as a measurement.
             */
            $out[] = array_filter([
                'entity_id' => $entityId,
                'ad_set_id' => (string) ($adGroup['id'] ?? '') ?: null,
                'campaign_id' => (string) ($campaign['id'] ?? '') ?: null,
                'date' => (string) ($segments['date'] ?? $from),
                'spend' => isset($metrics['costMicros']) ? (float) $metrics['costMicros'] / self::MICRO : null,
                'impressions' => isset($metrics['impressions']) ? (float) $metrics['impressions'] : null,
                'clicks' => isset($metrics['clicks']) ? (float) $metrics['clicks'] : null,
                'conversions' => isset($metrics['conversions']) ? (float) $metrics['conversions'] : null,
                'revenue' => isset($metrics['conversionsValue']) ? (float) $metrics['conversionsValue'] : null,
                'video_views' => isset($metrics['videoViews']) ? (float) $metrics['videoViews'] : null,
                'engagements' => isset($metrics['engagements']) ? (float) $metrics['engagements'] : null,
            ], static fn ($v) => $v !== null);
        }

        return SyncResult::of($out);
    }

    /** REACH-PERIOD-001 — Google documents `metrics.unique_users` for date ranges of at most 92 days. */
    private const UNIQUE_USERS_MAX_DAYS = 92;

    /*
     * REACH-PERIOD-001 — `metrics.unique_users` is Google's deduplicated reach for a date range, and it
     * «cannot be aggregated». It exists for Display, Video, Discovery and App campaigns only, so the
     * customer grain is not offered — a customer figure would describe only some of its campaigns —
     * and a campaign of another type answers nothing and stays not reported. The query selects no
     * `segments.date`, so the figure is one for the range, not a row per day.
     */
    public function periodReachGrains(): array
    {
        return [ReportsPeriodReach::CAMPAIGN];
    }

    public function periodReachWindowSupported(string $from, string $to): bool
    {
        return Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1 <= self::UNIQUE_USERS_MAX_DAYS;
    }

    public function periodReach(string $adAccountId, string $grain, string $from, string $to): array
    {
        if ($grain !== ReportsPeriodReach::CAMPAIGN) {
            return [];
        }

        $reported = $this->stream($this->tokens(), $adAccountId, <<<GAQL
            SELECT campaign.id, metrics.unique_users, metrics.average_impression_frequency_per_user, metrics.impressions
            FROM campaign
            WHERE segments.date BETWEEN '{$from}' AND '{$to}'
            GAQL);

        $rows = [];

        foreach ($reported as $row) {
            $metrics = (array) ($row['metrics'] ?? []);
            $id = (string) (((array) ($row['campaign'] ?? []))['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $users = is_numeric($metrics['uniqueUsers'] ?? null) ? (float) $metrics['uniqueUsers'] : null;

            $rows[] = [
                'external_id' => $id,
                // Zero unique users over delivered impressions is a campaign type without the metric.
                'reach' => $users !== null && $users > 0 ? $users : null,
                'impressions' => is_numeric($metrics['impressions'] ?? null) ? (float) $metrics['impressions'] : null,
                'frequency' => is_numeric($metrics['averageImpressionFrequencyPerUser'] ?? null) ? (float) $metrics['averageImpressionFrequencyPerUser'] : null,
            ];
        }

        return $rows;
    }
}
