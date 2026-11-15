@php
    /*
     * NOTIF-V2-EXTERNAL-001 — the external service report (Owner spec §11). Every colour, radius, size and font comes from
     * the shared design-tokens module (DesignTokens); nothing here names a value of its own. 600px, RTL/LTR, light with a
     * dark-mode alternate (prefers-color-scheme + Outlook.com's data-ogsc), tables for Outlook. No link into the platform:
     * only manage/unsubscribe, the footprint link and the workspace's optional «طلب تواصل».
     */
    use App\Domains\Notifications\External\Design\DesignTokens as T;
    $th = $m['theme'] === 'dark' ? 'dark' : 'light';
    $c = static fn (string $n): string => T::color($n, $th);
    $d = static fn (string $n): string => T::color($n, 'dark');
    $font = T::emailFont($m['locale']);
    $r = T::radius('radius-large');
    $rs = T::radius('radius-medium');
    $s2 = T::space('2'); $s3 = T::space('3'); $s4 = T::space('4'); $s6 = T::space('6');
    $xs = T::px('text-xs'); $sm = T::px('text-sm'); $base = T::px('text-base'); $lg = T::px('text-lg'); $kpi = T::px('text-kpi');
    $card = "background:{$c('surface')};border:1px solid {$c('border')};border-radius:{$r};padding:{$s4} {$s4};";
    $status = T::status($m['project_card']['status'] ?? 'on_track', $th);
    $arrow = static fn (?float $v, ?string $good): string => $v === null ? '' : ($v > 0.005 ? '▲ ' : ($v < -0.005 ? '▼ ' : '')).number_format(abs($v) * 100, 0).'%';
    $tone = static fn (?float $v, ?string $good): string => $v === null || $good === null || abs($v) < 0.005 ? 'text-muted' : ((($v > 0) === ($good === 'up')) ? 'brand-600' : 'text-secondary');
    $img = static function (string $key, string $alt, array $charts) use ($th) {
        $ch = $charts[$key] ?? null;
        if ($ch === null) { return null; }
        $w = (int) round($ch['width'] / 2); $h = (int) round($ch['height'] / 2);
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        if ($th === 'dark') {
            return '<img src="cid:'.$key.'-dark" width="'.$w.'" height="'.$h.'" alt="'.$e($alt).'" style="display:block;width:100%;max-width:'.$w.'px;height:auto;border:0">';
        }

        return '<img class="ch-light-img" src="cid:'.$key.'" width="'.$w.'" height="'.$h.'" alt="'.$e($alt).'" style="display:block;width:100%;max-width:'.$w.'px;height:auto;border:0">'
            .'<!--[if !mso]><!--><img class="ch-dark-img" src="cid:'.$key.'-dark" width="'.$w.'" height="'.$h.'" alt="'.$e($alt).'" style="display:none;width:100%;max-width:'.$w.'px;height:auto;border:0;max-height:0;overflow:hidden"><!--<![endif]-->';
    };
@endphp
<!doctype html>
<html lang="{{ $m['locale'] }}" dir="{{ $m['dir'] }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>{{ $m['subject'] }}</title>
@if ($th === 'light')
<style>
@media (prefers-color-scheme: dark) {
  .ch-body, .ch-canvas { background: {{ $d('background') }} !important; }
  .ch-card { background: {{ $d('surface') }} !important; border-color: {{ $d('border') }} !important; }
  .ch-soft { background: {{ $d('surface-secondary') }} !important; }
  .ch-ink { color: {{ $d('text-primary') }} !important; }
  .ch-ink2 { color: {{ $d('text-secondary') }} !important; }
  .ch-muted { color: {{ $d('text-muted') }} !important; }
  .ch-brand { color: {{ $d('brand-600') }} !important; }
  .ch-light-img { display: none !important; max-height: 0 !important; }
  .ch-dark-img { display: block !important; max-height: none !important; }
}
[data-ogsc] .ch-card { background: {{ $d('surface') }} !important; }
[data-ogsc] .ch-ink { color: {{ $d('text-primary') }} !important; }
[data-ogsc] .ch-muted { color: {{ $d('text-muted') }} !important; }
@media (max-width: 620px) { .ch-wrap { width: 100% !important; } .ch-pad { padding-left: {{ $s3 }} !important; padding-right: {{ $s3 }} !important; } }
</style>
@endif
</head>
<body class="ch-body" style="margin:0;padding:0;background:{{ $c('background') }};font-family:{{ $font }};color:{{ $c('text-primary') }}">
<table role="presentation" class="ch-canvas" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:{{ $c('background') }}">
<tr><td align="center" class="ch-pad" style="padding:{{ $s6 }} {{ $s4 }}">
<table role="presentation" class="ch-wrap" width="{{ T::emailWidth() }}" cellpadding="0" cellspacing="0" border="0" style="width:{{ T::emailWidth() }}px;max-width:100%" dir="{{ $m['dir'] }}">

@if ($m['copy_band'])
<tr><td style="padding:0 0 {{ $s3 }}"><div class="ch-soft ch-ink2" style="background:{{ $c('info-background') }};color:{{ $c('text-secondary') }};border-radius:{{ $rs }};padding:{{ $s2 }} {{ $s3 }};font-size:{{ $xs }};text-align:center">
<strong>{{ $m['copy_band']['title'] }}</strong>@if ($m['copy_band']['note']) — {{ $m['copy_band']['note'] }}@endif
</div></td></tr>
@endif

{{-- 1. Workspace + project header --}}
<tr><td class="ch-card" style="{{ $card }}">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td width="44" valign="middle"><div style="width:40px;height:40px;line-height:40px;border-radius:{{ $rs }};background:{{ $c('brand-600') }};color:{{ $c('surface') }};text-align:center;font-weight:700;font-size:{{ $lg }}">{{ $m['initials'] }}</div></td>
<td valign="middle" style="padding-{{ $m['align'] === 'right' ? 'right' : 'left' }}:{{ $s3 }};text-align:{{ $m['align'] }}">
<div class="ch-ink" style="font-size:{{ $base }};font-weight:700;color:{{ $c('text-primary') }}">{{ $m['workspace'] }}</div>
<div style="margin-top:4px"><span style="display:inline-block;background:{{ $c('brand-background') }};color:{{ $c('brand-700') }};border-radius:{{ T::radius('radius-pill') }};padding:2px {{ $s2 }};font-size:{{ $xs }};font-weight:600">{{ $m['badge'] }}</span></div>
<div class="ch-muted" style="margin-top:4px;font-size:{{ $xs }};color:{{ $c('text-muted') }}">{{ $m['managed_by'] }} · {{ $m['period'] }}</div>
</td></tr></table>
</td></tr>
<tr><td height="{{ (int) $s3 }}" style="font-size:0;line-height:0">&nbsp;</td></tr>

{{-- 2–3. Greeting + privacy line --}}
<tr><td class="ch-card" style="{{ $card }}text-align:{{ $m['align'] }}">
<div class="ch-ink" style="font-size:{{ $base }};font-weight:600;color:{{ $c('text-primary') }}">{{ $m['greeting'] }}</div>
<div class="ch-ink2" style="margin-top:{{ $s2 }};font-size:{{ $xs }};line-height:1.7;color:{{ $c('text-secondary') }}">{{ $m['privacy'] }}</div>
<div class="ch-ink2" style="margin-top:{{ $s2 }};font-size:{{ $sm }};color:{{ $c('text-secondary') }}">{{ $m['tone'] }}@if ($m['event_line']) {{ $m['event_line'] }}@endif</div>
@if ($m['updating'])
<div class="ch-soft" style="margin-top:{{ $s3 }};background:{{ $c('surface-secondary') }};border-radius:{{ $rs }};padding:{{ $s3 }};font-size:{{ $sm }};color:{{ $c('text-secondary') }}">{{ $m['updating'] }}</div>
@endif
</td></tr>
<tr><td height="{{ (int) $s3 }}" style="font-size:0;line-height:0">&nbsp;</td></tr>

{{-- 4. Opening card: top achievement, big number, progress ring, one data sentence --}}
@if ($m['opening'])
<tr><td class="ch-card" style="{{ $card }}text-align:{{ $m['align'] }}">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td valign="middle">
<div class="ch-muted" style="font-size:{{ $xs }};color:{{ $c('text-muted') }}">{{ $m['opening']['label'] }}</div>
<div class="ch-ink" dir="ltr" style="font-size:{{ $kpi }};font-weight:700;color:{{ $c('text-primary') }};text-align:{{ $m['align'] }}">{{ $m['opening']['value'] }}</div>
<div class="ch-ink2" style="margin-top:{{ $s2 }};font-size:{{ $sm }};color:{{ $c('text-secondary') }}">{{ $m['opening']['sentence'] }}</div>
</td>
@if ($m['opening']['ring'])
<td width="120" valign="middle" align="center">
@php $ringImg = $img('ring', $m['opening']['ring']['label'], $m['charts']); @endphp
@if ($ringImg){!! $ringImg !!}@else
<div style="font-size:{{ $lg }};font-weight:700;color:{{ $c('brand-600') }}" class="ch-brand">{{ $m['opening']['ring']['label'] }}</div>
@endif
</td>
@endif
</tr></table>
</td></tr>
<tr><td height="{{ (int) $s3 }}" style="font-size:0;line-height:0">&nbsp;</td></tr>
@endif

{{-- 5. KPIs per objective (each objective its own; never a cost per result across objectives) --}}
@if ($m['kpis'] !== [])
<tr><td class="ch-card" style="{{ $card }}text-align:{{ $m['align'] }}">
<div class="ch-ink" style="font-size:{{ $base }};font-weight:700;color:{{ $c('text-primary') }};margin-bottom:{{ $s3 }}">{{ $m['kpis_title'] }}</div>
@foreach ($m['kpis'] as $group)
<div class="ch-muted" style="font-size:{{ $xs }};font-weight:600;color:{{ $c('text-muted') }};margin:{{ $s2 }} 0">{{ $group['objective'] }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
@foreach ($group['cards'] as $k)
<td valign="top" width="{{ (int) floor(100 / max(1, count($group['cards']))) }}%" style="padding:2px">
<div class="ch-soft" style="background:{{ $c('surface-secondary') }};border-radius:{{ $rs }};padding:{{ $s3 }}">
<div class="ch-muted" style="font-size:{{ $xs }};color:{{ $c('text-muted') }}">{{ $k['label'] }}</div>
<div class="ch-ink" dir="ltr" style="font-size:{{ $lg }};font-weight:700;color:{{ $c('text-primary') }};text-align:{{ $m['align'] }}">{{ $k['value'] }}</div>
@if ($k['change'] !== null)<div dir="ltr" style="font-size:{{ $xs }};color:{{ $c($tone($k['change'], $k['good_when'])) }};text-align:{{ $m['align'] }}">{{ $arrow($k['change'], $k['good_when']) }}</div>@endif
</div>
</td>
@endforeach
</tr></table>
@endforeach
</td></tr>
<tr><td height="{{ (int) $s3 }}" style="font-size:0;line-height:0">&nbsp;</td></tr>
@endif

{{-- 6. Trend --}}
@if ($m['trend'])
<tr><td class="ch-card" style="{{ $card }}text-align:{{ $m['align'] }}">
<div class="ch-ink" style="font-size:{{ $base }};font-weight:700;color:{{ $c('text-primary') }};margin-bottom:{{ $s3 }}">{{ $m['trend']['title'] }}</div>
@php $trendImg = $img('trend', $m['trend']['alt'], $m['charts']); @endphp
@if ($trendImg){!! $trendImg !!}@else
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:{{ $xs }};color:{{ $c('text-secondary') }}">
<tr><th align="{{ $m['align'] }}" style="padding:4px 0;color:{{ $c('text-muted') }}">{{ $m['labels']['date'] }}</th><th align="{{ $m['align'] }}" style="padding:4px 0;color:{{ $c('text-muted') }}">{{ $m['labels']['spend'] }}</th></tr>
@foreach ($m['trend']['rows'] as $row)
<tr><td dir="ltr" style="padding:2px 0;border-top:1px solid {{ $c('border') }};text-align:{{ $m['align'] }}">{{ $row['date'] }}</td><td dir="ltr" style="padding:2px 0;border-top:1px solid {{ $c('border') }};text-align:{{ $m['align'] }}">{{ $row['spend'] }}</td></tr>
@endforeach
</table>
@endif
</td></tr>
<tr><td height="{{ (int) $s3 }}" style="font-size:0;line-height:0">&nbsp;</td></tr>
@endif

{{-- 7. Donut by platform --}}
@if ($m['platforms'])
<tr><td class="ch-card" style="{{ $card }}text-align:{{ $m['align'] }}">
<div class="ch-ink" style="font-size:{{ $base }};font-weight:700;color:{{ $c('text-primary') }};margin-bottom:{{ $s3 }}">{{ $m['platforms']['title'] }}</div>
@php $donutImg = $img('donut', $m['platforms']['alt'], $m['charts']); @endphp
@if ($donutImg)<div style="margin-bottom:{{ $s3 }}">{!! $donutImg !!}</div>@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:{{ $xs }};color:{{ $c('text-secondary') }}">
<tr><th align="{{ $m['align'] }}" style="padding:4px 0;color:{{ $c('text-muted') }}">{{ $m['labels']['platform'] }}</th><th align="{{ $m['align'] }}" style="padding:4px 0;color:{{ $c('text-muted') }}">{{ $m['labels']['spend'] }}</th><th align="{{ $m['align'] }}" style="padding:4px 0;color:{{ $c('text-muted') }}">{{ $m['labels']['share'] }}</th><th align="{{ $m['align'] }}" style="padding:4px 0;color:{{ $c('text-muted') }}">{{ $m['labels']['previous'] }}</th></tr>
@foreach ($m['platforms']['rows'] as $p)
<tr><td style="padding:4px 0;border-top:1px solid {{ $c('border') }}"><span style="display:inline-block;width:8px;height:8px;border-radius:4px;background:{{ T::platform($p['provider'], $th) }}"></span> {{ $p['label'] }}</td>
<td dir="ltr" style="padding:4px 0;border-top:1px solid {{ $c('border') }};text-align:{{ $m['align'] }}">{{ $p['spend'] }}</td>
<td dir="ltr" style="padding:4px 0;border-top:1px solid {{ $c('border') }};text-align:{{ $m['align'] }}">{{ $p['share'] }}</td>
<td dir="ltr" style="padding:4px 0;border-top:1px solid {{ $c('border') }};text-align:{{ $m['align'] }}">{{ $p['previous'] }}</td></tr>
@endforeach
</table>
</td></tr>
<tr><td height="{{ (int) $s3 }}" style="font-size:0;line-height:0">&nbsp;</td></tr>
@endif

{{-- 8. Project card: status chip, budget ring, «تكفي حتى» --}}
@if ($m['project_card'])
<tr><td class="ch-card" style="{{ $card }}text-align:{{ $m['align'] }}">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td class="ch-ink" style="font-size:{{ $base }};font-weight:700;color:{{ $c('text-primary') }}">{{ $m['project'] }}</td>
<td align="{{ $m['align'] === 'right' ? 'left' : 'right' }}"><span style="display:inline-block;background:{{ $status['background'] }};color:{{ $status['color'] }};border-radius:{{ T::radius('radius-pill') }};padding:2px {{ $s2 }};font-size:{{ $xs }};font-weight:600">{{ $m['project_card']['status_label'] }}</span></td>
</tr></table>
@if ($m['project_card']['budget'])
@php $b = $m['project_card']['budget']; @endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:{{ $s3 }};font-size:{{ $xs }};color:{{ $c('text-secondary') }}">
<tr>
<td width="25%"><div style="color:{{ $c('text-muted') }}">{{ $m['labels']['approved'] }}</div><div dir="ltr" style="font-weight:600;color:{{ $c('text-primary') }};text-align:{{ $m['align'] }}">{{ $b['approved'] }}</div></td>
<td width="25%"><div style="color:{{ $c('text-muted') }}">{{ $m['labels']['spent'] }}</div><div dir="ltr" style="font-weight:600;color:{{ $c('text-primary') }};text-align:{{ $m['align'] }}">{{ $b['spent'] }}</div></td>
<td width="25%"><div style="color:{{ $c('text-muted') }}">{{ $m['labels']['remaining'] }}</div><div dir="ltr" style="font-weight:600;color:{{ $c('text-primary') }};text-align:{{ $m['align'] }}">{{ $b['remaining'] }}</div></td>
<td width="25%"><div style="color:{{ $c('text-muted') }}">{{ $m['labels']['pace'] }}</div><div dir="ltr" style="font-weight:600;color:{{ $c('text-primary') }};text-align:{{ $m['align'] }}">{{ $b['pace'] }}</div></td>
</tr></table>
@if ($b['ratio'] !== null)
<div style="margin-top:{{ $s3 }};height:8px;background:{{ $c('surface-secondary') }};border-radius:{{ T::radius('radius-pill') }};overflow:hidden"><div style="height:8px;width:{{ (int) min(100, round($b['ratio'] * 100)) }}%;background:{{ $c('brand-600') }};border-radius:{{ T::radius('radius-pill') }}"></div></div>
@endif
@if ($b['lasts_until'])<div class="ch-brand" style="margin-top:{{ $s2 }};font-size:{{ $sm }};font-weight:600;color:{{ $c('brand-700') }}">{{ $b['lasts_until'] }}</div>@endif
@if ($b['equivalent'])<div class="ch-muted" style="font-size:{{ $xs }};color:{{ $c('text-muted') }}">{{ $b['equivalent'] }}</div>@endif
<div class="ch-muted" style="margin-top:4px;font-size:{{ $xs }};color:{{ $c('text-muted') }}">{{ $b['advisory'] }}</div>
@elseif ($m['project_card']['no_budget'])
<div class="ch-muted" style="margin-top:{{ $s2 }};font-size:{{ $xs }};color:{{ $c('text-muted') }}">{{ $m['project_card']['no_budget'] }}</div>
@endif
</td></tr>
<tr><td height="{{ (int) $s3 }}" style="font-size:0;line-height:0">&nbsp;</td></tr>
@endif

{{-- 9. Top campaigns (or platforms for the client role) --}}
@if ($m['top'])
<tr><td class="ch-card" style="{{ $card }}text-align:{{ $m['align'] }}">
<div class="ch-ink" style="font-size:{{ $base }};font-weight:700;color:{{ $c('text-primary') }};margin-bottom:{{ $s2 }}">{{ $m['top']['title'] }}</div>
@foreach ($m['top']['rows'] as $i => $row)
<div style="padding:{{ $s2 }} 0;border-top:1px solid {{ $c('border') }};font-size:{{ $sm }}"><span style="color:{{ $c('brand-600') }};font-weight:700">{{ $i + 1 }}.</span> <span class="ch-ink" style="color:{{ $c('text-primary') }};font-weight:600">{{ $row['name'] }}</span><div class="ch-muted" dir="auto" style="font-size:{{ $xs }};color:{{ $c('text-muted') }}">{{ $row['detail'] }}</div></div>
@endforeach
</td></tr>
<tr><td height="{{ (int) $s3 }}" style="font-size:0;line-height:0">&nbsp;</td></tr>
@endif

{{-- Achievements + 10. growth suggestions --}}
@if ($m['achievements'] !== [] || $m['suggestions'] !== [])
<tr><td class="ch-card" style="{{ $card }}text-align:{{ $m['align'] }}">
@if ($m['achievements'] !== [])
<div class="ch-ink" style="font-size:{{ $base }};font-weight:700;color:{{ $c('text-primary') }};margin-bottom:{{ $s2 }}">{{ $m['achievements_title'] }}</div>
@foreach ($m['achievements'] as $a)<div class="ch-ink2" style="font-size:{{ $sm }};color:{{ $c('text-secondary') }};padding:2px 0"><span style="color:{{ $c('brand-600') }}">✓</span> {{ $a }}</div>@endforeach
@endif
@if ($m['suggestions'] !== [])
<div class="ch-ink" style="font-size:{{ $base }};font-weight:700;color:{{ $c('text-primary') }};margin:{{ $s3 }} 0 {{ $s2 }}">{{ $m['suggestions_title'] }}</div>
@foreach ($m['suggestions'] as $sg)<div class="ch-ink2" style="font-size:{{ $sm }};color:{{ $c('text-secondary') }};padding:2px 0">· {{ $sg }}</div>@endforeach
@endif
</td></tr>
<tr><td height="{{ (int) $s3 }}" style="font-size:0;line-height:0">&nbsp;</td></tr>
@endif

{{-- 11. Signature, 12. why, manage, contact --}}
<tr><td style="padding:{{ $s2 }} {{ $s4 }};text-align:{{ $m['align'] }}">
<div class="ch-ink" style="font-size:{{ $sm }};color:{{ $c('text-primary') }};white-space:pre-line">{{ $m['signature'] }}</div>
<div class="ch-muted" style="margin-top:{{ $s3 }};font-size:{{ $xs }};line-height:1.7;color:{{ $c('text-muted') }}">{{ $m['why'] }}
<a href="{{ $m['manage_url'] }}" style="color:{{ $c('brand-700') }}">{{ $m['manage_label'] }}</a>@if ($m['contact']) · <a href="{{ $m['contact']['url'] }}" style="color:{{ $c('brand-700') }}">{{ $m['contact']['label'] }}</a>@endif</div>
</td></tr>

{{-- 13. Footprint --}}
@if ($m['footprint'])
<tr><td style="padding:{{ $s2 }} {{ $s4 }};text-align:{{ $m['align'] }}">
<div class="ch-muted" style="font-size:{{ $xs }};color:{{ $c('text-muted') }}"><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:{{ $c('brand-600') }};vertical-align:middle"></span><span style="display:inline-block;width:4px;height:4px;border-radius:2px;background:{{ $c('brand-gold') }};vertical-align:top"></span>
{{ $m['footprint']['text'] }} — <a href="{{ $m['footprint']['link'] }}" style="color:{{ $c('brand-700') }}">{{ $m['footprint']['link_label'] }}</a>
@if ($m['footprint']['invitation'])<br>{{ $m['footprint']['invitation'] }}@endif
</div>
</td></tr>
@endif

{{-- 14. Disclaimer, creation time, sources, reference, FX --}}
<tr><td style="padding:{{ $s2 }} {{ $s4 }} {{ $s6 }};text-align:{{ $m['align'] }}">
<div class="ch-muted" style="font-size:{{ $xs }};line-height:1.7;color:{{ $c('text-muted') }}">{{ $m['disclaimer'] }}<br>{{ $m['created'] }} · {{ $m['sources'] }} · <span dir="ltr">{{ $m['reference'] }}</span>@if ($m['fx'])<br><span dir="ltr">{{ $m['fx'] }}</span>@endif</div>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>
