<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Http\Controllers;

use App\Domains\Access\Support\WorkspaceRolePresets;
use App\Domains\Notifications\Engine\Consent\ConfirmationRequests;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * «أرسل طلبات التأكيد» (spec §9 migration rule): a count preview, then ONE explicit send by the workspace owner, who must
 * repeat the previewed count so a list that grew in between is not mailed by surprise. Plus internal approval for an
 * org-domain address, and the public confirmation page (GET shows, POST confirms — link scanners fetch every URL).
 */
final class ConsentController extends Controller
{
    public function __construct(private readonly ConfirmationRequests $consent, private readonly TenantContext $tenants) {}

    public function preview(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('settings.manage'), 403);

        return ApiResponse::success($this->consent->preview((string) $this->tenants->tenantId()));
    }

    public function send(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null && ($user->hasRole(WorkspaceRolePresets::OWNER) || (bool) $user->is_platform_admin), 403);
        $expected = (int) $request->validate(['expected' => ['required', 'integer', 'min:1']])['expected'];
        $tenantId = (string) $this->tenants->tenantId();

        $preview = $this->consent->preview($tenantId);
        if ($preview['recipients'] !== $expected) {
            return ApiResponse::error(null, ['expected' => ['The number of recipients changed; preview again.']], ['preview' => $preview], 409);
        }

        return ApiResponse::success($this->consent->send($tenantId, (int) $user->getKey()));
    }

    public function approveInternally(Request $request, string $recipient): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('settings.manage'), 403);
        $ok = $this->consent->approveInternally((string) $this->tenants->tenantId(), $recipient, (int) $request->user()->getKey());

        return $ok ? ApiResponse::success(['approved' => true])
            : ApiResponse::error(null, ['recipient' => ['Only a pending address on the workspace domains, outside the client role, can be approved internally.']], status: 422);
    }

    public function showConfirm(Request $request, string $token): Response
    {
        return $this->page($request, 'ask', $token);
    }

    public function confirm(Request $request, string $token): Response
    {
        return $this->page($request, $this->consent->confirm($token) !== null ? 'done' : 'invalid', $token);
    }

    private function page(Request $request, string $state, string $token): Response
    {
        $ar = $request->query('lang') !== 'en';
        $t = $ar
            ? ['ask' => 'لتأكيد استلام التقارير اضغط الزر.', 'button' => 'تأكيد', 'done' => 'تم التأكيد. ستصلك التقارير حسب الجدول.', 'invalid' => 'هذا الرابط غير صالح أو سبق استخدامه.']
            : ['ask' => 'Press the button to confirm receiving the reports.', 'button' => 'Confirm', 'done' => 'Confirmed. The reports will reach you on schedule.', 'invalid' => 'This link is not valid or was already used.'];
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $form = $state === 'ask'
            ? '<form method="post" action="'.$e(url('/n/confirm/'.$token)).'"><button type="submit">'.$e($t['button']).'</button></form>'
            : '';
        $html = '<!doctype html><html lang="'.($ar ? 'ar' : 'en').'" dir="'.($ar ? 'rtl' : 'ltr').'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>CampaignsHub</title></head>'
            .'<body style="font-family:sans-serif;max-width:480px;margin:40px auto;padding:0 16px"><p>'.$e($t[$state]).'</p>'.$form.'</body></html>';

        return response($html, $state === 'invalid' ? 404 : 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
