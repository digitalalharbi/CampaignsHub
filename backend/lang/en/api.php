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
    'portal_unavailable' => 'This service is not available yet. We will announce it when it opens.',
    'too_many_requests' => 'Too many requests. Please try again shortly.',
    'server_error' => 'Something went wrong. Please try again.',

    'password_reset_sent' => 'If an account exists for that email, a reset link has been sent.',

    'contact_received' => 'Thank you — your message has been received and we will reply by email.',
    'support_ticket_created' => 'Your ticket has been created. Keep the reference to follow it up.',
    'data_request_received' => 'Your request has been recorded and will be reviewed.',
    'policies_not_accepted' => 'You must accept: :documents',
];
