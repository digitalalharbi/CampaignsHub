<?php

declare(strict_types=1);

/*
 * FORMS-PDPL-001 — how long the EVIDENCE around a form submission is kept.
 *
 * The lead itself is the client's record in the Leads Centre and follows that module's own rules. This is the surrounding
 * evidence — the visit's attribution, the session id, the funnel events, the payloads reported to platforms — which exists to
 * explain where a lead came from and has no reason to outlive its usefulness. A form may set its own period (30 days to 10 years).
 * What is NEVER purged: the consent state, the wording's version, when it was given and which form version it answered,
 * because that is the proof that the processing was lawful, and a record of consent that expires with the data it justified
 * proves nothing.
 */
return [
    'default_retention_days' => (int) env('FORMS_RETENTION_DAYS', 730),
];
