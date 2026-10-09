<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Http\Controllers;

use App\Domains\Campaigns\Management\WriteCapabilityRegistry;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CAMPAIGN-MGMT-DOMAIN-001 — what this reader may write, per provider, stated before any surface
 * draws a control.
 */
final class CampaignManagementController extends Controller
{
    public function capabilities(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null && $user->hasPermission('campaigns.view'), 403);

        $providers = [];
        foreach (WriteCapabilityRegistry::entries() as $entry) {
            $providers[$entry['provider']] ??= ['provider' => $entry['provider'], 'capabilities' => []];
            $providers[$entry['provider']]['capabilities'][] = [
                ...$entry,
                'permitted' => $user->hasPermission($entry['permission']),
                'allowed' => WriteCapabilityRegistry::allowed(
                    $entry['provider'],
                    $entry['capability'],
                    static fn (string $permission): bool => $user->hasPermission($permission),
                ),
            ];
        }

        return ApiResponse::success([
            'statuses' => [
                WriteCapabilityRegistry::NOT_IMPLEMENTED,
                WriteCapabilityRegistry::IMPLEMENTED_NOT_VERIFIED,
                WriteCapabilityRegistry::AWAITING_CREDENTIALS,
                WriteCapabilityRegistry::VERIFIED,
            ],
            'rule_ar' => 'لا يُعرض أي إجراء كتابة لمنصة ما لم تكن القدرة نفسها منفَّذة ومقيَّدة بصلاحية؛ وتُعدّ موثَّقة فقط بعد جولة كتابة حقيقية على الإنتاج.',
            'rule_en' => 'No write action is shown for a provider unless that exact capability is implemented and permission-gated; it is verified only after a real Production write round-trip.',
            'providers' => array_values($providers),
        ], 'Campaign write capabilities.');
    }
}
