{{--
  Everything one sweep found, in one message — MAIL-013.

  This replaced one email per finding. Four separate emails arriving in the same second are not four
  times the attention; they are a filter rule. The cards below carry the same shape as
  `operational.blade.php` — context, headline, what it means — because a reader who has learnt to
  read one alert should not have to learn a second layout to read three.

  The same email rules as everywhere: tables, inline styles, no external images, explicit
  backgrounds so a dark-mode client cannot invert a card into black-on-black. See `layout.blade.php`.
--}}
@php($tone = ['critical' => '#b91c1c', 'warning' => '#b45309', 'positive' => '#0d8a6f', 'info' => '#5b6b68'])
@php($toneBg = ['critical' => '#fef2f2', 'warning' => '#fffbeb', 'positive' => '#e8f5f1', 'info' => '#faf8f3'])

<div dir="{{ $dir }}" style="font-family:{!! $font !!}; color:#0b1a16; text-align:{{ $startSide }};">

    @php($compact = count($items) >= 3)
    {{-- EMAIL-DECISION-REPORT-001 — how many need a decision, by severity, before the cards: the portfolio-health strip of an alert mail. --}}
    @php($bySeverity = array_count_values(array_map(static fn (array $i): string => (string) ($i['severity'] ?? 'info'), $items)))
    @if (count($items) > 1)
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" data-block="severity-strip" style="margin:0 0 16px;">
            <tr>
                @foreach (['critical', 'warning', 'positive', 'info'] as $level)
                    @if (($bySeverity[$level] ?? 0) > 0)
                        <td style="padding-{{ $endSide }}:8px;">
                            <span style="display:inline-block; padding:5px 12px; border-radius:999px; font-size:12px; font-weight:700; background-color:{{ $toneBg[$level] }}; color:{{ $tone[$level] }};" dir="ltr">{{ $bySeverity[$level] }} {{ $level === 'critical' ? ($dir === 'rtl' ? 'حرج' : 'critical') : ($level === 'warning' ? ($dir === 'rtl' ? 'تحذير' : 'warning') : ($level === 'positive' ? ($dir === 'rtl' ? 'إيجابي' : 'positive') : ($dir === 'rtl' ? 'للعلم' : 'info'))) }}</span>
                        </td>
                    @endif
                @endforeach
            </tr>
        </table>
    @endif

    @foreach ($items as $item)
        @php($colour = $tone[$item['severity']] ?? $tone['info'])
        @php($background = $toneBg[$item['severity']] ?? $toneBg['info'])

        {{--
          One table per finding rather than one table with many rows.

          Outlook collapses the border-radius and the left rule on nested rows, and a card that loses
          its severity colour loses the only signal that separates «worth reading» from «act today».
        --}}
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
               style="background-color:{{ $background }}; border-radius:12px; margin-bottom:12px;">
            <tr>
                <td style="padding:{{ $compact ? 12 : 16 }}px 18px; border-{{ $startSide }}:4px solid {{ $colour }}; border-radius:12px;">
                    @if ($item['context'] !== '')
                        <div style="font-size:12px; font-weight:700; color:#5b6b68; text-transform:uppercase; letter-spacing:0.4px;">{{ $item['context'] }}</div>
                    @endif
                    <div style="font-size:16px; font-weight:800; color:#0b1a16; padding-top:4px; line-height:1.5;">{{ $item['title'] }}</div>
                    {{--
                      EMAIL-PERF-DASHBOARDS-001 — the finding's own number first, then the sentence.
                      One lead figure at display size and the rest as a compact strip, the hierarchy the
                      digest and the web's Platform view use; absent when the rule carries no figure.
                    --}}
                    @if (! empty($item['figures']))
                        <div style="margin-top:10px;">
                            <div style="font-size:12px; color:#5b6b68;">{{ $item['figures']['lead']['label'] }}</div>
                            <div class="ch-kpi-lead" style="font-size:24px; line-height:1.15; font-weight:800; color:#0b1a16; text-align:{{ $startSide }};" dir="ltr">{{ $item['figures']['lead']['value'] }}</div>
                        </div>
                        @if (! empty($item['figures']['strip']))
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:8px;">
                                <tr>
                                    @foreach ($item['figures']['strip'] as $card)
                                        <td class="ch-kpi" width="{{ (int) (100 / count($item['figures']['strip'])) }}%" style="vertical-align:top; text-align:center; padding:0 6px;">
                                            <div style="font-size:11px; color:#5b6b68;">{{ $card['label'] }}</div>
                                            <div style="font-size:15px; font-weight:700; color:#0b1a16;" dir="ltr">{{ $card['value'] }}</div>
                                        </td>
                                    @endforeach
                                </tr>
                            </table>
                        @endif
                    @endif
                    @if (! empty($item['viz']))
                        @include('mail.digest._viz', ['viz' => $item['viz']])
                    @endif
                    @if (! $compact && $item['detail'] !== '')
                        <div style="font-size:13px; color:#3d4742; line-height:1.7; padding-top:8px;">{{ $item['detail'] }}</div>
                    @endif
                    @if (! empty($item['numbers']) && empty($item['viz']))
                        {{-- EMAIL-DIGESTS-OPERATIONAL-001 — the numbers as a line of their own: before → now, and the threshold that fired. --}}
                        <div data-block="numbers" style="font-size:13px; font-weight:700; color:#0b1a16; padding-top:4px; direction:{{ $dir }}; unicode-bidi:plaintext;">{{ $item['numbers'] }}</div>
                    @endif
                    @if (! empty($item['action']))
                        {{-- The decision this card asks for, and the one place to take it. --}}
                        <div data-block="action" style="font-size:13px; font-weight:700; color:#0b1a16; padding-top:10px;">{{ $item['action'] }}</div>
                    @endif
                    @if (! empty($item['url']))
                        <div style="padding-top:8px;">
                            <a href="{{ $item['url'] }}" style="display:inline-block; background-color:#ffffff; color:{{ $colour }}; border:1px solid {{ $colour }}; font-size:12px; font-weight:700; text-decoration:none; padding:7px 14px; border-radius:8px;">{{ ($item['button'] ?? null) ? $item['button'].($dir === 'rtl' ? ' ←' : ' →') : ($dir === 'rtl' ? 'افتح التنبيه ←' : 'Open the alert →') }}</a>
                        </div>
                    @endif
                    @if (! empty($item['secondary']))
                        {{-- The campaign's name, as stored and truncated: a secondary line, never the headline. --}}
                        <div data-block="secondary" style="font-size:11px; color:#8b9a97; padding-top:8px; line-height:1.6; unicode-bidi:plaintext;">{{ $item['secondary'] }}</div>
                    @endif
                </td>
            </tr>
        </table>
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
