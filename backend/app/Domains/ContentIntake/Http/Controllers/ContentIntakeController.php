<?php

declare(strict_types=1);

namespace App\Domains\ContentIntake\Http\Controllers;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativeRows;
use App\Domains\ContentIntake\Analysis\AiProviderAnalyzer;
use App\Domains\ContentIntake\Enums\IntakeStatus;
use App\Domains\ContentIntake\Models\ContentIntakeItem;
use App\Domains\ContentIntake\Services\ContentHistory;
use App\Domains\ContentIntake\Services\DriveIntakeImporter;
use App\Domains\ContentIntake\Services\IntakePreChecks;
use App\Domains\ContentIntake\Services\IntakeService;
use App\Domains\ContentIntake\Services\IntakeTransitionException;
use App\Domains\ContentIntake\Services\IntakeTransitions;
use App\Domains\Drive\Models\DriveLink;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CONTENT-INTAKE-001 — the quality gate API. Reads need `content.view` and the project in the caller's reach; writing
 * needs `content.create` / `content.update`; the decision needs `content.approve` / `content.reject` and is made only by
 * {@see IntakeTransitions}. Tenant isolation is the model's global scope (a foreign id is a 404), project isolation is
 * {@see IntakeService::constrain()}.
 */
final class ContentIntakeController extends Controller
{
    private const PAGE = 100;

    public function __construct(
        private readonly IntakeService $intake,
        private readonly IntakeTransitions $transitions,
        private readonly ContentHistory $history,
        private readonly CreativeRows $rows,
        private readonly AiProviderAnalyzer $ai,
        private readonly DriveIntakeImporter $drive,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('content.view'), 403);

        $query = ContentIntakeItem::query();
        $this->intake->constrain($query, $request->user());
        if ($request->filled('project')) {
            $project = (string) $request->query('project');
            // A malformed id is an empty answer, not a database error.
            $query->where('project_id', Str::isUuid($project) ? $project : '00000000-0000-0000-0000-000000000000');
        }
        $counts = (clone $query)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all();
        if ($request->filled('status')) {
            $query->whereIn('status', array_values(array_intersect(explode(',', (string) $request->query('status')), IntakeStatus::values())));
        }
        $total = (clone $query)->count();
        $items = $query->latest('updated_at')->limit(self::PAGE)->get();

        return ApiResponse::success(
            $items->map(fn (ContentIntakeItem $i): array => $this->present($i, $request))->all(),
            'Content intake.',
            meta: [
                'total' => $total, 'withheld' => max(0, $total - $items->count()),
                'counts' => array_map('intval', $counts),
                'ai' => ['state' => $this->ai->status()],
                'objectives' => (new IntakePreChecks)->objectives(),
                'platforms' => array_keys((array) config('content_intake.specs', [])),
                'max_upload_kb' => (int) config('content_intake.max_upload_kb'),
            ],
        );
    }

    public function show(Request $request, ContentIntakeItem $item): JsonResponse
    {
        $this->authorizeRead($request, $item);
        $data = $this->present($item, $request);
        $data['events'] = $item->events()->get()->map(static fn ($e): array => [
            'id' => $e->id, 'action' => $e->action, 'from' => $e->from_status, 'to' => $e->to_status,
            'actor' => $e->actor_name, 'reason' => $e->reason, 'notes' => $e->notes, 'item_name' => $e->item_name,
            'meta' => $e->meta, 'at' => $e->created_at?->toIso8601String(),
        ])->all();
        $data['after_launch'] = $item->status === 'live' ? $this->history->afterLaunch($item) : null;

        return ApiResponse::success($data, 'Content item.');
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('content.create'), 403);
        $data = $request->validate($this->rules(true));
        abort_unless($this->intake->reaches($request->user(), $data['project_id']), 403);
        $file = $request->file('file');
        if ($file === null && ! in_array($data['kind'] ?? 'copy', ['copy', 'link'], true)) {
            return ApiResponse::error('A file is required for image and video content.', status: 422);
        }

        $item = $this->intake->create($request->user(), $data['project_id'], $data, $file);

        return ApiResponse::success($this->present($item, $request), 'Content item created.', status: 201);
    }

    public function update(Request $request, ContentIntakeItem $item): JsonResponse
    {
        $this->authorizeRead($request, $item);
        $user = $request->user();
        abort_unless($user->hasPermission('content.update') || ((int) $item->uploaded_by === (int) $user->getKey() && $user->hasPermission('content.create')), 403);
        $data = $request->validate($this->rules(false));

        try {
            $item = $this->intake->update($item, $user, $data, $request->file('file'));
        } catch (IntakeTransitionException $e) {
            return ApiResponse::error($e->reason, ['reason' => $e->reason], status: $e->http);
        }

        return ApiResponse::success($this->present($item, $request), 'Content item updated.');
    }

    public function transition(Request $request, ContentIntakeItem $item): JsonResponse
    {
        $this->authorizeRead($request, $item);
        $data = $request->validate([
            'action' => ['required', 'string', 'in:'.implode(',', IntakeTransitions::actions())],
            'reason' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'creative_id' => ['nullable', 'uuid'],
        ]);

        try {
            $item = $this->transitions->apply($item, $data['action'], $request->user(), $data);
        } catch (IntakeTransitionException $e) {
            return ApiResponse::error($e->reason, ['reason' => $e->reason] + $e->context, status: $e->http);
        }

        return ApiResponse::success($this->present($item, $request), 'Content item moved.');
    }

    public function recheck(Request $request, ContentIntakeItem $item): JsonResponse
    {
        $this->authorizeRead($request, $item);
        abort_unless($request->user()->hasPermission('content.update') || $request->user()->hasPermission('content.create'), 403);

        return ApiResponse::success($this->present($this->intake->recheck($item, $request->user()), $request), 'Checks re-run.');
    }

    public function file(Request $request, ContentIntakeItem $item): StreamedResponse
    {
        $this->authorizeRead($request, $item);
        abort_if($item->path === null || $item->disk === null || ! Storage::disk($item->disk)->exists($item->path), 404);

        return Storage::disk($item->disk)->response($item->path, null, [
            'Content-Type' => (string) ($item->mime ?? 'application/octet-stream'),
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /** Import the project's linked Drive folder into the pipeline. Read-only on Drive; bounded per run. */
    public function driveSync(Request $request, DriveLink $link): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('drive.manage') && $request->user()->hasPermission('content.create'), 403);
        abort_unless($link->scope === 'project' && $link->scope_id !== null && $this->intake->reaches($request->user(), (string) $link->scope_id), 403);

        return ApiResponse::success($this->drive->sync($link)
            + ['last_synced_at' => $link->fresh()?->last_synced_at?->toIso8601String(), 'last_error' => $link->fresh()?->last_error], 'Drive sync.');
    }

    /**
     * The operational view: what waits on a person, what was sent back, what was approved and not launched, and the
     * live items the canonical fatigue assessment flags. Fatigue here is a VIEW signal from the same assessor the library
     * and the report use — it raises nothing and creates no second alert system.
     */
    public function followUp(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('content.view'), 403);
        $base = static fn () => ContentIntakeItem::query();
        $scoped = function () use ($base, $request) {
            $q = $base();
            $this->intake->constrain($q, $request->user());

            return $q;
        };
        $take = fn (string $status) => $scoped()->where('status', $status)->orderBy('updated_at')->limit(50)->get()
            ->map(fn (ContentIntakeItem $i): array => $this->present($i, $request))->all();

        $live = $scoped()->where('status', 'live')->whereNotNull('linked_creative_id')->orderByDesc('launched_at')->limit(100)->get();
        $signals = [];
        if ($live->isNotEmpty()) {
            $creatives = ExternalCreative::query()->whereIn('id', $live->pluck('linked_creative_id')->all())->get();
            $to = Carbon::today();
            $from = $to->copy()->subDays(29);
            $byId = collect($this->rows->present($creatives, $from, $to, true))->keyBy('id');
            foreach ($live as $item) {
                $fatigue = $byId->get($item->linked_creative_id)['fatigue'] ?? null;
                if (is_array($fatigue) && in_array($fatigue['status'] ?? null, ['watch', 'fatigued'], true)) {
                    $signals[] = ['item' => $this->present($item, $request), 'fatigue' => $fatigue];
                }
            }
        }

        return ApiResponse::success([
            'awaiting_review' => $take('in_review'),
            'needs_changes' => $take('needs_changes'),
            'rejected' => $take('rejected'),
            'approved_not_launched' => $take('approved'),
            'live_fatigue' => $signals,
        ], 'Content follow-up.');
    }

    private function authorizeRead(Request $request, ContentIntakeItem $item): void
    {
        abort_unless($request->user()?->hasPermission('content.view'), 403);
        abort_unless($this->intake->reaches($request->user(), $item->project_id), 404);
    }

    /** @return array<string, mixed> */
    private function present(ContentIntakeItem $item, Request $request): array
    {
        $arr = $item->toArray();
        $arr['has_file'] = $item->path !== null;
        $arr['available_actions'] = $this->transitions->available($item, $request->user());
        $arr['can_edit'] = in_array($item->status, ['draft', 'needs_changes'], true);

        return $arr;
    }

    /** @return array<string, mixed> */
    private function rules(bool $create): array
    {
        $kb = (int) config('content_intake.max_upload_kb', 51200);

        return [
            'project_id' => [$create ? 'required' : 'prohibited', 'uuid'],
            'name' => ['nullable', 'string', 'max:255'],
            'kind' => ['nullable', 'string', 'in:copy,link'],
            'provider' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/'],
            'objective' => ['nullable', 'string', 'in:'.implode(',', (new IntakePreChecks)->objectives())],
            'placement' => ['nullable', 'string', 'in:feed,story,reel'],
            'headline' => ['nullable', 'string', 'max:255'],
            'cta' => ['nullable', 'string', 'max:120'],
            'copy_text' => ['nullable', 'string', 'max:5000'],
            'destination_url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'file' => ['nullable', 'file', 'max:'.$kb, 'mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm'],
        ];
    }
}
