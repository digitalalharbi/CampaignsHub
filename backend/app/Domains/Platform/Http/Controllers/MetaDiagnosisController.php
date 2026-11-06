<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Integrations\Services\MetaConnectionDiagnosis;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/** META-DIAGNOSIS-001 — GET /api/v1/admin/integrations/meta/diagnosis. Reads recorded facts and calls nothing. */
final class MetaDiagnosisController extends Controller
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function __invoke(MetaConnectionDiagnosis $diagnosis): JsonResponse
    {
        $this->tenants->enterPlatformScope();

        return ApiResponse::success(['connections' => $diagnosis->connections()], 'Meta connection diagnosis.');
    }
}
