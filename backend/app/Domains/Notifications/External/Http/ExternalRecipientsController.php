<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Http;

use App\Domains\Notifications\Engine\Contracts\EntitlementGate;
use App\Domains\Notifications\Engine\Contracts\WorkspaceTypes;
use App\Domains\Notifications\Engine\Enums\EntitlementKey;
use App\Domains\Notifications\Engine\Scheduling\DigestPeriods;
use App\Domains\Notifications\External\Recipients\ExternalRecipients;
use App\Domains\Notifications\External\Recipients\ExternalSettings;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * NOTIF-V2-EXTERNAL-001 — the external section's JSON API for builder R's settings tabs and wizard (spec §9, §14).
 * Tenant from the membership (TenantContext); every read and write is scoped to it. `settings.manage` for writes and
 * reads alike (recipient addresses are personal data). Entitlements are enforced again inside the service.
 */
final class ExternalRecipientsController extends Controller
{
    public function __construct(
        private readonly ExternalRecipients $recipients,
        private readonly ExternalSettings $settings,
        private readonly TenantContext $tenants,
    ) {}

    public function options(Request $request, WorkspaceTypes $types, EntitlementGate $gate): JsonResponse
    {
        $tenant = $this->authorize($request);
        $profile = $types->profileFor($tenant);
        $locale = $request->query('lang') === 'en' ? 'en' : 'ar';
        $ent = [];
        foreach ([EntitlementKey::External, EntitlementKey::ExternalCharts, EntitlementKey::ExternalCcBcc, EntitlementKey::ExternalCustomSubject, EntitlementKey::ExternalReviewMode, EntitlementKey::RemovePlatformFootprint] as $key) {
            $ent[$key->value] = $gate->state($tenant, $key)->mode->value;
        }

        return ApiResponse::success([
            'workspace_type' => $profile->key,
            'roles' => array_map(static fn (string $r): array => ['key' => $r, 'label' => (string) __("notifications_v2.roles.{$r}", [], $locale), 'is_client_role' => $profile->isClientRole($r)], $profile->roles),
            'responsible_role' => $profile->responsibleRole,
            'terms' => [
                'client' => (string) __((string) ($profile->terminology['client'] ?? ''), [], $locale),
                'project' => (string) __((string) ($profile->terminology['project'] ?? ''), [], $locale),
            ],
            'digest_types' => ExternalRecipients::DIGEST_TYPES,
            'event_keys' => $this->recipients->externalEventKeys(),
            'periods' => DigestPeriods::KEYS,
            'frequencies' => ExternalRecipients::FREQUENCIES,
            'period_rules' => DigestPeriods::RULES,
            'entitlements' => $ent,
            'limits' => ['external_recipients' => $gate->limit($tenant, 'external_recipients'), 'cc_per_message' => $gate->limit($tenant, 'cc_per_message'), 'monthly_messages' => $gate->limit($tenant, 'monthly_messages')],
            'org_domains' => $this->settings->workspace($tenant)->orgDomains,
        ]);
    }

    public function periodCheck(Request $request): JsonResponse
    {
        $this->authorize($request);
        $data = $request->validate(['period_key' => ['required', 'string'], 'frequency' => ['required', 'string']]);

        return ApiResponse::success(DigestPeriods::check((string) $data['period_key'], (string) $data['frequency']));
    }

    public function kpis(Request $request): JsonResponse
    {
        return ApiResponse::success($this->recipients->kpis($this->authorize($request)));
    }

    public function index(Request $request): JsonResponse
    {
        $tenant = $this->authorize($request);
        $status = $request->query('status');

        return ApiResponse::success($this->recipients->list($tenant, is_string($status) && in_array($status, ['pending', 'active', 'paused', 'cancelled'], true) ? $status : null));
    }

    public function show(Request $request, string $recipient): JsonResponse
    {
        return ApiResponse::success($this->recipients->show($this->authorize($request), $recipient));
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = $this->authorize($request);

        return ApiResponse::success($this->recipients->create($tenant, (int) $request->user()->getKey(), $request->all()), status: 201);
    }

    public function update(Request $request, string $recipient): JsonResponse
    {
        return ApiResponse::success($this->recipients->updateRecipient($this->authorize($request), $recipient, $request->all()));
    }

    public function recipientAction(Request $request, string $recipient, string $action): JsonResponse
    {
        $tenant = $this->authorize($request);
        if ($action === 'cancel') {
            $request->validate(['confirm' => ['accepted']]);
        }

        return ApiResponse::success($this->recipients->setRecipientStatus($tenant, $recipient, $action, (int) $request->user()->getKey()));
    }

    public function storeSubscription(Request $request, string $recipient): JsonResponse
    {
        $tenant = $this->authorize($request);

        return ApiResponse::success($this->recipients->addSubscription($tenant, $recipient, (int) $request->user()->getKey(), $request->all()), status: 201);
    }

    public function updateSubscription(Request $request, string $subscription): JsonResponse
    {
        $tenant = $this->authorize($request);

        return ApiResponse::success($this->recipients->updateSubscription($tenant, $subscription, (int) $request->user()->getKey(), $request->all()));
    }

    public function subscriptionAction(Request $request, string $subscription, string $action): JsonResponse
    {
        $tenant = $this->authorize($request);
        $actor = (int) $request->user()->getKey();
        if ($action === 'duplicate') {
            return ApiResponse::success($this->recipients->duplicateSubscription($tenant, $subscription, $actor), status: 201);
        }
        if ($action === 'cancel') {
            $request->validate(['confirm' => ['accepted']]);
        }

        return ApiResponse::success($this->recipients->setSubscriptionStatus($tenant, $subscription, $action, $actor));
    }

    public function showSettings(Request $request): JsonResponse
    {
        return ApiResponse::success($this->settings->for($this->authorize($request)));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $tenant = $this->authorize($request);
        $data = $request->validate([
            'sender' => ['sometimes', 'array'],
            'sender.reply_to' => ['nullable', 'email:rfc'],
            'sender.signature' => ['nullable', 'string', 'max:500'],
            'external' => ['sometimes', 'array'],
            'external.contact_link' => ['nullable', 'url:https', 'max:300'],
            'external.forbidden_words' => ['sometimes', 'array', 'max:200'],
            'external.forbidden_words.*' => ['string', 'max:60'],
            'external.subject_templates' => ['sometimes', 'array'],
            'external.continuity_days' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'external.improvement_pct' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'external.copy_delivery' => ['sometimes', 'in:all,events,digest'],
            'external.review_window_hours' => ['sometimes', 'integer', 'min:1', 'max:72'],
            'external.display_currency' => ['nullable', 'string', 'size:3'],
            'external.disclaimer' => ['sometimes', 'array'],
            'external.disclaimer.ar' => ['nullable', 'string', 'max:1000'],
            'external.disclaimer.en' => ['nullable', 'string', 'max:1000'],
        ]);

        return ApiResponse::success($this->settings->update($tenant, $data));
    }

    private function authorize(Request $request): string
    {
        abort_unless($request->user()?->hasPermission('settings.manage'), 403);

        return (string) $this->tenants->tenantId();
    }
}
