{{-- Ranked horizontal bars: [rank or colour dot] name · bar · value · change. The platform legend of a donut and the top campaigns share it. Needs $rows (label, value, bar 0–100, provider, optional rank, optional delta {text,tone}). Bars are table cells (no image): they survive blocked images and every client. --}}
@php($S = \App\Domains\Notifications\Support\DigestStatus::class)
@php($T = \App\Domains\Notifications\External\Design\DesignTokens::class)
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="rows" style="table-layout:fixed;">
    @foreach ($rows as $r)
        @php($colour = $r['provider'] !== '' ? $T::platform((string) $r['provider']) : '#0d8a6f')
        <tr>
            <td width="{{ $r['rank'] ?? null ? 18 : 14 }}" valign="middle" style="padding:5px 0; font-size:12px; font-weight:800; color:#0a6b57;">@if ($r['rank'] ?? null){{ $r['rank'] }}@else<span style="display:inline-block; width:9px; height:9px; border-radius:5px; background-color:{{ $colour }};">&nbsp;</span>@endif</td>
            <td width="34%" valign="middle" style="padding:5px 4px; font-size:12px; font-weight:800; color:#0b1a16; white-space:nowrap; overflow:hidden;"><span dir="auto" style="unicode-bidi:plaintext;">{{ \Illuminate\Support\Str::limit($r['label'], 22, '…') }}</span></td>
            <td valign="middle" dir="ltr" style="padding:5px 6px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td bgcolor="#eeeae0" style="background-color:#eeeae0; border-radius:3px;">
                    <table role="presentation" width="{{ max(4, min(100, (int) $r['bar'])) }}%" cellpadding="0" cellspacing="0" border="0"><tr><td height="6" bgcolor="{{ $colour }}" style="background-color:{{ $colour }}; height:6px; line-height:6px; font-size:1px; border-radius:3px;">&nbsp;</td></tr></table>
                </td></tr></table>
            </td>
            <td width="70" valign="middle" dir="ltr" style="padding:5px 0; font-size:12px; font-weight:800; color:#0b1a16; text-align:{{ $endSide }}; white-space:nowrap;">{{ $r['value'] }}</td>
            <td width="52" valign="middle" dir="ltr" style="padding:5px 0 5px 4px; text-align:{{ $endSide }}; white-space:nowrap;">@if (! empty($r['delta']))<span style="font-size:10px; font-weight:800; color:{{ $S::INK[$r['delta']['tone']] }};">{{ $r['delta']['text'] }}</span>@endif</td>
        </tr>
    @endforeach
</table>
