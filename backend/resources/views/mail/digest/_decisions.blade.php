{{-- What needs a person: severity, ONE headline sentence, the numbers, the next step and a button INTO the system. Needs $items, $showProject. --}}
@foreach ($items as $d)
    @php($c = $d['severity'] === 'critical' ? 'bad' : 'warn')
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="decision" style="background-color:{{ $toneBg[$c] }}; border-radius:12px; margin-bottom:10px;">
        <tr>
            <td style="padding:12px 14px; border-{{ $startSide }}:4px solid {{ $tone[$c] }}; border-radius:12px;">
                @if (($showProject ?? false) && ! empty($d['project']))
                    <div style="font-size:11px; font-weight:700; color:#5b6b68;">{{ $d['project'] }}</div>
                @endif
                <div style="font-size:15px; font-weight:800; color:#0b1a16; line-height:1.5;">{{ $d['headline'] }}</div>
                <div style="font-size:13px; color:#3d4742; line-height:1.7; padding-top:4px;">{{ $d['text'] }}</div>
                <div style="font-size:12px; font-weight:700; color:#0b1a16; padding-top:6px;">{{ $d['action'] }}</div>
                <div style="padding-top:8px;"><a href="{{ $d['url'] }}" style="display:inline-block; background-color:#ffffff; color:{{ $tone[$c] }}; border:1px solid {{ $tone[$c] }}; font-size:12px; font-weight:700; text-decoration:none; padding:7px 14px; border-radius:8px;">{{ $d['button'] }} {{ $dir === 'rtl' ? '←' : '→' }}</a></div>
            </td>
        </tr>
    </table>
@endforeach
