<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="best-worst" style="margin:0 0 10px; table-layout:fixed;">
    <tr>
        @foreach ($m['best_worst'] as $b)
            @php($S = \App\Domains\Notifications\Support\DigestStatus::class)
            <td valign="top" style="padding:0 3px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td bgcolor="{{ $S::SOFT[$b['tone']] }}" style="background-color:{{ $S::SOFT[$b['tone']] }}; border-{{ $startSide }}:4px solid {{ $S::INK[$b['tone']] }}; border-radius:10px; padding:8px 10px;">
                    <div style="font-size:11px; font-weight:800; color:{{ $S::INK[$b['tone']] }};">{{ $S::GLYPH[$b['tone']] }} {{ $b['title'] }}</div>
                    <div style="font-size:13px; font-weight:800; color:#0b1a16; padding-top:2px;">{{ $b['name'] }}</div>
                    <div style="font-size:12px; color:#3d4742;"><span dir="ltr">{{ $b['value'] }}</span> · <span dir="ltr">{{ $b['sub'] }}</span></div>
                </td></tr></table>
            </td>
        @endforeach
    </tr>
</table>
