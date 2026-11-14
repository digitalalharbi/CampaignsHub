<?php

declare(strict_types=1);

namespace App\Domains\Funding\Services;

use App\Domains\Audit\AuditLogger;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\ClientWorkspaces\Services\ClientAccess;
use App\Domains\Funding\Models\FundingPayment;
use App\Domains\Funding\Models\ProjectFunding;
use App\Domains\Metrics\Services\CurrencyConverter;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * FIN-LOOP-001 — finance adds a budget in ONE step, and the loop starts from it.
 *
 * The client and the project may already exist or not. Either is reused when it does — by id, or by the
 * same name (trimmed, case-insensitive) — and created once when it does not, under an advisory lock so
 * two clicks cannot make two (F04). The amount is then appended to the project's record:
 *
 *  - the same `idempotency_key` twice returns the first row and adds nothing (F06: retry, double click);
 *  - the project's budget row is locked while the amount is added, so two renewals at once are both
 *    counted and neither is lost;
 *  - the remaining before and after are stored with the row, as the evidence of what the renewal did;
 *  - a currency other than SAR needs a dated rate; without one the amount is refused, not guessed.
 */
final class AddFundingPayment
{
    public function __construct(
        private readonly ClientAccess $access,
        private readonly CurrencyConverter $fx,
        private readonly FundingBalance $balance,
        private readonly FundingSpendRefresher $refresher,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
        private readonly FundingReach $reach,
    ) {}

    /**
     * @param  array{client_workspace_id?: string|null, client_name?: string|null, project_id?: string|null, project_name?: string|null, amount: float|int|string, currency?: string|null, paid_on: string, reference?: string|null, note?: string|null, idempotency_key: string}  $input
     * @return array{payment: FundingPayment, created: bool}
     */
    public function __invoke(User $actor, array $input, bool $retried = false): array
    {
        $tenantId = $this->tenant->tenantId();
        $currency = strtoupper((string) ($input['currency'] ?? 'SAR'));
        $paidOn = Carbon::parse($input['paid_on']);
        $amount = round((float) $input['amount'], 2);

        $rate = $this->fx->resolve($currency, 'SAR', $paidOn);
        if ($rate === null) {
            throw ValidationException::withMessages(['currency' => [__('funding.no_rate', ['currency' => $currency])]]);
        }

        try {
            return DB::transaction(function () use ($actor, $input, $tenantId, $currency, $paidOn, $amount, $rate): array {
                $client = $this->client($actor, $input, $tenantId);
                $project = $this->project($actor, $input, $client, $tenantId);
                $ceilings = $this->access->projectCeilings($actor);
                abort_unless($this->access->projectAllowed($ceilings, (string) $client->id, (string) $project->id)
                    && ! $this->reach->moneyWithheld($actor, (string) $project->id), 404);

                DB::select('select pg_advisory_xact_lock(hashtext(?))', ['funding:'.$project->id]);
                $funding = ProjectFunding::query()->firstOrCreate(
                    ['project_id' => $project->id],
                    ['tenant_id' => $tenantId, 'client_workspace_id' => $client->id, 'payment_method' => 'prepaid', 'created_by' => $actor->id],
                );

                $existing = FundingPayment::query()->where('project_id', $project->id)
                    ->where('idempotency_key', $input['idempotency_key'])->first();
                if ($existing !== null) {
                    return ['payment' => $existing, 'created' => false];
                }

                $isFirst = ! FundingPayment::query()->where('project_id', $project->id)->exists();
                if (! $isFirst) {
                    $this->refresher->refresh($funding);
                }
                $before = $isFirst ? null : $this->balance->of($funding)['remaining'];
                $amountSar = round($amount * $rate['rate'], 2);

                $payment = FundingPayment::query()->create([
                    'tenant_id' => $tenantId,
                    'client_workspace_id' => $client->id,
                    'project_id' => $project->id,
                    'kind' => $isFirst ? 'initial' : 'top_up',
                    'amount' => $amount,
                    'currency' => $currency,
                    'amount_sar' => $amountSar,
                    'rate' => $rate['rate'],
                    'rate_date' => $rate['rate_date'],
                    'rate_source' => $rate['source'],
                    'paid_on' => $paidOn->toDateString(),
                    'reference' => $input['reference'] ?? null,
                    'note' => $input['note'] ?? null,
                    'remaining_before' => $before,
                    'remaining_after' => null,
                    'idempotency_key' => $input['idempotency_key'],
                    'created_by' => $actor->id,
                ]);

                // The amount may move the first counted day (the first one, or one dated earlier), so spend is
                // read again before the remaining after it is known. That figure is written once, here, inside
                // the same transaction — the only write a payment row ever receives after insert.
                $this->refresher->refresh($funding);
                $after = $this->balance->of($funding)['remaining'];
                DB::table('funding_payments')->where('id', $payment->id)->update(['remaining_after' => $after]);
                $payment->setRawAttributes(array_merge($payment->getAttributes(), ['remaining_after' => $after]), true);

                $this->audit->log(
                    action: 'funding.payment.added',
                    entityType: FundingPayment::class,
                    entityId: (string) $payment->id,
                    before: ['remaining' => $before],
                    after: [
                        'project_id' => $project->id, 'kind' => $payment->kind, 'amount' => $amount, 'currency' => $currency,
                        'amount_sar' => $amountSar, 'paid_on' => $paidOn->toDateString(), 'reference' => $payment->reference,
                        'remaining' => $after,
                    ],
                    userId: $actor->id,
                    tenantId: $tenantId,
                );

                return ['payment' => $payment, 'created' => true];
            });
        } catch (UniqueConstraintViolationException $e) {
            // Only the idempotency key is an expected collision: a concurrent request with the same key won.
            // Run once more — the transaction rolled back, so the client and project are resolved again under
            // the same checks, and the short-circuit above answers with that request's row.
            if ($retried || ! str_contains($e->getMessage(), 'funding_payments_project_id_idempotency_key_unique')) {
                throw $e;
            }

            return $this($actor, $input, true);
        }
    }

    /** @param array<string, mixed> $input */
    private function client(User $actor, array $input, string $tenantId): ClientWorkspace
    {
        if (! empty($input['client_workspace_id'])) {
            $client = ClientWorkspace::query()->find($input['client_workspace_id']);
            if ($client === null || ! $this->access->canAccessClient($actor, $client)) {
                throw ValidationException::withMessages(['client_workspace_id' => [__('funding.client_taken')]]);
            }

            return $client;
        }

        $name = trim((string) ($input['client_name'] ?? ''));
        DB::select('select pg_advisory_xact_lock(hashtext(?))', ['funding-client:'.$tenantId.':'.mb_strtolower($name)]);

        $same = ClientWorkspace::query()->whereNull('archived_at')
            ->whereRaw('lower(trim(name)) = ?', [mb_strtolower($name)])->first();
        if ($same !== null) {
            // The same name is the same client — reused, never doubled. One outside the user's reach gets
            // the same answer as an unknown id («choose the client from the list»).
            if (! $this->access->canAccessClient($actor, $same)) {
                throw ValidationException::withMessages(['client_name' => [__('funding.client_taken')]]);
            }

            return $same;
        }

        // A new client is a new client whoever adds its first budget: the user must be allowed to create one.
        if (! $actor->hasPermission('clients.create')) {
            throw ValidationException::withMessages(['client_name' => [__('funding.client_taken')]]);
        }

        $client = ClientWorkspace::query()->create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'slug' => (Str::slug($name) ?: 'client').'-'.Str::lower(Str::random(5)),
            'mode' => 'managed',
            'status' => 'active',
            'client_status' => 'active',
            'client_source' => 'finance',
            'owner_id' => $actor->id,
        ]);
        $this->audit->log(action: 'client.created', entityType: ClientWorkspace::class, entityId: (string) $client->id,
            after: ['name' => $name, 'source' => 'funding'], userId: $actor->id, tenantId: $tenantId);

        return $client;
    }

    /** @param array<string, mixed> $input */
    private function project(User $actor, array $input, ClientWorkspace $client, string $tenantId): Project
    {
        if (! empty($input['project_id'])) {
            $project = Project::query()->where('client_workspace_id', $client->id)->find($input['project_id']);
            if ($project === null) {
                throw ValidationException::withMessages(['project_id' => [__('validation.exists', ['attribute' => 'project'])]]);
            }

            return $project;
        }

        $name = trim((string) ($input['project_name'] ?? ''));
        DB::select('select pg_advisory_xact_lock(hashtext(?))', ['funding-project:'.$client->id.':'.mb_strtolower($name)]);

        $same = Project::query()->where('client_workspace_id', $client->id)
            ->whereRaw('lower(trim(name)) = ?', [mb_strtolower($name)])->first();
        if ($same !== null) {
            return $same; // reach and money are checked by the caller for a found project as for a named one
        }

        $project = Project::query()->create([
            'tenant_id' => $tenantId,
            'client_workspace_id' => $client->id,
            'name' => $name,
            'status' => 'active',
            'setup_completion' => 0,
        ]);
        $this->audit->log(action: 'project.created', entityType: Project::class, entityId: (string) $project->id,
            after: ['name' => $name, 'source' => 'funding'], userId: $actor->id, tenantId: $tenantId);

        return $project;
    }
}
