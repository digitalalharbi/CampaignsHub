<?php

declare(strict_types=1);

namespace App\Domains\Forms\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Forms\Conversions\ConversionKinds;
use App\Domains\Forms\Conversions\ConversionPayloads;
use App\Domains\Forms\Conversions\ConversionTransport;
use App\Domains\Forms\Jobs\DeliverConversion;
use App\Domains\Forms\Models\ConversionDelivery;
use App\Domains\Forms\Models\ConversionDestination;
use App\Domains\Forms\Models\LeadForm;
use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Projects\Context\ProjectContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * FORMS-DISPATCH-001 — the team's side of conversion delivery: where a lead's conversion is reported, whether it is
 * live, and what happened to each one.
 *
 * Reading needs `leads.view`; changing anything needs `leads.update`; both on the project the destination belongs to.
 * A secret is accepted on the way in and NEVER returned — a response says only which secrets are set. Going live is a
 * separate act with its own explicit confirmation, its own check that the secrets exist, and an audit entry: a
 * destination that is merely configured, or in test, transmits nothing.
 */
final class ConversionDestinationController extends Controller
{
    public function __construct(
        private readonly ProjectContext $context,
        private readonly ProjectAbilities $abilities,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request, string $project): JsonResponse
    {
        $this->may($request, $project, ProjectCapability::LEADS_VIEW);
        $pid = $this->projectId($project);
        $destinations = ConversionDestination::query()->where('project_id', $pid)->orderBy('created_at')->get();

        $since = Carbon::now()->subDays(7);
        $counts = ConversionDelivery::query()->where('project_id', $pid)->where('created_at', '>=', $since)
            ->selectRaw('conversion_destination_id, status, count(*) as n')->groupBy('conversion_destination_id', 'status')->get()
            ->groupBy('conversion_destination_id');
        $lastError = ConversionDelivery::query()->where('project_id', $pid)->where('status', ConversionDelivery::FAILED)
            ->orderByDesc('updated_at')->get()->unique('conversion_destination_id')->keyBy('conversion_destination_id');

        return ApiResponse::success(
            $destinations->map(fn (ConversionDestination $d): array => $this->shape($d) + [
                'last_7_days' => ($counts[$d->id] ?? collect())->pluck('n', 'status')->map(fn ($n): int => (int) $n)->all(),
                'last_error' => isset($lastError[$d->id]) ? ['error' => $lastError[$d->id]->error, 'at' => $lastError[$d->id]->updated_at?->toIso8601String()] : null,
            ])->values()->all(),
            'Conversion destinations.',
            ['kinds' => ['implemented' => ConversionKinds::IMPLEMENTED, 'planned' => ConversionKinds::PLANNED]],
        );
    }

    public function store(Request $request, string $project): JsonResponse
    {
        $this->may($request, $project, ProjectCapability::LEADS_UPDATE);
        $data = $request->validate([
            'kind' => ['required', 'string', 'max:24'],
            'name' => ['required', 'string', 'max:120'],
            'lead_form_id' => ['nullable', 'uuid'],
            'config' => ['required', 'array'],
            'secrets' => ['nullable', 'array'],
        ]);

        $config = $this->config($data['kind'], $data['config']);
        $form = $this->formOrNull($project, $data['lead_form_id'] ?? null);

        $destination = ConversionDestination::create([
            'project_id' => $this->projectId($project),
            'lead_form_id' => $form?->id,
            'kind' => $data['kind'],
            'name' => $data['name'],
            'status' => ConversionDestination::TEST,
            'config' => $config,
            'secrets' => ConversionKinds::secrets($data['kind'], (array) ($data['secrets'] ?? [])) ?: null,
            'created_by' => $request->user()?->id,
        ]);
        $this->audit->log(action: 'conversion_destination.created', entityType: ConversionDestination::class, entityId: (string) $destination->id, after: ['kind' => $destination->kind, 'status' => 'test']);

        return ApiResponse::success($this->shape($destination), 'Destination saved in test mode.', status: 201);
    }

    public function update(Request $request, string $project, string $destination): JsonResponse
    {
        $this->may($request, $project, ProjectCapability::LEADS_UPDATE);
        $d = $this->find($project, $destination);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'config' => ['sometimes', 'array'],
            'secrets' => ['sometimes', 'array'],
            'status' => ['sometimes', 'in:test,live,off'],
            'confirm_live' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['name'])) {
            $d->name = $data['name'];
        }
        if (isset($data['config'])) {
            $d->config = $this->config($d->kind, $data['config']);
        }
        if (isset($data['secrets'])) {
            // Merged: a key left blank keeps what is stored, so editing the pixel id never requires typing a token again.
            $d->secrets = array_merge((array) $d->secrets, ConversionKinds::secrets($d->kind, $data['secrets'])) ?: null;
        }

        if (isset($data['status']) && $data['status'] !== $d->status) {
            if ($data['status'] === ConversionDestination::LIVE) {
                abort_unless(($data['confirm_live'] ?? false) === true, 422, 'Going live sends real customers\' hashed identifiers to this platform; confirm it explicitly.');
                $missing = ConversionKinds::missingForLive($d->kind, $d->secrets);
                abort_if($missing !== [], 422, 'Add the missing credential before going live: '.implode(', ', $missing).'.');
                $d->went_live_at = Carbon::now();
            }
            $this->audit->log(action: 'conversion_destination.status', entityType: ConversionDestination::class, entityId: (string) $d->id, before: ['status' => $d->status], after: ['status' => $data['status']]);
            $d->status = $data['status'];
        }
        $d->save();

        return ApiResponse::success($this->shape($d->refresh()), 'Destination saved.');
    }

    public function destroy(Request $request, string $project, string $destination): JsonResponse
    {
        $this->may($request, $project, ProjectCapability::LEADS_UPDATE);
        $d = $this->find($project, $destination);
        $d->delete();
        $this->audit->log(action: 'conversion_destination.deleted', entityType: ConversionDestination::class, entityId: (string) $d->id);

        return ApiResponse::success(null, 'Destination removed.');
    }

    /**
     * Build the destination's payload from a SYNTHETIC lead and, when it is live with its credentials, send it to the
     * platform's checking interface. No stored lead is ever used here, so nothing a customer typed leaves the product.
     */
    public function diagnose(Request $request, string $project, string $destination, ConversionTransport $transport): JsonResponse
    {
        $this->may($request, $project, ProjectCapability::LEADS_UPDATE);
        $d = $this->find($project, $destination);
        $payload = ConversionPayloads::build($d, [
            'event_id' => 'diagnostic-'.bin2hex(random_bytes(6)),
            'occurred_at' => Carbon::now(),
            'email' => 'diagnostic@example.invalid',
            'phone' => '+966500000000',
            'name' => 'Diagnostic Test',
            'form_name' => 'Diagnostic',
            'session_id' => 'diagnostic-session',
            'utm' => ['utm_source' => 'diagnostic'],
            'click_ids' => [],
            'ga_client_id' => null,
            'ga_session_id' => null,
            'source_url' => null,
        ]);

        $missing = ConversionKinds::missingForLive($d->kind, $d->secrets);
        $validation = null;
        if ($d->status === ConversionDestination::LIVE && $missing === []) {
            $validation = $transport->send($d, $payload, validate: true);
        }

        return ApiResponse::success([
            'payload' => $payload,
            'synthetic' => true,
            'missing_credentials' => $missing,
            'validation' => $validation,
            'note' => $validation === null
                ? 'Nothing was sent. The payload in this response is what would be built; a destination in test mode, or without its credentials, is never contacted.'
                : 'The synthetic payload was sent to the platform\'s checking interface only.',
        ], 'Diagnostics.');
    }

    public function deliveries(Request $request, string $project): JsonResponse
    {
        $this->may($request, $project, ProjectCapability::LEADS_VIEW);
        $q = ConversionDelivery::query()->where('project_id', $this->projectId($project));
        foreach (['status' => 'status', 'destination' => 'conversion_destination_id', 'form' => 'lead_form_id'] as $param => $column) {
            if (is_string($request->query($param)) && $request->query($param) !== '') {
                $q->where($column, $request->query($param));
            }
        }

        return ApiResponse::success(
            $q->orderByDesc('created_at')->limit(50)->get()->map(fn (ConversionDelivery $x): array => [
                'id' => (string) $x->id,
                'destination_id' => (string) $x->conversion_destination_id,
                'lead_id' => $x->lead_id === null ? null : (string) $x->lead_id,
                'event_name' => $x->event_name,
                'event_id' => $x->event_id,
                'status' => $x->status,
                'attempts' => $x->attempts,
                'response_code' => $x->response_code,
                'error' => $x->error,
                'payload' => $x->payload,
                'sent_at' => $x->sent_at?->toIso8601String(),
                'next_attempt_at' => $x->next_attempt_at?->toIso8601String(),
                'created_at' => $x->created_at?->toIso8601String(),
            ])->all(),
            'Conversion deliveries.',
            ['limit' => 50],
        );
    }

    public function retry(Request $request, string $project, string $delivery): JsonResponse
    {
        $this->may($request, $project, ProjectCapability::LEADS_UPDATE);
        $x = ConversionDelivery::query()->where('project_id', $this->projectId($project))->find($delivery);
        abort_if($x === null, 404, 'Delivery not found.');
        abort_unless($x->status === ConversionDelivery::FAILED, 409, 'Only a failed delivery can be retried.');
        $d = ConversionDestination::query()->find($x->conversion_destination_id);
        abort_unless($d !== null && $d->status === ConversionDestination::LIVE, 409, 'The destination is not live.');

        $x->forceFill(['status' => ConversionDelivery::QUEUED, 'error' => null, 'response_code' => null, 'attempts' => 0, 'next_attempt_at' => null, 'created_at' => Carbon::now()])->save();
        DeliverConversion::dispatch((string) $x->id);

        return ApiResponse::success(['id' => (string) $x->id, 'status' => $x->status], 'Delivery queued again.');
    }

    /** @param array<string,mixed> $raw @return array<string,string> */
    private function config(string $kind, array $raw): array
    {
        try {
            return ConversionKinds::config($kind, $raw);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }
    }

    private function formOrNull(string $project, ?string $id): ?LeadForm
    {
        if ($id === null) {
            return null;
        }
        $form = LeadForm::query()->where('project_id', $this->projectId($project))->find($id);
        abort_if($form === null, 422, 'That form is not in this project.');

        return $form;
    }

    private function find(string $project, string $id): ConversionDestination
    {
        $d = ConversionDestination::query()->where('project_id', $this->projectId($project))->find($id);
        abort_if($d === null, 404, 'Destination not found.');

        return $d;
    }

    private function may(Request $request, string $project, string $capability): void
    {
        $user = $request->user();
        abort_unless($user !== null && $this->abilities->allows($user, $this->projectId($project), $capability), 403);
    }

    private function projectId(string $project): string
    {
        return $this->context->projectId() ?? $project;
    }

    /** @return array<string,mixed> */
    private function shape(ConversionDestination $d): array
    {
        return [
            'id' => (string) $d->id,
            'kind' => $d->kind,
            'name' => $d->name,
            'status' => $d->status,
            'lead_form_id' => $d->lead_form_id === null ? null : (string) $d->lead_form_id,
            'config' => $d->config,
            // Which secrets exist — never their values.
            'secrets_set' => array_fill_keys(array_keys((array) $d->secrets), true),
            'missing_for_live' => ConversionKinds::missingForLive($d->kind, $d->secrets),
            'went_live_at' => $d->went_live_at?->toIso8601String(),
        ];
    }
}
