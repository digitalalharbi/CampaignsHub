<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

/**
 * CREATIVE-DISPLAY-001 — which creatives a client link LISTS, as a policy rather than a frozen list.
 *
 *  - `activity` (the default for a new link): any creative with at least one real figure inside the report period — an impression, a
 *    click, spend, a result — paused or not. A creative synced tomorrow that delivers inside the period appears on the SAME link; one with
 *    nothing in the period, or active only in an older period, does not.
 *  - `all`: every creative inside the link's account scope, active or not.
 *  - `selected`: exactly the creatives the operator named (`scope.creative_ids`), whatever their activity.
 *
 * `include_zero_activity` widens `activity` to creatives the period holds no figure for. A link made before this carried no policy at all and
 * keeps its earlier reading, which is why the share reads `null` rather than a default.
 */
final class CreativeDisplay
{
    public const POLICIES = ['activity', 'all', 'selected'];

    public function __construct(
        public readonly string $policy = 'activity',
        public readonly bool $includeZeroActivity = false,
        public readonly bool $autoRefresh = true,
    ) {}

    /** @param array<string, mixed>|null $raw */
    public static function fromArray(?array $raw): ?self
    {
        if ($raw === null || $raw === []) {
            return null;
        }
        $policy = in_array($raw['policy'] ?? null, self::POLICIES, true) ? (string) $raw['policy'] : 'activity';

        return new self($policy, ($raw['include_zero_activity'] ?? false) === true, ($raw['auto_refresh'] ?? true) !== false);
    }

    /** Whether the period decides which creatives are listed. */
    public function filtersByPeriod(): bool
    {
        return $this->policy === 'activity' && ! $this->includeZeroActivity;
    }

    /** @return array{policy: string, include_zero_activity: bool, auto_refresh: bool} */
    public function toArray(): array
    {
        return ['policy' => $this->policy, 'include_zero_activity' => $this->includeZeroActivity, 'auto_refresh' => $this->autoRefresh];
    }
}
