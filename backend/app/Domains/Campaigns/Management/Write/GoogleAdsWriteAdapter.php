<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Management\Write;

use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Support\PlatformHttp;

/**
 * Google Ads API — changes are `:mutate` operations on resource names, with an update mask.
 *
 * Provider semantics kept as Google defines them: the only removal is REMOVE, and it is final, so it
 * is offered as «delete» and never as an archive; there is no copy endpoint, so no duplicate; a
 * campaign's channel type cannot change, so no objective edit; ad groups carry no budget. A budget is
 * its own resource and may be SHARED by several campaigns — a shared budget is refused here, because
 * changing it from one campaign's page silently changes every other campaign it funds.
 */
final class GoogleAdsWriteAdapter extends AbstractWriteAdapter
{
    protected const SUPPORT = [
        'campaign' => [
            'pause' => true, 'resume' => true, 'rename' => true, 'budget' => true, 'bid_strategy' => true, 'delete' => true,
            'create_ad_set' => true,
            'archive' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'duplicate' => WriteRefusal::PROVIDER_UNSUPPORTED,
        ],
        'ad_set' => [
            'pause' => true, 'resume' => true, 'rename' => true, 'delete' => true,
            // A responsive search ad: its headlines, descriptions and landing URL ARE the ad.
            'create_ad' => true,
            // Countries live on the CAMPAIGN in Google Ads, age and gender on the ad group; both are replaced in one atomic mutate.
            'targeting' => true,
            'budget' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'archive' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'duplicate' => WriteRefusal::PROVIDER_UNSUPPORTED,
            // A Search ad group has no placements to choose; it serves on Google Search by definition.
            'placements' => WriteRefusal::PROVIDER_UNSUPPORTED,
        ],
        'ad' => [
            'pause' => true, 'resume' => true, 'delete' => true,
            'budget' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'schedule' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'bid_strategy' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'archive' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'duplicate' => WriteRefusal::PROVIDER_UNSUPPORTED,
            // A Google ad IS its assets; there is no separate creative to bind.
            'creative' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'destination' => true,
        ],
    ];

    /** Strategy → [the JSON field, the update-mask path]. */
    private const STRATEGIES = [
        'MANUAL_CPC' => ['manualCpc', 'manual_cpc'],
        'MAXIMIZE_CONVERSIONS' => ['maximizeConversions', 'maximize_conversions'],
        'MAXIMIZE_CONVERSION_VALUE' => ['maximizeConversionValue', 'maximize_conversion_value'],
        'TARGET_SPEND' => ['targetSpend', 'target_spend'],
    ];

    public function provider(): string
    {
        return 'google';
    }

    public function bidStrategies(WriteLevel $level): array
    {
        return $level === WriteLevel::Campaign ? array_keys(self::STRATEGIES) : [];
    }

    public function budgetKinds(WriteLevel $level): array
    {
        return $level === WriteLevel::Campaign ? ['daily'] : [];
    }

    public function objectives(): array
    {
        return ['SEARCH'];
    }

    public function createRefusal(): ?string
    {
        return null;
    }

    public function perform(ApiAdvertisingConnector $connector, WriteTarget $target, WriteAction $action, array $input): WriteOutcome
    {
        $customer = $this->customer($target->accountExternalId);
        [$collection, $resource] = $this->resource($customer, $target);
        $headers = $this->headers($target->managerExternalId, $customer);

        if ($action === WriteAction::Budget) {
            return $this->budget($connector, $customer, $target, (float) $input['daily_budget'], $headers);
        }

        if ($action === WriteAction::CreateAdSet) {
            $response = $this->send($connector, 'POST', "customers/{$customer}/adGroups:mutate", ['operations' => [['create' => [
                'name' => (string) $input['name'],
                'campaign' => "customers/{$customer}/campaigns/{$target->externalId}",
                'status' => 'PAUSED',
                'type' => 'SEARCH_STANDARD',
                'cpcBidMicros' => (string) $this->micros((float) $input['bid_amount']),
            ]]]], idempotent: false, headers: $headers);
            $resource = (string) (($response->json() ?? [])['results'][0]['resourceName'] ?? '');

            return $this->verdict($response, [], $resource !== '' ? substr($resource, (int) strrpos($resource, '/') + 1) : null);
        }

        if ($action === WriteAction::Targeting) {
            return $this->targeting($connector, $customer, $target, $input, $headers);
        }

        if ($action === WriteAction::CreateAd) {
            $response = $this->send($connector, 'POST', "customers/{$customer}/adGroupAds:mutate", ['operations' => [['create' => [
                'adGroup' => "customers/{$customer}/adGroups/{$target->externalId}",
                'status' => 'PAUSED',
                'ad' => [
                    'finalUrls' => [(string) $input['url']],
                    'responsiveSearchAd' => [
                        'headlines' => array_map(static fn (string $t): array => ['text' => $t], array_values((array) $input['headlines'])),
                        'descriptions' => array_map(static fn (string $t): array => ['text' => $t], array_values((array) $input['descriptions'])),
                    ],
                ],
            ]]]], idempotent: false, headers: $headers);
            // customers/{c}/adGroupAds/{adGroupId}~{adId} — the ad's own id is after the tilde.
            $resource = (string) (($response->json() ?? [])['results'][0]['resourceName'] ?? '');
            $adId = str_contains($resource, '~') ? substr($resource, (int) strrpos($resource, '~') + 1) : null;

            return $this->verdict($response, [], $adId);
        }

        if ($action === WriteAction::Destination) {
            return $this->verdict($this->send($connector, 'POST', "customers/{$customer}/ads:mutate", [
                'operations' => [['update' => ['resourceName' => "customers/{$customer}/ads/{$target->externalId}", 'finalUrls' => [(string) $input['url']]], 'updateMask' => 'final_urls']],
            ], headers: $headers), ['destination_url' => (string) $input['url']]);
        }

        if ($action === WriteAction::Delete) {
            return $this->verdict(
                $this->send($connector, 'POST', "customers/{$customer}/{$collection}:mutate", ['operations' => [['remove' => $resource]]], headers: $headers),
                $this->statusMirror($action),
            );
        }

        [$fields, $mask, $mirror] = match ($action) {
            WriteAction::Pause => [['status' => 'PAUSED'], 'status', $this->statusMirror($action)],
            WriteAction::Resume => [['status' => 'ENABLED'], 'status', $this->statusMirror($action)],
            WriteAction::Rename => [['name' => (string) $input['name']], 'name', ['name' => (string) $input['name']]],
            WriteAction::BidStrategy => $this->strategy((string) $input['strategy'], isset($input['bid_amount']) ? (float) $input['bid_amount'] : null),
            default => [[], '', []],
        };

        return $this->verdict($this->send($connector, 'POST', "customers/{$customer}/{$collection}:mutate", [
            'operations' => [['update' => ['resourceName' => $resource] + $fields, 'updateMask' => $mask]],
        ], headers: $headers), $mirror);
    }

    public function create(ApiAdvertisingConnector $connector, CreateCampaignCommand $command): WriteOutcome
    {
        if ($command->dailyBudget === null) {
            return WriteOutcome::refused('A Google Ads campaign needs a daily budget — state one to create it.');
        }

        $customer = $this->customer($command->accountExternalId);
        $headers = $this->headers($command->managerExternalId, $customer);

        $budget = $this->send($connector, 'POST', "customers/{$customer}/campaignBudgets:mutate", ['operations' => [['create' => [
            'name' => $command->name.' — budget '.substr(md5($command->name.microtime()), 0, 6),
            'amountMicros' => (string) $this->micros($command->dailyBudget),
            'deliveryMethod' => 'STANDARD',
            'explicitlyShared' => false,
        ]]]], idempotent: false, headers: $headers);
        if (! PlatformHttp::succeeded($budget)) {
            return $this->verdict($budget);
        }
        $budgetName = (string) (($budget->json() ?? [])['results'][0]['resourceName'] ?? '');

        $campaign = $this->send($connector, 'POST', "customers/{$customer}/campaigns:mutate", ['operations' => [['create' => [
            'name' => $command->name,
            'status' => 'PAUSED',
            'advertisingChannelType' => 'SEARCH',
            'campaignBudget' => $budgetName,
            'manualCpc' => (object) [],
            'networkSettings' => ['targetGoogleSearch' => true, 'targetSearchNetwork' => true, 'targetContentNetwork' => false],
            'containsEuPoliticalAdvertising' => 'DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING',
        ]]]], idempotent: false, headers: $headers);

        if (! PlatformHttp::succeeded($campaign)) {
            // The budget would otherwise sit on the account funding nothing. Best effort; the refusal stands either way.
            $this->send($connector, 'POST', "customers/{$customer}/campaignBudgets:mutate", ['operations' => [['remove' => $budgetName]]], headers: $headers);

            return $this->verdict($campaign);
        }

        $resource = (string) (($campaign->json() ?? [])['results'][0]['resourceName'] ?? '');
        $id = $resource !== '' ? substr($resource, (int) strrpos($resource, '/') + 1) : null;

        return $this->verdict($campaign, [], $id);
    }

    /** @param  array<string, string>  $headers */
    private function budget(ApiAdvertisingConnector $connector, string $customer, WriteTarget $target, float $amount, array $headers): WriteOutcome
    {
        $read = $this->send($connector, 'POST', "customers/{$customer}/googleAds:search", [
            'query' => 'SELECT campaign.campaign_budget, campaign_budget.explicitly_shared FROM campaign WHERE campaign.id = '.(int) $target->externalId,
        ], headers: $headers);
        if (! PlatformHttp::succeeded($read)) {
            return $this->verdict($read);
        }
        $row = ($read->json() ?? [])['results'][0] ?? [];
        $budgetName = (string) ($row['campaign']['campaignBudget'] ?? '');
        if ($budgetName === '') {
            return WriteOutcome::refused('Google Ads returned no budget for this campaign.');
        }
        if (($row['campaignBudget']['explicitlyShared'] ?? false) === true) {
            return WriteOutcome::refused('This campaign draws on a SHARED budget — changing it here would change every campaign it funds. Change it in Google Ads, where those campaigns are listed.');
        }

        return $this->verdict($this->send($connector, 'POST', "customers/{$customer}/campaignBudgets:mutate", [
            'operations' => [['update' => ['resourceName' => $budgetName, 'amountMicros' => (string) $this->micros($amount)], 'updateMask' => 'amount_micros']],
        ], headers: $headers), ['daily_budget' => $amount]);
    }

    /** Google's age ranges as [criterion type, from, to]. */
    private const AGE_RANGES = [
        ['AGE_RANGE_18_24', 18, 24], ['AGE_RANGE_25_34', 25, 34], ['AGE_RANGE_35_44', 35, 44],
        ['AGE_RANGE_45_54', 45, 54], ['AGE_RANGE_55_64', 55, 64], ['AGE_RANGE_65_UP', 65, 120],
    ];

    /**
     * Ad group targeting, the way Google Ads holds it.
     *
     * Countries are LOCATION criteria on the campaign, so they apply to every ad group in it — the
     * dialog says so before anything is sent. Age and gender are ad group criteria: what is outside
     * the chosen range is EXCLUDED (a negative criterion), and «undetermined» is never excluded.
     * Country codes become geo target constants by asking Google; a code Google does not list is
     * refused by name. Everything is replaced in one `googleAds:mutate`, which Google applies
     * atomically: the old criteria are removed and the new ones created, or nothing changes.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $headers
     */
    private function targeting(ApiAdvertisingConnector $connector, string $customer, WriteTarget $target, array $input, array $headers): WriteOutcome
    {
        $campaignId = (int) $target->parentExternalId;
        $adGroupId = (int) $target->externalId;
        if ($campaignId === 0) {
            return WriteOutcome::refused('The ad group\'s campaign is not known here, so its countries cannot be set.');
        }

        $codes = array_values(array_unique(array_map(static fn ($c): string => strtoupper((string) $c), (array) $input['countries'])));
        $geo = $this->send($connector, 'POST', "customers/{$customer}/googleAds:search", [
            'query' => "SELECT geo_target_constant.resource_name, geo_target_constant.country_code FROM geo_target_constant WHERE geo_target_constant.target_type = 'Country' AND geo_target_constant.status = 'ENABLED' AND geo_target_constant.country_code IN ('".implode("','", $codes)."')",
        ], headers: $headers);
        if (! PlatformHttp::succeeded($geo)) {
            return $this->verdict($geo);
        }
        $constants = [];
        foreach ((array) (($geo->json() ?? [])['results'] ?? []) as $row) {
            $code = strtoupper((string) ($row['geoTargetConstant']['countryCode'] ?? ''));
            $name = (string) ($row['geoTargetConstant']['resourceName'] ?? '');
            if ($code !== '' && $name !== '') {
                $constants[$code] ??= $name;
            }
        }
        $missing = array_values(array_diff($codes, array_keys($constants)));
        if ($missing !== []) {
            return WriteOutcome::refused('Google Ads does not list these countries: '.implode(', ', $missing).'.');
        }

        $existing = $this->send($connector, 'POST', "customers/{$customer}/googleAds:search", [
            'query' => "SELECT campaign_criterion.resource_name FROM campaign_criterion WHERE campaign.id = {$campaignId} AND campaign_criterion.type = 'LOCATION' AND campaign_criterion.negative = FALSE",
        ], headers: $headers);
        if (! PlatformHttp::succeeded($existing)) {
            return $this->verdict($existing);
        }
        $demographics = $this->send($connector, 'POST', "customers/{$customer}/googleAds:search", [
            'query' => "SELECT ad_group_criterion.resource_name FROM ad_group_criterion WHERE ad_group.id = {$adGroupId} AND ad_group_criterion.type IN ('AGE_RANGE', 'GENDER')",
        ], headers: $headers);
        if (! PlatformHttp::succeeded($demographics)) {
            return $this->verdict($demographics);
        }

        $operations = [];
        foreach ((array) (($existing->json() ?? [])['results'] ?? []) as $row) {
            $operations[] = ['campaignCriterionOperation' => ['remove' => (string) ($row['campaignCriterion']['resourceName'] ?? '')]];
        }
        foreach ($codes as $code) {
            $operations[] = ['campaignCriterionOperation' => ['create' => [
                'campaign' => "customers/{$customer}/campaigns/{$campaignId}",
                'location' => ['geoTargetConstant' => $constants[$code]],
            ]]];
        }
        foreach ((array) (($demographics->json() ?? [])['results'] ?? []) as $row) {
            $operations[] = ['adGroupCriterionOperation' => ['remove' => (string) ($row['adGroupCriterion']['resourceName'] ?? '')]];
        }
        $adGroup = "customers/{$customer}/adGroups/{$adGroupId}";
        $min = (int) ($input['age_min'] ?? 18);
        $max = (int) ($input['age_max'] ?? 65);
        $kept = [];
        foreach (self::AGE_RANGES as [$type, $from, $to]) {
            $inside = $from >= $min && ($max >= 65 || $to <= $max);
            if ($inside) {
                $kept[] = [$from, $to];
            } else {
                $operations[] = ['adGroupCriterionOperation' => ['create' => ['adGroup' => $adGroup, 'negative' => true, 'ageRange' => ['type' => $type]]]];
            }
        }
        if ($kept === []) {
            return WriteOutcome::refused('Google targets age in fixed ranges (18-24, 25-34, 35-44, 45-54, 55-64, 65+); no range lies inside the one chosen.');
        }
        $genders = (string) ($input['genders'] ?? 'all');
        if ($genders !== 'all') {
            $operations[] = ['adGroupCriterionOperation' => ['create' => ['adGroup' => $adGroup, 'negative' => true, 'gender' => ['type' => $genders === 'male' ? 'FEMALE' : 'MALE']]]];
        }

        $mirror = $this->targetingMirror($input);
        $mirror['age'] = $kept[0][0].'-'.min(65, $kept[count($kept) - 1][1]);
        $mirror['countries_scope'] = 'campaign';

        return $this->verdict(
            $this->send($connector, 'POST', "customers/{$customer}/googleAds:mutate", ['mutateOperations' => $operations], idempotent: false, headers: $headers),
            ['targeting' => $mirror],
        );
    }

    /** @return array{0: array<string, mixed>, 1: string, 2: array<string, mixed>} */
    private function strategy(string $strategy, ?float $targetCpa): array
    {
        [$field, $mask] = self::STRATEGIES[$strategy];
        if ($strategy === 'MAXIMIZE_CONVERSIONS' && $targetCpa !== null) {
            return [[$field => ['targetCpaMicros' => (string) $this->micros($targetCpa)]], 'maximize_conversions.target_cpa_micros', []];
        }

        return [[$field => (object) []], $mask, []];
    }

    /** @return array{0: string, 1: string} the mutate collection and the resource name */
    private function resource(string $customer, WriteTarget $target): array
    {
        return match ($target->level) {
            WriteLevel::Campaign => ['campaigns', "customers/{$customer}/campaigns/{$target->externalId}"],
            WriteLevel::AdSet => ['adGroups', "customers/{$customer}/adGroups/{$target->externalId}"],
            WriteLevel::Ad => ['adGroupAds', "customers/{$customer}/adGroupAds/{$target->parentExternalId}~{$target->externalId}"],
        };
    }

    private function customer(string $id): string
    {
        return (string) preg_replace('/\D/', '', $id);
    }

    /** @return array<string, string> */
    private function headers(?string $manager, string $customer): array
    {
        $login = $manager !== null && $manager !== '' ? $this->customer($manager) : $customer;

        return ['login-customer-id' => $login];
    }
}
