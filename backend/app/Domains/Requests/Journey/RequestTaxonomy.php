<?php

declare(strict_types=1);

namespace App\Domains\Requests\Journey;

/**
 * The hierarchical request taxonomy — Module → Service → Category → Request Type — plus the controlled
 * vocabularies for objective, priority and source.
 *
 * This is a canonical (non-tenant) constant catalog with validation helpers, additive to the existing
 * request_types table. It gives the journey a professional, structured intake vocabulary without
 * rewriting the legacy per-type rows.
 */
final class RequestTaxonomy
{
    /**
     * Service → its module + the categories it offers, each category → its request types.
     *
     * @var array<string, array{module: string, categories: array<string, list<string>>}>
     */
    private const TREE = [
        'Paid Advertising Management' => [
            'module' => 'paid_media',
            'categories' => [
                'New Campaign' => ['Search Campaign', 'Social Campaign', 'Display Campaign', 'Video Campaign', 'Shopping Campaign'],
                'Existing Campaign Management' => ['Budget Adjustment', 'Creative Refresh', 'Audience Update', 'Scaling'],
                'Performance Optimization' => ['Bid Strategy', 'Conversion Rate Optimization', 'Creative Testing', 'Landing Page Review'],
                'Account Audit' => ['Full Account Audit', 'Wasted Spend Review', 'Structure Review'],
                'Tracking Setup' => ['Pixel Setup', 'Server-Side Tracking', 'Conversion API', 'Event Mapping'],
                'Reporting' => ['Performance Dashboard', 'Weekly Report', 'Monthly Report', 'Custom Report'],
                'Data Integration' => ['Platform Connection', 'CRM Integration', 'Data Warehouse Sync'],
                'Consultation' => ['Strategy Session', 'Account Review Call', 'Training'],
            ],
        ],
        'Influencer & UGC Management' => [
            'module' => 'influencer_marketing',
            'categories' => [
                'Influencer Campaign' => ['Creator Sourcing', 'Campaign Management', 'Contract & Briefing'],
                'UGC Production' => ['Content Brief', 'Content Collection', 'Content Licensing'],
                'Reporting' => ['Campaign Report', 'Creator Performance'],
                'Consultation' => ['Strategy Session', 'Creator Vetting'],
            ],
        ],
        'Analytics' => [
            'module' => 'analytics',
            'categories' => [
                'Data Analysis' => ['Performance Analysis', 'Cohort Analysis', 'Attribution Analysis'],
                'Account Audit' => ['Analytics Audit', 'Data Quality Review'],
                'Reporting' => ['Insight Report', 'Executive Summary'],
                'Consultation' => ['Analytics Strategy', 'Measurement Planning'],
            ],
        ],
        'Tracking' => [
            'module' => 'tracking',
            'categories' => [
                'Tracking Setup' => ['Pixel Setup', 'Server-Side Tracking', 'Conversion API', 'Event Mapping'],
                'Account Audit' => ['Tracking Audit', 'Data Loss Review'],
                'Consultation' => ['Tracking Strategy'],
            ],
        ],
        'Reporting' => [
            'module' => 'reporting',
            'categories' => [
                'Reporting' => ['Performance Dashboard', 'Weekly Report', 'Monthly Report', 'Custom Report'],
                'Data Integration' => ['Data Source Connection', 'Automated Reporting'],
                'Consultation' => ['Reporting Strategy'],
            ],
        ],
        'Integrations' => [
            'module' => 'integration',
            'categories' => [
                'Data Integration' => ['Platform Connection', 'CRM Integration', 'Data Warehouse Sync', 'Webhook Setup'],
                'Tracking Setup' => ['Conversion API', 'Event Mapping'],
                'Consultation' => ['Integration Planning'],
            ],
        ],
        'Consulting' => [
            'module' => 'consulting',
            'categories' => [
                'Consultation' => ['Strategy Session', 'Account Review Call', 'Training', 'Workshop'],
                'Account Audit' => ['Full Account Audit', 'Growth Opportunity Review'],
            ],
        ],
        'Custom' => [
            'module' => 'custom',
            'categories' => [
                'Custom Request' => ['Custom Scope'],
            ],
        ],
    ];

    /** @var list<string> */
    private const OBJECTIVES = [
        'sales', 'leads', 'awareness', 'traffic', 'engagement',
        'app_installs', 'retention', 'reach', 'other',
    ];

    /** @var list<string> */
    private const PRIORITIES = ['critical', 'high', 'medium', 'low'];

    /** @var list<string> */
    private const SOURCES = [
        'public_portal', 'client_portal', 'team_manual', 'referral', 'import', 'api', 'sales',
    ];

    /** @return list<string> */
    public static function services(): array
    {
        return array_keys(self::TREE);
    }

    /** @return list<string> The distinct modules referenced by the tree. */
    public static function modules(): array
    {
        return array_values(array_unique(array_map(
            static fn (array $service): string => $service['module'],
            self::TREE,
        )));
    }

    public static function moduleForService(string $service): ?string
    {
        return self::TREE[$service]['module'] ?? null;
    }

    /** @return list<string> Categories offered by a service. */
    public static function categoriesFor(string $service): array
    {
        return array_keys(self::TREE[$service]['categories'] ?? []);
    }

    /** @return list<string> Request types under a service+category. */
    public static function requestTypesFor(string $service, string $category): array
    {
        return self::TREE[$service]['categories'][$category] ?? [];
    }

    /** @return list<string> */
    public static function objectives(): array
    {
        return self::OBJECTIVES;
    }

    /** @return list<string> */
    public static function priorities(): array
    {
        return self::PRIORITIES;
    }

    /** @return list<string> */
    public static function sources(): array
    {
        return self::SOURCES;
    }

    public static function isValidService(string $service): bool
    {
        return isset(self::TREE[$service]);
    }

    public static function isValidCategory(string $service, string $category): bool
    {
        return isset(self::TREE[$service]['categories'][$category]);
    }

    public static function isValidRequestType(string $service, string $category, string $requestType): bool
    {
        return in_array($requestType, self::requestTypesFor($service, $category), true);
    }

    /** Validate a full Service → Category → Request Type path in one call. */
    public static function isValidPath(string $service, string $category, string $requestType): bool
    {
        return self::isValidService($service)
            && self::isValidCategory($service, $category)
            && self::isValidRequestType($service, $category, $requestType);
    }

    public static function isValidObjective(string $objective): bool
    {
        return in_array($objective, self::OBJECTIVES, true);
    }

    public static function isValidPriority(string $priority): bool
    {
        return in_array($priority, self::PRIORITIES, true);
    }

    public static function isValidSource(string $source): bool
    {
        return in_array($source, self::SOURCES, true);
    }

    /**
     * The full tree, for API/meta responses.
     *
     * @return array<string, array{module: string, categories: array<string, list<string>>}>
     */
    public static function tree(): array
    {
        return self::TREE;
    }
}
