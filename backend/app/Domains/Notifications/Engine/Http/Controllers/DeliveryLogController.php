<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Http\Controllers;

use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The proof of notice (spec §4): the workspace's delivery log and subscription log, read-only, plus the CSV export.
 * There is no write route — the tables refuse UPDATE and DELETE at the database level as well. Only a member who may
 * manage settings reads them; the tenant is the membership's, never a request parameter.
 */
final class DeliveryLogController extends Controller
{
    private const COLUMNS = [
        'id', 'delivery_id', 'reference', 'event_key', 'scope', 'channel', 'recipient_kind', 'recipient_address', 'project_id',
        'project_name', 'workspace_name', 'status', 'reason', 'content_version', 'attempt', 'provider_message_id',
        'provider_accepted_at', 'created_at',
    ];

    public function __construct(private readonly TenantContext $tenants) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $page = $this->query($request)->orderByDesc('seq')->paginate(min(100, max(10, (int) $request->query('per_page', 50))), self::COLUMNS);

        return ApiResponse::success($page->items(), meta: ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorizeManage($request);
        $query = $this->query($request)->orderBy('seq');

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF"); // so a spreadsheet opens the Arabic names correctly
            fputcsv($out, self::COLUMNS);
            $query->select(self::COLUMNS)->chunk(1000, function ($rows) use ($out): void {
                foreach ($rows as $row) {
                    fputcsv($out, array_map(static fn (string $c) => self::cell($row->{$c}), self::COLUMNS));
                }
            });
            fclose($out);
        }, 'notification-log-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function subscriptions(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $page = DB::table('notification_subscription_log')->where('tenant_id', (string) $this->tenants->tenantId())
            ->when($request->query('recipient_id'), fn (Builder $q, $v) => $q->where('recipient_id', (string) $v))
            ->orderByDesc('seq')->paginate(50, ['id', 'recipient_id', 'subscription_id', 'action', 'detail', 'actor_label', 'created_at']);

        return ApiResponse::success($page->items(), meta: ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()]);
    }

    private function query(Request $request): Builder
    {
        return DB::table('notification_delivery_log')->where('tenant_id', (string) $this->tenants->tenantId())
            ->when($request->query('status'), fn (Builder $q, $v) => $q->where('status', (string) $v))
            ->when($request->query('scope'), fn (Builder $q, $v) => $q->where('scope', (string) $v))
            ->when($request->query('channel'), fn (Builder $q, $v) => $q->where('channel', (string) $v))
            ->when($request->query('reference'), fn (Builder $q, $v) => $q->where('reference', (string) $v))
            ->when($request->query('from'), fn (Builder $q, $v) => $q->where('created_at', '>=', (string) $v))
            ->when($request->query('to'), fn (Builder $q, $v) => $q->where('created_at', '<=', (string) $v.' 23:59:59'));
    }

    /** A cell that a spreadsheet would run as a formula is neutralised (CSV injection). */
    private static function cell(mixed $value): string
    {
        $s = (string) ($value ?? '');

        return $s !== '' && in_array($s[0], ['=', '+', '-', '@'], true) ? "'".$s : $s;
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()?->hasPermission('settings.manage'), 403);
    }
}
