<?php

declare(strict_types=1);

/*
 * English authentication messages (I18N-001).
 *
 * Laravel ships `failed`, `password` and `throttle`; the rest are CampaignsHub's own, so this file
 * must exist rather than relying on the framework's — a missing key renders as the key itself.
 */

return [
    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

    'signed_in' => 'Signed in successfully.',
    'code_sent' => 'A verification code has been sent.',
    'signed_out' => 'Signed out successfully.',
    'current_user' => 'Current user.',
    'token_issued' => 'Token issued successfully.',

    'unavailable' => 'Your account is not available. Please contact support.',

    'portal_mismatch' => 'This account is not authorised for that portal.',
];
