<?php

declare(strict_types=1);

/* The API envelope, in English (I18N-001). Same keys as `lang/ar/api.php`. */

return [
    'ok' => 'Operation completed successfully.',
    'failed' => 'The request could not be processed.',

    'validation' => 'The submitted data is invalid.',
    'unauthenticated' => 'Your session is not valid. Please sign in.',
    'unauthorized' => 'This action is unauthorized.',
    'csrf' => 'This page has expired. Please refresh and try again.',
    'not_found' => 'The requested resource was not found.',
    'too_many_requests' => 'Too many requests. Please try again shortly.',
    'server_error' => 'Something went wrong. Please try again.',

    'password_reset_sent' => 'If an account exists for that email, a reset link has been sent.',
];
