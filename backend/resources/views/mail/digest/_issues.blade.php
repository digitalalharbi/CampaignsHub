{{-- The ranked «needs a decision» list: rank, chip, WHERE, what, the problem drawn, ONE button. Needs $items, $showProject, $from (rank offset). --}}
@foreach ($items as $n => $d)
    @php($state = $d['state'])
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="issue" data-state="{{ $state }}" data-reason="{{ $d['reason'] }}" style="background-color:{{ \App\Domains\Notifications\Support\DigestStatus::SOFT[$state] }}; border-radius:10px; margin-bottom:6px;">
        <tr>
            {{-- The leading edge: the objective's own figure (big value, small metric name, delta) on the state's colour band — or the chip when the finding has no figure. --}}
            <td width="76" valign="middle" align="center" style="padding:6px 4px; text-align:center; background-color:{{ \App\Domains\Notifications\Support\DigestStatus::CELL[$state] }}; border-{{ $startSide }}:4px solid {{ \App\Domains\Notifications\Support\DigestStatus::INK[$state] }}; border-radius:{{ $startSide === 'right' ? '10px 0 0 10px' : '10px 0 0 10px' }};">
                @if (! empty($d['fig']) && ! $d['fig']['missing'])
                    <div style="font-size:15px; line-height:1.2; font-weight:800; color:{{ \App\Domains\Notifications\Support\DigestStatus::INK[$state] }};" dir="ltr">{{ $d['fig']['value'] }}</div>
                    <div style="font-size:10px; line-height:1.3; color:#5b6b68;">{{ $d['fig']['label'] }}</div>
                    @if ($d['fig']['change'])<div style="font-size:11px; font-weight:800; color:{{ $d['fig']['colour'] }};" dir="ltr">{{ $d['fig']['change'] }}</div>@endif
                @else
                    @include('mail.digest._chip', ['state' => $state, 'size' => 24])
                @endif
            </td>
            <td valign="top" style="padding:6px 8px;">
                @if (($showProject ?? true) || $d['platform'] !== '')
                    <div style="font-size:13px; font-weight:800; color:#0b1a16; line-height:1.4;">@include('mail.digest._chip', ['state' => $state, 'size' => 16]) {{ ($showProject ?? true) ? $d['project'] : '' }}@if (($showProject ?? true) && $d['platform'] !== '') <span style="color:#8b9a97; font-weight:400;">·</span> @endif{{ $d['platform'] }}@if (! empty($d['objective'])) <span style="display:inline-block; padding:0 7px; border-radius:999px; font-size:10px; font-weight:800; background-color:#e8f5f1; color:#0a6b57;">{{ $d['objective'] }}</span>@endif</div>
                @endif
                <div style="font-size:13px; font-weight:700; color:{{ \App\Domains\Notifications\Support\DigestStatus::INK[$state] }}; line-height:1.5;">{{ $d['headline'] }}</div>
                @if (! empty($d['cause']))<div data-block="cause" style="font-size:12px; color:#3d4742; line-height:1.5;">{{ $d['cause'] }}</div>@endif
                {{-- ALERT-VISUAL-EVERY-CARD-001 — always a graphic: the finding's own drawing, or the ring when it has none. --}}
                @include('mail.partials.visual', ['viz' => $d['viz'], 'state' => $state, 'timeline' => $d['timeline'] ?? null, 'size' => 26])
                @if (! $d['viz'] && $d['figure'] !== '')
                    <div style="font-size:12px; color:#3d4742; line-height:1.5;" dir="auto">{{ $d['figure'] }}</div>
                @endif
            </td>
            <td width="84" valign="middle" style="padding:8px 8px 8px 0; text-align:center;">
                <a href="{{ $d['url'] }}" style="display:block; background-color:#ffffff; color:{{ \App\Domains\Notifications\Support\DigestStatus::INK[$state] }}; border:1px solid {{ \App\Domains\Notifications\Support\DigestStatus::INK[$state] }}; font-size:12px; font-weight:700; text-decoration:none; padding:6px 2px; border-radius:8px; line-height:1.3;">{{ $d['button'] }} {{ $dir === 'rtl' ? '←' : '→' }}</a>
            </td>
        </tr>
    </table>
@endforeach
