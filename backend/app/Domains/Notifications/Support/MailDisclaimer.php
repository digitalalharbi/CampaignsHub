<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

use App\Domains\Disclaimers\Services\DisclaimerResolver;
use App\Domains\Tenancy\Context\TenantContext;
use Throwable;

/**
 * DIGESTS-OBJECTIVE-KPIS-001 — the closing note of every operational email, from the central disclaimer mechanism.
 *
 * One short line (`config/disclaimers.php` → `operational`), resolved with the same priority every other disclaimer follows:
 * project → client → organization → system, so an override still wins. It sits in the footer's own note cell, MERGED with whatever
 * results/freshness line the mail already carries — one compact box, not two. If the resolver cannot answer (no tenant in hand,
 * the table unreachable) the system default is used: the note is never silently absent.
 */
final class MailDisclaimer
{
    /** @param  string|null  $also  a results/freshness line the mail already carries; it is kept, joined to the note */
    public static function note(string $lang, ?string $also = null, ?string $clientId = null, ?string $projectId = null): string
    {
        $locale = $lang === 'en' ? 'en' : 'ar';
        $text = null;
        try {
            $tenant = app(TenantContext::class);
            $r = app(DisclaimerResolver::class)->resolve($tenant->hasTenant() ? (string) $tenant->tenantId() : '', $clientId, $projectId);
            if (($r['enabled']['operational'] ?? true) !== true) {
                return trim((string) $also);
            }
            $node = $r['sections']['operational'] ?? null;
            $text = is_array($node) ? ($node[$locale] ?? $node['ar'] ?? null) : null;
        } catch (Throwable) {
            $text = null;
        }
        $text ??= (string) config('disclaimers.sections.operational.'.$locale, '');

        return trim($text.' '.trim((string) $also));
    }
}
