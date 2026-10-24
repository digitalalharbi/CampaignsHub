<?php

declare(strict_types=1);
use App\Domains\Agents\Executors\CampaignWriteExecutor;

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

    // AGENT-CHAT-PRIVACY-001 — how long a conversation is kept after its last message; `agents:purge-chat` deletes older ones daily.
    'chat_retention_days' => (int) env('AGENTS_CHAT_RETENTION_DAYS', 90),

    // How long an unanswered proposal stays decidable.
    'proposal_ttl_hours' => 72,

    /*
     * AGENTS-AUTONOMY-001 — what a project's record must show before an owner may let pauses or budget
     * decreases run without waiting. Read again at every automatic run.
     */
    'autonomy' => [
        'min_days' => 30,
        'min_decided' => 10,
        'min_approval_rate' => 0.9,
    ],

    /*
     * Executors, by class name. The one executor carries an APPROVED proposal through the existing campaign
     * write path (CampaignWriteService) as its approver — no new route to a platform exists.
     *
     * @var list<class-string<\App\Domains\Agents\Contracts\ProposalExecutor>>
     */
    'executors' => [CampaignWriteExecutor::class],

    /*
     * Which providers an approved proposal may be carried to. Meta (`ads_management`) and Google Ads
     * (`adwords`) grant write access in the scopes this product requests. Snapchat waits for the Owner's
     * reversible write test on the stored token (AGENTS-SNAP-WRITE-CONFIRM-001) and TikTok for its app
     * permission; LinkedIn and X are read-only grants. Approving for a provider not listed ends `blocked`
     * with execution_not_enabled_for_provider.
     */
    'execute_providers' => array_values(array_filter(explode(',', (string) env('AGENTS_EXECUTE_PROVIDERS', 'meta,google')))),

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
        /*
         * OpenAI's model is the tenant's choice, never ours: set AGENTS_OPENAI_MODEL, or list the model in the
         * key's allowed_models. With neither, an OpenAI key is refused with model_not_configured.
         */
        'openai' => array_fill_keys(['orchestrator', 'campaign', 'analyst', 'creative', 'leads', 'reports'], env('AGENTS_OPENAI_MODEL')),
        // The same rule for Gemini: AGENTS_GEMINI_MODEL or the key's allowed_models, else model_not_configured.
        'gemini' => array_fill_keys(['orchestrator', 'campaign', 'analyst', 'creative', 'leads', 'reports'], env('AGENTS_GEMINI_MODEL')),
    ],

    // The most tool round-trips one turn may take before it stops and says so.
    'max_tool_rounds' => 8,
];
