<?php

declare(strict_types=1);

namespace App\Domains\Forms\Http\Controllers;

use App\Domains\CRM\Models\Lead;
use App\Domains\Forms\Models\LeadForm;
use App\Domains\Forms\Services\FormDataRetention;
use App\Domains\Forms\Services\ReferralCodes;
use App\Domains\Projects\Context\ProjectContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\Frontend;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FORMS-PDPL-001 — a data subject's erasure of a lead that came through a form.
 *
 * It needs the capability to work the project's leads (the route) AND the tenant's `leads.delete`: erasure is the one act here
 * that cannot be undone, so it is held to the permission that already guards deleting a lead. An explicit `confirm` is required.
 * See {@see FormDataRetention} for what goes and what stays.
 */
final class FormDataController extends Controller
{
    public function __construct(private readonly ProjectContext $context, private readonly FormDataRetention $retention) {}

    /**
     * FORMS-REFERRALS-001 — the link a lead can pass on: the form's public address with a signed code naming THIS lead.
     * Reading it needs the capability to work the project's leads; the code carries an opaque id, no personal data.
     */
    public function referralLink(Request $request, string $project, string $lead): JsonResponse
    {
        $data = $request->validate(['form' => ['required', 'uuid']]);
        $pid = $this->context->projectId() ?? $project;
        $model = Lead::query()->where('project_id', $pid)->find($lead);
        $form = LeadForm::query()->where('project_id', $pid)->where('status', LeadForm::PUBLISHED)->find($data['form']);
        abort_if($model === null || $form === null, 404, 'Lead or published form not found.');

        return ApiResponse::success(['url' => Frontend::url('/f/'.$form->slug.'?ref='.ReferralCodes::issue($model, (string) $form->id))], 'Referral link.');
    }

    public function erase(Request $request, string $project, string $lead): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('leads.delete'), 403);
        $request->validate(['confirm' => ['required', 'accepted']]);

        $model = Lead::query()->where('project_id', $this->context->projectId() ?? $project)->find($lead);
        abort_if($model === null, 404, 'Lead not found.');

        return ApiResponse::success($this->retention->eraseLead($model, $request->user()), 'Lead erased. The consent record was kept.');
    }
}
