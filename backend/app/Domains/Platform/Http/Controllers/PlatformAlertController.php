<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Audit\AuditLogger;
use App\Domains\Platform\Alerts\PlatformAlertPresenter;
use App\Domains\Platform\Alerts\SettlementSource;
use App\Domains\Platform\Alerts\UnavailableSettlementSource;
use App\Domains\Platform\Models\PlatformAlert;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PLATFORM-PAYMENT-ALERTS-001 — the payment and sign-up alerts, in the platform owner's «الطلبات» inbox.
 *
 * Route group `auth:sanctum` + `platform`: a workspace session of any role is refused before this runs. The rows
 * carry no owner but the platform, so there is nothing a workspace could be scoped to here.
 */
final class PlatformAlertController extends Controller
{
    /** @var array<string, RegistrationRequest> */
    private array $contacts = [];

    public function __construct(
        private readonly PlatformAlertPresenter $presenter,
        private readonly AuditLogger $audit,
        private readonly SettlementSource $settlements,
    ) {}

    /** GET /admin/platform-alerts?status=open|all */
    public function index(Request $request): JsonResponse
    {
        $ar = $request->user()?->locale !== 'en';
        $all = $request->string('status')->value() === 'all';

        $alerts = PlatformAlert::query()
            ->when(! $all, fn ($q) => $q->open())
            ->orderByDesc('last_seen_at')
            ->limit(100)
            ->get();

        $open = PlatformAlert::query()->open();
        // One read for every contact on the page, rather than one per alert.
        $ids = $alerts->map(fn (PlatformAlert $a) => $a->facts['registration_id'] ?? null)->filter(fn ($id) => is_string($id))->unique()->values()->all();
        $this->contacts = RegistrationRequest::query()->whereIn('id', $ids)->get(['id', 'email', 'phone'])->keyBy(fn (RegistrationRequest $r) => (string) $r->getKey())->all();

        return ApiResponse::success([
            'alerts' => $alerts->map(fn (PlatformAlert $a): array => $this->row($a, $ar))->all(),
            'counts' => [
                'open' => (clone $open)->count(),
                'critical' => (clone $open)->where('severity', 'critical')->count(),
                'by_kind' => (clone $open)->selectRaw('kind, count(*) as n')->groupBy('kind')->pluck('n', 'kind')->map(fn ($n): int => (int) $n)->all(),
            ],
            // Honest about the one check that cannot run: no settlement data exists here yet.
            'settlement_check' => $this->settlements->isAvailable() ? 'available' : UnavailableSettlementSource::STATE,
        ], 'Platform alerts.');
    }

    /** PATCH /admin/platform-alerts/{id} — the owner marks it handled (or reopens it). Audited. */
    public function update(Request $request, string $id): JsonResponse
    {
        $alert = PlatformAlert::query()->findOrFail($id);
        $data = $request->validate(['acknowledged' => ['required', 'boolean']]);

        $before = $alert->acknowledged_at?->toIso8601String();
        $alert->forceFill($data['acknowledged']
            ? ['acknowledged_at' => now(), 'acknowledged_by' => $request->user()?->getKey()]
            : ['acknowledged_at' => null, 'acknowledged_by' => null])->save();

        $this->audit->log('platform.alert.'.($data['acknowledged'] ? 'acknowledged' : 'reopened'), 'platform_alert', (string) $alert->getKey(),
            ['acknowledged_at' => $before], ['acknowledged_at' => $alert->acknowledged_at?->toIso8601String(), 'kind' => $alert->kind]);

        return ApiResponse::success(['alert' => $this->row($alert->refresh(), $request->user()?->locale !== 'en')], 'Platform alert updated.');
    }

    /** @return array<string,mixed> */
    private function row(PlatformAlert $alert, bool $ar): array
    {
        $facts = $alert->facts;
        $registrationId = $facts['registration_id'] ?? null;
        // The way to reach them is read live from the application, never copied into the alert.
        $contact = ! is_string($registrationId) ? null
            : ($this->contacts[$registrationId] ?? RegistrationRequest::query()->whereKey($registrationId)->first(['id', 'email', 'phone']));

        return [
            'id' => (string) $alert->getKey(),
            'kind' => $alert->kind,
            'severity' => $alert->severity,
            'card' => $this->presenter->present($alert, $ar),
            'occurrences' => (int) $alert->occurrences,
            'first_seen_at' => $alert->first_seen_at?->toIso8601String(),
            'last_seen_at' => $alert->last_seen_at?->toIso8601String(),
            'emailed_at' => $alert->emailed_at?->toIso8601String(),
            'resolved_at' => $alert->resolved_at?->toIso8601String(),
            'acknowledged_at' => $alert->acknowledged_at?->toIso8601String(),
            'contact' => $contact === null ? null : ['email' => $contact->email, 'phone' => $contact->phone],
        ];
    }
}
