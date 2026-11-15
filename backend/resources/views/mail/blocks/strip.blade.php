{{-- The platform strip, shared by the project digest and the external report: one state chip per platform. Needs $cells (provider, short, state). --}}
@php($S = \App\Domains\Notifications\Support\DigestStatus::class)
@if ($cells)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="platform-strip" style="margin-bottom:12px; table-layout:fixed;">
            <tr>
                @foreach ($cells as $c)
                    <td align="center" data-provider="{{ $c['provider'] }}" data-state="{{ $c['state'] }}" style="padding:0 2px;">
                        <div style="font-size:10px; font-weight:700; color:#5b6b68; padding-bottom:3px; white-space:nowrap; overflow:hidden;">{{ $c['short'] }}</div>
                        <span style="display:block; height:26px; line-height:26px; border-radius:8px; text-align:center; font-size:14px; font-weight:800; background-color:{{ $S::CELL[$c['state']] }}; color:{{ $S::INK[$c['state']] }};">{{ $S::GLYPH[$c['state']] }}</span>
                    </td>
                @endforeach
            </tr>
        </table>
    @endif
