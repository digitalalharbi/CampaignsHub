<?php

declare(strict_types=1);

return [
    /*
     * AGENTS-APPROVAL-GATEWAY-001 — limits that live in code, not in a prompt.
     *
     * A project may carry its own in `projects.meta.agent_limits`; these are what applies when it does not.
     */
    'limits' => [
        // The largest single raise of a daily budget, as a percentage of the current one.
        'max_increase_percent' => 20.0,
        // An absolute daily ceiling per object, in the account's currency. Null = none beyond the percentage.
        'daily_budget_ceiling' => null,
    ],

    // How long an unanswered proposal stays decidable.
    'proposal_ttl_hours' => 72,

    /*
     * Executors, by class name. EMPTY on purpose: no provider write is enabled until that provider's call has
     * been verified. Approving an action whose provider has no executor ends `blocked`, with the reason.
     *
     * @var list<class-string<\App\Domains\Agents\Contracts\ProposalExecutor>>
     */
    'executors' => [],

    /*
     * AGENTS-ENGINE-001 — which model each role uses, per provider. The plan gives the strongest model to
     * the roles that decide and the fastest to the repetitive ones; an owner can override per tenant through
     * the credential's `allowed_models`. Nothing outside this file names a model.
     */
    'models' => [
        'anthropic' => [
            'orchestrator' => 'claude-opus-5-5',
            'campaign' => 'claude-opus-5-5',
            'analyst' => 'claude-sonnet-5-5',
            'creative' => 'claude-sonnet-5-5',
            'leads' => 'claude-haiku-5-5',
            'reports' => 'claude-haiku-5-5',
        ],
    ],

    // The most tool round-trips one turn may take before it stops and says so.
    'max_tool_rounds' => 8,
];
