<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Http\Controllers;

use App\Domains\Notifications\Charts\MailChart;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/** EMAIL-DECISION-REPORT-001 — the PNG behind an email's chart `<img>`, by opaque token; nothing else is readable here. */
final class MailChartController extends Controller
{
    public function png(string $token): Response
    {
        abort_unless(preg_match('/^[a-z0-9]{32}$/', $token) === 1, 404);
        $spec = MailChart::spec($token);
        abort_if($spec === null || ! MailChart::available(), 404);

        return response(MailChart::render($spec), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
