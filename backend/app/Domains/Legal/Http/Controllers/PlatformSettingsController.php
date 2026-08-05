<?php

declare(strict_types=1);

namespace App\Domains\Legal\Http\Controllers;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Legal\Models\PlatformSetting;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LEGAL-001 — the platform operator's own legal details, edited from `/admin`.
 *
 * Behind the `platform` gate with everything else on that file: this is the identity that appears on
 * a published privacy policy, and a tenant administrator changing who the data controller is would be
 * a considerable problem.
 *
 * Every change is audited by FIELD NAME. Not the values: an address and a registration number are
 * ordinary business facts rather than secrets, but the audit trail's job here is «who changed the
 * published legal identity, and when» — and a log that copied each value would quietly become a
 * second, unmanaged store of the same details.
 */
final class PlatformSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $settings = PlatformSetting::current();

        return ApiResponse::success([
            ...$settings->toPublicArray(),
            'dpo_email' => $settings->dpo_email,
            'updated_at' => $settings->updated_at?->toIso8601String(),
            /*
             * What is still missing, named.
             *
             * The operator's real question on this screen is «is my privacy policy publishable yet»,
             * and answering it by making them compare a form against a policy is how a field stays
             * empty for a year. Nothing is invented to fill these — they are simply listed.
             */
            'missing' => $settings->isPublished() ? [] : ['legal_name'],
        ], 'Platform settings.');
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'legal_name_ar' => ['nullable', 'string', 'max:200'],
            'legal_name_en' => ['nullable', 'string', 'max:200'],
            'trading_name' => ['nullable', 'string', 'max:200'],
            'registration_number' => ['nullable', 'string', 'max:80'],
            'tax_number' => ['nullable', 'string', 'max:80'],
            'jurisdiction' => ['nullable', 'string', 'max:120'],
            'address_ar' => ['nullable', 'string', 'max:400'],
            'address_en' => ['nullable', 'string', 'max:400'],
            // The contact address is the one field with no null: it is printed on every policy page
            // as the way to reach the operator, and a policy with no contact is not one.
            'contact_email' => ['required', 'email', 'max:160'],
            'support_email' => ['nullable', 'email', 'max:160'],
            'security_email' => ['nullable', 'email', 'max:160'],
            'privacy_email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'dpo_name' => ['nullable', 'string', 'max:160'],
            'dpo_email' => ['nullable', 'email', 'max:160'],
        ]);

        $settings = PlatformSetting::current();

        // Which fields actually moved — an audit entry saying "settings updated" on a no-op save is
        // noise that makes the entries which matter harder to find.
        $changed = array_keys(array_filter(
            $data,
            static fn (mixed $value, string $key): bool => ($settings->{$key} ?? null) !== $value,
            ARRAY_FILTER_USE_BOTH,
        ));

        $settings->fill([...$data, 'updated_by' => $request->user()?->id])->save();

        if ($changed !== []) {
            AuditLog::create([
                'tenant_id' => null,
                'user_id' => $request->user()?->id,
                'action' => 'platform.settings.updated',
                'entity_type' => PlatformSetting::class,
                'entity_id' => (string) $settings->getKey(),
                // Field NAMES, not values — see the class note.
                'after' => ['fields' => $changed],
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
            ]);
        }

        return $this->show();
    }
}
