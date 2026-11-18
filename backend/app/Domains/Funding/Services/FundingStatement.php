<?php

declare(strict_types=1);

namespace App\Domains\Funding\Services;

use App\Domains\Branding\Services\SharedLinkBranding;
use App\Domains\Funding\Support\FundingDisclaimer;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * BUDGET-STATEMENT-001 — the identity block of «البيان المالي»: who issued it, about whom, for which period, in which
 * currency, when and by whom — for the PDF, and the first rows of the XLSX.
 *
 * Only what exists is shown. The workspace holds a name and (optionally) a logo, resolved by the same branding source the
 * shared reports use; it holds no address, commercial registration, VAT number, e-mail or phone, so none is printed and
 * none is invented. The client is the one the statement is about when it is limited to one, else its count.
 */
final class FundingStatement
{
    public function __construct(private readonly SharedLinkBranding $branding) {}

    /**
     * @param  list<array<string, mixed>>  $rows  client + project + FundingBalance::of()
     * @return array<string, mixed>
     */
    public function block(User $user, array $rows, string $period, string $lang): array
    {
        $lang = $lang === 'en' ? 'en' : 'ar';
        $tenantId = (string) app(TenantContext::class)->tenantId();
        $tenant = Tenant::withoutGlobalScopes()->find($tenantId);

        $clients = array_values(array_unique(array_map(static fn (array $r): string => (string) $r['client'], $rows)));
        $projects = array_values(array_unique(array_map(static fn (array $r): string => (string) $r['project'], $rows)));
        $one = count($rows) === 1 ? $rows[0] : null;

        return [
            'lang' => $lang,
            'title' => $lang === 'ar' ? 'البيان المالي' : 'Financial statement',
            'agency' => ['name' => (string) ($tenant->name ?? ''), 'logo' => $this->logo($tenantId)],
            'client' => count($clients) === 1 ? $clients[0] : null,
            'clients' => count($clients),
            'project' => count($projects) === 1 ? $projects[0] : null,
            'projects' => count($projects),
            'mode' => $one['payment_mode'] ?? null,
            'budget_period' => $one === null ? null : [$one['period_start'], $one['period_end']],
            'period' => $period,
            'currency' => 'SAR',
            'fx_note' => FundingDisclaimer::fxNote($lang),
            'generated_at' => Carbon::now('Asia/Riyadh')->format('Y-m-d H:i'),
            'generated_by' => (string) $user->name,
            'disclaimer' => FundingDisclaimer::text($lang),
        ];
    }

    /** The agency's mark as a data URI (the page is rendered with no network), or null: no logo is invented. */
    private function logo(string $tenantId): ?string
    {
        try {
            $bytes = $this->branding->logoBytes(null, $tenantId, 'agency');
        } catch (Throwable) {
            return null;
        }
        if ($bytes === null) {
            return null;
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'], true) ? 'data:'.$mime.';base64,'.base64_encode($bytes) : null;
    }
}
