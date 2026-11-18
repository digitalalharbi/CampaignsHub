<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Http;

use App\Domains\Notifications\External\Content\Footprint;
use App\Domains\Notifications\External\Design\DesignTokens;
use App\Domains\Notifications\External\Unsubscribe\UnsubscribeLinks;
use App\Domains\Notifications\External\Unsubscribe\Unsubscriber;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The two public links an external message may carry besides «طلب تواصل» (spec §11): manage/unsubscribe, and the
 * platform footprint. Neither leads into the platform; neither needs a login.
 *
 * - `GET /n/u/{token}` shows the four choices; `POST` applies one. A mail client's RFC 8058 one-click POST
 *   (`List-Unsubscribe=One-Click`) stops «this type». A GET never changes anything (link scanners fetch every URL).
 * - `GET /n/f/{token}` counts a footprint click (type + workspace, nothing personal) and redirects to the public site
 *   with the UTM parameters.
 */
final class PublicLinksController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $claims = UnsubscribeLinks::verify($token);

        return $this->page($request, $claims === null ? 'invalid' : 'ask', $token, $claims !== null && isset($claims['c']));
    }

    public function apply(Request $request, string $token, Unsubscriber $unsubscriber): Response
    {
        $claims = UnsubscribeLinks::verify($token);
        if ($claims === null) {
            return $this->page($request, 'invalid', $token, false);
        }
        $action = $request->input('List-Unsubscribe') === 'One-Click' ? 'type' : (string) $request->input('action', 'type');
        $action = in_array($action, Unsubscriber::ACTIONS, true) ? $action : 'type';
        $result = $unsubscriber->apply($claims, $action);

        return $this->page($request, $result['done'] ? 'done_'.$result['action'] : 'nothing', $token, isset($claims['c']));
    }

    public function footprint(Request $request, string $token): RedirectResponse
    {
        $claims = Footprint::verifyClick($token);
        if ($claims !== null) {
            DB::table('notification_footprint_clicks')->insert([
                'tenant_id' => $claims['t'] ?? null, 'message_type' => mb_substr((string) ($claims['m'] ?? 'external'), 0, 60),
                'reference' => isset($claims['ref']) ? mb_substr((string) $claims['ref'], 0, 32) : null, 'clicked_at' => Carbon::now(),
            ]);
        }

        return redirect()->away(Footprint::siteUrl((string) ($claims['m'] ?? 'external')));
    }

    private function page(Request $request, string $state, string $token, bool $copy): Response
    {
        $ar = $request->query('lang') !== 'en';
        $l = $ar ? 'ar' : 'en';
        $t = static fn (string $k): string => (string) __("notifications_external.unsubscribe.{$k}", [], $l);
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $action = url('/n/u/'.$token).($ar ? '' : '?lang=en');
        $button = static fn (string $value, string $label) => '<form method="post" action="'.$e($action).'" style="margin:0 0 '.DesignTokens::space('2').'">'
            .'<input type="hidden" name="action" value="'.$e($value).'"><button type="submit" style="width:100%;padding:'.DesignTokens::space('3').';border:1px solid '.DesignTokens::color('border-strong').';border-radius:'.DesignTokens::radius('radius-medium').';background:'.DesignTokens::color('surface').';color:'.DesignTokens::color('text-primary').';font:inherit;cursor:pointer">'.$e($label).'</button></form>';

        $body = match (true) {
            $state === 'ask' && $copy => '<p>'.$e($t('copy_intro')).'</p>'.$button('type', $t('copy_stop')),
            $state === 'ask' => '<p>'.$e($t('intro')).'</p>'.$button('type', $t('type')).$button('project', $t('project')).$button('reduce', $t('reduce')).$button('all', $t('all')),
            str_starts_with($state, 'done_') => '<p>'.$e($t($state)).'</p>',
            $state === 'nothing' => '<p>'.$e($t('nothing')).'</p>',
            default => '<p>'.$e($t('invalid')).'</p>',
        };
        $html = '<!doctype html><html lang="'.$l.'" dir="'.($ar ? 'rtl' : 'ltr').'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>CampaignsHub</title></head>'
            .'<body style="margin:0;background:'.DesignTokens::color('background').';color:'.DesignTokens::color('text-primary').';font-family:'.$e(DesignTokens::emailFont($l)).'">'
            .'<main style="max-width:480px;margin:40px auto;padding:'.DesignTokens::space('6').';background:'.DesignTokens::color('surface').';border:1px solid '.DesignTokens::color('border').';border-radius:'.DesignTokens::radius('radius-large').'">'
            .'<h1 style="font-size:'.DesignTokens::px('text-lg').';margin:0 0 '.DesignTokens::space('4').'">'.$e($t('title')).'</h1>'.$body.'</main></body></html>';

        return response($html, $state === 'invalid' ? 404 : 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
