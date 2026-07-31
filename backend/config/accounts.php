<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Registration policy (SIGNUP-002)
    |--------------------------------------------------------------------------
    |
    | What an applicant must clear before a workspace is created for them. Read by
    | `RegistrationPolicy`, which merges default → account type → plan, so the plan is the most
    | specific statement and wins.
    |
    | The default below is EMAIL VERIFICATION ONLY, which is what the product does today. That is
    | the auto-activate branch the contract permits, written down so it is a choice with a name
    | rather than the absence of a gate. Turning on approval or payment for a plan is a config
    | change and nothing else — the path is already built to honour them.
    |
    | These become editable from /admin once the plans engine lands (PLAN-001); until then this file
    | is the single place the answer lives.
    |
    */
    'registration' => [
        'default' => [
            'requires_mobile' => false,
            'requires_approval' => false,
            'requires_payment' => false,
        ],

        // Coarser than a plan and finer than the default: "every agency is reviewed" without
        // deciding anything about which plan they picked.
        'account_types' => [
            // 'agency' => ['requires_approval' => true],
        ],

        // A plan is the most specific statement and overrides both.
        'plans' => [
            // 'growth' => ['requires_payment' => true],
        ],
    ],

];
