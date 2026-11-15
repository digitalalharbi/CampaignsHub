@php($t = $m['trend'])
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="trend" style="border:1px solid #e4e0d6; border-radius:12px; margin:0 0 10px;">
    <tr><td style="padding:10px 12px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
            <td><div style="font-size:11px; font-weight:800; color:#5b6b68;">{{ $t['title'] }}</div><div style="font-size:20px; font-weight:800; color:#0b1a16;" dir="ltr">{{ $t['total'] }}</div></td>
            <td style="text-align:{{ $endSide }};">@if ($t['delta'])<span style="display:inline-block; padding:2px 9px; border-radius:999px; font-size:11px; font-weight:800; background-color:{{ \App\Domains\Notifications\Support\DigestStatus::CELL[$t['delta']['tone']] }}; color:{{ \App\Domains\Notifications\Support\DigestStatus::INK[$t['delta']['tone']] }};" dir="ltr">{{ $t['delta']['text'] }}</span>@endif</td>
        </tr></table>
        @if (isset($m['charts']['trend']))
            <div style="padding-top:6px;">@include('mail.blocks.img', ['key' => 'trend', 'alt' => $t['alt'], 'w' => 520])</div>
        @else
            @include('mail.digest._spark', ['bars' => $t['bars'], 'colour' => '#0d8a6f', 'height' => 40, 'labels' => count($t['bars']) <= 16])
        @endif
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" dir="ltr" style="margin-top:6px;"><tr>
            <td width="14" style="padding:0 4px 0 0;"><table role="presentation" width="14" cellpadding="0" cellspacing="0" border="0"><tr><td height="3" bgcolor="#0d8a6f" style="background-color:#0d8a6f; height:3px; line-height:3px; font-size:1px; border-radius:2px;">&nbsp;</td></tr></table></td>
            <td style="font-size:10px; color:#5b6b68; padding-right:12px;" dir="auto">{{ $t['legend']['current'] }}</td>
            <td width="14" style="padding:0 4px 0 0;"><table role="presentation" width="14" cellpadding="0" cellspacing="0" border="0"><tr><td height="3" bgcolor="#9ccfc0" style="background-color:#9ccfc0; height:3px; line-height:3px; font-size:1px; border-radius:2px;">&nbsp;</td></tr></table></td>
            <td style="font-size:10px; color:#5b6b68;" dir="auto">{{ $t['legend']['previous'] }}</td>
        </tr></table>
    </td></tr>
</table>
