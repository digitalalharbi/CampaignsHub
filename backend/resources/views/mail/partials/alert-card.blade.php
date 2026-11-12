{{--
  One alert or notification in the approved digest grammar — DIGESTS-CADENCE-BUDGETS-001.

  Status chip + coloured edge · where · the headline · the problem DRAWN · ONE short line · the next steps in order, with
  arrows · ONE button to the exact screen. No paragraph. Needs $c (`AlertVisual::present()`), $dir, $startSide; optional $big.
--}}
@php($S = \App\Domains\Notifications\Support\DigestStatus::class)
@php($state = $c['chip']['state'])
@php($big = $big ?? false)
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="alert-card" data-state="{{ $state }}" data-rule="{{ $c['rule'] ?? '' }}"
       style="background-color:{{ $S::SOFT[$state] }}; border-radius:12px; margin-bottom:10px;">
    <tr>
        <td style="padding:12px 16px; border-{{ $startSide }}:4px solid {{ $S::INK[$state] }}; border-radius:12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                <td style="white-space:nowrap;"><span data-chip="status" style="display:inline-block; padding:2px 10px; border-radius:999px; font-size:11px; font-weight:800; background-color:{{ $S::CELL[$state] }}; color:{{ $S::INK[$state] }};">{{ $c['chip']['glyph'] }} {{ $c['chip']['label'] }}</span></td>
                @if (($c['context'] ?? '') !== '')
                    <td style="padding-{{ $startSide }}:8px; font-size:12px; font-weight:700; color:#5b6b68; text-align:{{ $startSide }};">{{ $c['context'] }}</td>
                @endif
            </tr></table>
            <div style="font-size:{{ $big ? 18 : 16 }}px; font-weight:800; color:#0b1a16; padding-top:6px; line-height:1.5;">{{ $c['title'] }}</div>
            @include('mail.partials.visual', ['viz' => $c['viz'], 'state' => ($c['rule'] ?? '') === 'recovered' ? 'ok' : $state, 'timeline' => $c['timeline'] ?? null])
            {{-- The figures behind the drawing, small, under it: amounts with their currency, the limit, the date of the last figures. --}}
            @if (! empty($c['figures']['lead']))
                <div data-block="figures" style="font-size:12px; color:#3d4742; padding-top:4px; unicode-bidi:plaintext;">
                    {{-- Beside a plan → spend drawing the pace ratio would print one finding twice («167%» beside «80%»); the amounts stay. --}}
                    @php($withLead = ($c['viz']['type'] ?? null) !== 'pace')
                    @if ($withLead)<span dir="ltr" style="font-weight:800;">{{ $c['figures']['lead']['value'] }}</span> {{ $c['figures']['lead']['label'] }}@endif @foreach ((array) ($c['figures']['strip'] ?? []) as $i => $f){{ $i > 0 || $withLead ? ' · ' : '' }}{{ $f['label'] }} <span dir="ltr" style="font-weight:700;">{{ $f['value'] }}</span>@endforeach
                </div>
            @elseif (($c['numbers'] ?? '') !== '')
                <div data-block="numbers" style="font-size:12px; color:#3d4742; padding-top:4px; unicode-bidi:plaintext;">{{ $c['numbers'] }}</div>
            @endif
            @if (($c['line'] ?? '') !== '')
                <div data-block="line" style="font-size:13px; color:#3d4742; line-height:1.6; padding-top:6px;">{{ $c['line'] }}</div>
            @endif
            @if (! empty($c['steps']))
                {{-- The decision, in order: 1 ← 2 ← 3. --}}
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" data-block="steps" style="margin-top:8px;">
                    <tr>
                        @foreach ($c['steps'] as $n => $step)
                            @if ($n > 0)<td style="padding:0 4px; font-size:13px; font-weight:800; color:{{ $S::INK[$state] }};">{{ $dir === 'rtl' ? '←' : '→' }}</td>@endif
                            <td style="padding:0;"><span style="display:inline-block; padding:3px 8px; border-radius:8px; border:1px solid #e4e0d6; background-color:#ffffff; font-size:12px; font-weight:700; color:#0b1a16;"><span dir="ltr" style="color:{{ $S::INK[$state] }};">{{ $n + 1 }}</span> {{ $step }}</span></td>
                        @endforeach
                    </tr>
                </table>
            @endif
            @if (! empty($c['url']))
                <div style="padding-top:10px;">
                    <a href="{{ $c['url'] }}" data-block="card-button" style="display:inline-block; background-color:#ffffff; color:{{ $S::INK[$state] }}; border:1px solid {{ $S::INK[$state] }}; font-size:12px; font-weight:700; text-decoration:none; padding:7px 14px; border-radius:8px;">{{ ($c['button'] ?? null) ?: ($dir === 'rtl' ? 'افتح الصفحة' : 'Open the page') }} {{ $dir === 'rtl' ? '←' : '→' }}</a>
                </div>
            @endif
            @if (! empty($c['secondary']))
                <div data-block="secondary" style="font-size:11px; color:#8b9a97; padding-top:8px; line-height:1.6; unicode-bidi:plaintext;">{{ $c['secondary'] }}</div>
            @endif
        </td>
    </tr>
</table>
