<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Http\Controllers;

use App\Domains\Billing\Providers\HyperPayPaymentProvider;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * PAY-GATEWAY-CONSOLE-001 — the page that hosts a gateway's own card widget when the gateway has no hosted page of its own.
 *
 * HyperPay's COPYandPAY answers a checkout with an id, not an address: its card form is a script loaded from HyperPay's host. This
 * page is that script and a form, nothing else; it reads no card data and settles nothing (the webhook does, after re-reading the
 * payment). Reached only through a temporary signed URL minted when the checkout opened, so its checkout id, integrity hash and
 * return address cannot be substituted.
 */
final class HostedCheckoutController extends Controller
{
    public function hyperpay(Request $request, HyperPayPaymentProvider $hyperpay): Response
    {
        $checkout = (string) $request->query('checkout', '');
        $integrity = (string) $request->query('integrity', '');
        $return = (string) $request->query('return', '');

        abort_unless(preg_match('/^[A-Za-z0-9.\-]{1,64}$/', $checkout) === 1, 404);
        abort_unless(str_starts_with($return, 'https://') || app()->environment(['local', 'testing']), 404);

        $script = e($hyperpay->host().'/v1/paymentWidgets.js?checkoutId='.$checkout);
        $integrityAttr = $integrity !== '' ? ' integrity="'.e($integrity).'" crossorigin="anonymous"' : '';
        $action = e($return);

        $html = <<<HTML
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<title>CampaignsHub — الدفع</title>
<script src="{$script}"{$integrityAttr}></script>
<style>body{font-family:system-ui,sans-serif;margin:0;padding:24px 16px;background:#f7f7f8;color:#111}main{max-width:480px;margin:0 auto}</style>
</head>
<body>
<main>
<h1 style="font-size:18px">الدفع الآمن عبر HyperPay</h1>
<form action="{$action}" class="paymentWidgets" data-brands="MADA VISA MASTER"></form>
</main>
</body>
</html>
HTML;

        return response($html)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Cache-Control', 'no-store')
            ->header('X-Frame-Options', 'DENY');
    }
}
