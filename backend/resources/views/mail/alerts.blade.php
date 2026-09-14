{{--
  Everything one sweep found, in one message — MAIL-013.

  This replaced one email per finding. Four separate emails arriving in the same second are not four
  times the attention; they are a filter rule. The cards below carry the same shape as
  `operational.blade.php` — context, headline, what it means — because a reader who has learnt to
  read one alert should not have to learn a second layout to read three.

  The same email rules as everywhere: tables, inline styles, no external images, explicit
  backgrounds so a dark-mode client cannot invert a card into black-on-black. See `layout.blade.php`.
--}}
@php($tone = ['critical' => '#b91c1c', 'warning' => '#b45309', 'positive' => '#0f766e', 'info' => '#5b6b68'])
@php($toneBg = ['critical' => '#fef2f2', 'warning' => '#fffbeb', 'positive' => '#ecfdf5', 'info' => '#f8fafa'])

<div style="font-family:{!! $font !!}; color:#0f172a;">

    <div style="font-size:15px; color:#5b6b68; margin:0 0 6px;">{{ $greeting }}</div>
    <div style="font-size:14px; color:#334155; line-height:1.8; margin:0 0 18px;">{{ $intro }}</div>

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
                <td style="padding:16px 18px; border-{{ $startSide }}:4px solid {{ $colour }}; border-radius:12px;">
                    @if ($item['context'] !== '')
                        <div style="font-size:12px; font-weight:700; color:#5b6b68; text-transform:uppercase; letter-spacing:0.4px;">{{ $item['context'] }}</div>
                    @endif
                    <div style="font-size:16px; font-weight:800; color:#0f172a; padding-top:4px; line-height:1.5;">{{ $item['title'] }}</div>
                    <div style="font-size:14px; color:#334155; line-height:1.8; padding-top:8px;">{{ $item['detail'] }}</div>
                </td>
            </tr>
        </table>
    @endforeach

    {{-- One button, and it says what it does. «اضغط هنا» tells a reader nothing about where they land. --}}
    <div style="padding-top:8px;">
        <a href="{{ $actionUrl }}"
           style="display:inline-block; background-color:#0f766e; color:#ffffff; font-size:14px; font-weight:700;
                  text-decoration:none; padding:11px 20px; border-radius:8px;">{{ $actionLabel }}</a>
    </div>

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
                ? 'وصلتك هذه الرسالة لأنك تتابع هذه المشاريع في CampaignsHub.'
                : 'You are receiving this because you follow these projects in CampaignsHub.') }}
    </div>
</div>
