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
    // LOGIN-OTP-001 — the server-side resend window, stated in the same words the countdown uses.
    'resend_cooldown' => 'Please wait :seconds seconds before requesting another code.',
    // The three ways a code can be refused, in the reader's own language (LOGIN-E2E-001).
    'code_expired' => 'This verification code has expired. Request a new one.',
    'code_attempts' => 'Too many attempts. Request a new code.',
    'code_incorrect' => 'Incorrect code.',
    'code_unavailable' => 'We could not check the code right now. Please try again.',
    'signed_out' => 'Signed out successfully.',
    'current_user' => 'Current user.',
    'token_issued' => 'Token issued successfully.',

    'unavailable' => 'Your account is not available. Please contact support.',

    'portal_mismatch' => 'This account is not authorised for that portal.',
    'code_channel_off' => 'Signing in with a code on this channel is turned off. Use your password or «Forgot password?».',
    'code_proof_off' => 'Proving a mobile number by code is turned off by the platform administrator. A number already confirmed stays confirmed.',
    'code_switch_sms_only' => 'Refused: :count account(s) have an unproved address and no self-service way back except an SMS code. Have them prove the address, or help them with a set-password link, first.',
    'code_switch_signup_unproven' => 'Refused: signup is not proving addresses, and an SMS code is the only self-service way back for those accounts. The two cannot both be off.',
    'code_switch_contacts' => 'Refused: :count client contact(s) would have no way in. They hold no password and no other channel that reaches them.',
];
