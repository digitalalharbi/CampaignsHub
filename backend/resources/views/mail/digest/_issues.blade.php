{{--
  The ranked «needs a decision» list: rank, chip, WHERE, what, the problem drawn, ONE button. Needs $items, $showProject, $from (rank offset).

  EMAIL-CLIENT-COMPAT-001 — two columns with FIXED widths: the leading figure column is 76px (its band included), the text column
  takes the rest. The button used to be a third 84px column with `display:block` on the link: in the Gmail app its label wrapped
  («افتح / التنبيهات») and squeezed the text column until a platform name broke one word per line («تيك / توك»). It is now a
  bullet-proof cell under the text, on the end side, `white-space:nowrap`; the WHERE pieces are each `nowrap` + `keep-all`, so a
  name wraps between pieces and never inside one.
--}}
@php($S = \App\Domains\Notifications\Support\DigestStatus::class)
@foreach ($items as $n => $d)
    @php($state = $d['state'])
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="{{ $S::SOFT[$state] }}" data-block="issue" data-state="{{ $state }}" data-reason="{{ $d['reason'] }}" style="width:100%; table-layout:fixed; background-color:{{ $S::SOFT[$state] }}; border-radius:10px; margin-bottom:6px;">
        <tr>
            {{-- The leading edge: the objective's own figure (big value, small metric name, delta) on the state's colour band — or the chip when the finding has no figure. --}}
            <td width="76" valign="middle" align="center" bgcolor="{{ $S::CELL[$state] }}" style="width:72px; padding:6px 0; text-align:center; background-color:{{ $S::CELL[$state] }}; border-{{ $startSide }}:4px solid {{ $S::INK[$state] }}; border-radius:{{ $startSide === 'right' ? '0 10px 10px 0' : '10px 0 0 10px' }};">
                @if (! empty($d['fig']) && ! $d['fig']['missing'])
                    <div style="font-size:15px; line-height:18px; font-weight:800; color:{{ $S::INK[$state] }}; white-space:nowrap;" dir="ltr">{{ $d['fig']['value'] }}</div>
                    <div style="font-size:10px; line-height:13px; color:#5b6b68;">{{ $d['fig']['label'] }}</div>
                    @if ($d['fig']['change'])<div style="font-size:11px; line-height:14px; font-weight:800; color:{{ $d['fig']['colour'] }}; white-space:nowrap;" dir="ltr">{{ $d['fig']['change'] }}</div>@endif
                @else
                    @include('mail.digest._chip', ['state' => $state, 'size' => 24])
                @endif
            </td>
            <td valign="top" style="padding:6px 8px 8px; word-break:keep-all;">
                @if (($showProject ?? true) || $d['platform'] !== '')
                    <div style="font-size:13px; font-weight:800; color:#0b1a16; line-height:18px; word-break:keep-all;">@include('mail.digest._chip', ['state' => $state, 'size' => 16]) @if ($showProject ?? true)<span style="white-space:nowrap;">{{ $d['project'] }}</span>@endif @if (($showProject ?? true) && $d['platform'] !== '')<span style="color:#8b9a97; font-weight:400;">·</span> @endif @if ($d['platform'] !== '')<span style="white-space:nowrap;">{{ $d['platform'] }}</span>@endif @if (! empty($d['objective']))<span style="display:inline-block; padding:0 7px; border-radius:999px; font-size:10px; line-height:16px; font-weight:800; background-color:#e8f5f1; color:#0a6b57; white-space:nowrap;">{{ $d['objective'] }}</span>@endif</div>
                @endif
                <div style="font-size:13px; font-weight:700; color:{{ $S::INK[$state] }}; line-height:20px;">{{ $d['headline'] }}</div>
                @if (! empty($d['cause']))<div data-block="cause" style="font-size:12px; color:#3d4742; line-height:18px;">{{ $d['cause'] }}</div>@endif
                {{-- ALERT-VISUAL-EVERY-CARD-001 — always a graphic: the finding's own drawing, or the ring when it has none. --}}
                @include('mail.partials.visual', ['viz' => $d['viz'], 'state' => $state, 'timeline' => $d['timeline'] ?? null, 'size' => 26])
                @if (! $d['viz'] && $d['figure'] !== '')
                    <div style="font-size:12px; color:#3d4742; line-height:18px;" dir="auto">{{ $d['figure'] }}</div>
                @endif
                @include('mail.partials.card-button', ['href' => $d['url'], 'label' => $d['button'].' '.($dir === 'rtl' ? '←' : '→'), 'ink' => $S::INK[$state], 'top' => 6])
            </td>
        </tr>
    </table>
@endforeach
