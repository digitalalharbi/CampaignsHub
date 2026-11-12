{{--
  Everything one sweep found, in one message — MAIL-013.

  This replaced one email per finding. Four separate emails arriving in the same second are not four
  times the attention; they are a filter rule. The cards below carry the same shape as
  `operational.blade.php` — context, headline, what it means — because a reader who has learnt to
  read one alert should not have to learn a second layout to read three.

  The same email rules as everywhere: tables, inline styles, no external images, explicit
  backgrounds so a dark-mode client cannot invert a card into black-on-black. See `layout.blade.php`.
--}}
@php($S = \App\Domains\Notifications\Support\DigestStatus::class)

<div dir="{{ $dir }}" style="font-family:{!! $font !!}; color:#0b1a16; text-align:{{ $startSide }};">

    {{-- How many need a decision, as the digest counts them (✕ ! ✓ –), before the ranked cards. --}}
    @php($byState = array_count_values(array_map(static fn (array $i): string => (string) ($i['chip']['state'] ?? 'none'), $items)))
    @if (count($items) > 1)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="severity-strip" style="margin:0 0 12px; table-layout:fixed;">
            <tr>
                @foreach (['bad', 'watch', 'ok', 'none'] as $k)
                    @if (($byState[$k] ?? 0) > 0)
                        <td style="padding-{{ $endSide }}:4px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                                <td align="center" bgcolor="{{ $S::CELL[$k] }}" style="background-color:{{ $S::CELL[$k] }}; border-radius:10px; padding:6px 2px; text-align:center;">
                                    <div style="font-size:11px; font-weight:700; color:{{ $S::INK[$k] }};">{{ $S::GLYPH[$k] }} {{ \App\Domains\Notifications\Support\AlertVisual::chip(['bad' => 'critical', 'watch' => 'warning', 'ok' => 'positive', 'none' => 'info'][$k], $dir === 'rtl')['label'] }}</div>
                                    <div style="font-size:20px; font-weight:800; color:{{ $S::INK[$k] }};" dir="ltr">{{ $byState[$k] }}</div>
                                </td>
                            </tr></table>
                        </td>
                    @endif
                @endforeach
            </tr>
        </table>
    @endif

    {{-- DIGESTS-CADENCE-BUDGETS-001 — every card in the approved digest grammar: chip, drawn problem, one line, ordered steps, one button. --}}
    @foreach ($items as $item)
        @include('mail.partials.alert-card', ['c' => $item, 'big' => count($items) === 1])
    @endforeach

    {{-- One button, and it says what it does. «اضغط هنا» tells a reader nothing about where they land. --}}
    @include('mail.partials.button', ['href' => $actionUrl, 'label' => $actionLabel])

    {{--
      Why this arrived. An unexplained email is one a person marks as spam rather than unsubscribes.

      The shell's own sentence is «you follow THIS PROJECT», which was written for a message about
      one. Found by rendering the bundle: three findings across two different clients sat above a
      line claiming they were all about one project. A bulletin says «these projects», and when it
      genuinely carries one finding it says the singular again.
    --}}
    @php($single = count($items) === 1 || count(array_unique(array_column($items, 'context'))) === 1)
    <div style="font-size:12px; color:#8b9a97; line-height:1.7; margin-top:20px;">
        {{ $single
            ? $t['why']
            : ($dir === 'rtl'
                ? 'وصلتك لأنك تتابع هذه المشاريع.'
                : 'Sent because you follow these projects.') }}
    </div>
</div>
