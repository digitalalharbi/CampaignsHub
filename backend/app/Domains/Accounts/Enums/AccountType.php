<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Enums;

/**
 * The kind of account a workspace is. It decides the navigation persona:
 *  - PERSONAL (freelancer / agency / in-house team) → the full operator menu (clients, projects, requests…);
 *  - COMPANY (brand / self-serve company) → a simplified menu (their own campaigns/analytics/reports only).
 */
enum AccountType: string
{
    case Freelancer = 'freelancer';
    case Agency = 'agency';
    case InHouseTeam = 'in_house_team';
    case Brand = 'brand';
    case SelfServeCompany = 'self_serve_company';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** personal = full agency tooling; company = simplified self-serve menu. */
    public function workspaceKind(): string
    {
        return match ($this) {
            self::Brand, self::SelfServeCompany => 'company',
            default => 'personal',
        };
    }
}
