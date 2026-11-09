{{-- The ranked «needs a decision» list: rank, chip, WHERE, what, the problem drawn, ONE button. Needs $items, $showProject, $from (rank offset). --}}
@foreach ($items as $n => $d)
    @php($state = $d['state'])
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="issue" data-state="{{ $state }}" data-reason="{{ $d['reason'] }}" style="background-color:{{ \App\Domains\Notifications\Support\DigestStatus::SOFT[$state] }}; border-radius:10px; margin-bottom:6px;">
        <tr>
            <td width="30" valign="top" style="padding:8px 0; text-align:center; border-{{ $startSide }}:4px solid {{ \App\Domains\Notifications\Support\DigestStatus::INK[$state] }}; border-radius:10px;">
                @include('mail.digest._chip', ['state' => $state, 'size' => 22])
                <div style="font-size:10px; color:#8b9a97; padding-top:3px;" dir="ltr">{{ ($from ?? 0) + $n + 1 }}</div>
            </td>
            <td valign="top" style="padding:6px 8px;">
                @if (($showProject ?? true) || $d['platform'] !== '')
                    <div style="font-size:13px; font-weight:800; color:#0b1a16; line-height:1.4;">{{ ($showProject ?? true) ? $d['project'] : '' }}@if (($showProject ?? true) && $d['platform'] !== '') <span style="color:#8b9a97; font-weight:400;">·</span> @endif{{ $d['platform'] }}</div>
                @endif
                <div style="font-size:13px; font-weight:700; color:{{ \App\Domains\Notifications\Support\DigestStatus::INK[$state] }}; line-height:1.5;">{{ $d['headline'] }}</div>
                @if ($d['viz'])
                    @include('mail.digest._viz', ['viz' => $d['viz']])
                @elseif ($d['figure'] !== '')
                    <div style="font-size:12px; color:#3d4742; line-height:1.5;" dir="auto">{{ $d['figure'] }}</div>
                @endif
            </td>
            <td width="84" valign="middle" style="padding:8px 8px 8px 0; text-align:center;">
                <a href="{{ $d['url'] }}" style="display:block; background-color:#ffffff; color:{{ \App\Domains\Notifications\Support\DigestStatus::INK[$state] }}; border:1px solid {{ \App\Domains\Notifications\Support\DigestStatus::INK[$state] }}; font-size:12px; font-weight:700; text-decoration:none; padding:6px 2px; border-radius:8px; line-height:1.3;">{{ $d['button'] }} {{ $dir === 'rtl' ? '←' : '→' }}</a>
            </td>
        </tr>
    </table>
@endforeach
