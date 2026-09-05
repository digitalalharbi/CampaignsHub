{{--
  The daily digest — MAIL-002.

  Every block answers the same four questions in the same order: what happened, why it matters, what
  changed, what to do. The verdict line comes FIRST inside each project, before its figures, because
  a reader scanning on a phone at 8am reads one line per project and opens the one that asks for them.

  Nothing here decides whether a number exists. `DigestPresenter` has already turned every figure
  into a string — «12,400 SAR», «لم ترسله المنصة», «لا توجد بيانات» — so there is no `?? 0` left in
  this file to get wrong.
--}}
@php($tone = ['good' => '#0f766e', 'warn' => '#b45309', 'bad' => '#b91c1c', 'neutral' => '#5b6b68'])
@php($toneBg = ['good' => '#ecfdf5', 'warn' => '#fffbeb', 'bad' => '#fef2f2', 'neutral' => '#f8fafa'])

<div style="font-family:{!! $font !!}; color:#0f172a;">

    <div style="font-size:20px; font-weight:800; color:#0f172a; margin:0 0 4px;">{{ $t['greeting'] }}</div>
    <div style="font-size:14px; color:#5b6b68; margin:0 0 20px;">{{ $t['intro'] }}</div>

    {{-- The account line. Spend and results sum across projects; a cost per result deliberately does not. --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
           style="background-color:#f8fafa; border:1px solid #e2e8e6; border-radius:12px; margin-bottom:20px;">
        <tr>
            <td style="padding:14px 16px;">
                <div style="font-size:12px; font-weight:700; color:#5b6b68; text-transform:uppercase; letter-spacing:0.4px;">{{ $t['account_total'] }}</div>
                {{--
                  EMAIL-DASHBOARD-UX-001 — a KPI card carries its MOVEMENT.

                  Three figures with no comparison told a reader what happened and nothing about
                  whether it was normal. Each card now shows the change against the previous window
                  of the same length, coloured by whether it is good news — and shows NO pill where
                  the previous window was zero, because every rise from nothing is infinite.

                  The cards come from the mailable, so their number varies: revenue is dropped
                  entirely when nothing reported any, rather than printed as a zero a lead-generation
                  account would read as a loss.
                --}}
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:8px;">
                    <tr>
                        @foreach ($totals as $card)
                            <td class="ch-kpi" width="{{ (int) (100 / max(1, count($totals))) }}%" style="vertical-align:top;">
                                <div style="font-size:12px; color:#5b6b68;">{{ $card['label'] }}</div>
                                <div style="font-size:20px; font-weight:800; color:#0f172a;" dir="ltr">{{ $card['value'] }}</div>
                                @if ($card['change'])
                                    <div style="font-size:11px; font-weight:700; color:{{ $tone[$card['tone']] }};" dir="ltr">{{ $card['change'] }}</div>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                </table>
                {{--
                  Stated, not implied. A reader who sees three account-wide figures will look for a
                  fourth — and the fourth they want is a cost per result, which across projects would
                  divide one client's money by another client's orders.
                --}}
                <div style="font-size:11px; color:#8b9a97; padding-top:10px; line-height:1.6;">{{ $t['no_blended_note'] }}</div>
            </td>
        </tr>
    </table>

    {{--
      EMAIL-DASHBOARD-UX-001 — the two ends, before the middle.

      A person reading on a phone at 8am wants what rose most and what fell most; a list of twelve
      projects in alphabetical order is a list nobody reads to the bottom. Movement is measured on
      RESULTS rather than spend, because spending more is not an improvement and a digest that
      celebrates it rewards the wrong behaviour.

      Absent entirely when there is only one project with a comparison: «best of one» is a ranking of
      nothing, and printing it as a highlight teaches a reader that the highlights mean nothing.
    --}}
    @if (($movement['best'] ?? null) || ($movement['worst'] ?? null))
        <div style="font-size:12px; font-weight:700; color:#5b6b68; text-transform:uppercase; letter-spacing:0.4px; margin-bottom:8px;">{{ $t['movement'] }}</div>
        @if ($movement['best'])
            <div style="margin-bottom:8px; padding:10px 12px; border-radius:10px; background-color:{{ $toneBg['good'] }};
                        border-{{ $startSide }}:3px solid {{ $tone['good'] }};">
                <div style="font-size:13px; font-weight:700; color:#0f172a;">{{ $movement['best']['name'] }} — {{ $t['best_move'] }}</div>
                <div style="font-size:12px; color:{{ $tone['good'] }};" dir="ltr">{{ $movement['best']['text'] }}</div>
            </div>
        @endif
        @if ($movement['worst'])
            <div style="margin-bottom:16px; padding:10px 12px; border-radius:10px; background-color:{{ $toneBg['bad'] }};
                        border-{{ $startSide }}:3px solid {{ $tone['bad'] }};">
                <div style="font-size:13px; font-weight:700; color:#0f172a;">{{ $movement['worst']['name'] }} — {{ $t['worst_move'] }}</div>
                <div style="font-size:12px; color:{{ $tone['bad'] }};" dir="ltr">{{ $movement['worst']['text'] }}</div>
            </div>
        @endif
    @endif

    @foreach ($projects as $p)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
               style="border:1px solid #e2e8e6; border-radius:12px; margin-bottom:16px;">
            <tr>
                <td style="padding:16px;">

                    <div style="font-size:16px; font-weight:800; color:#0f172a;">{{ $p['name'] }}</div>

                    {{-- The verdict, before the figures — this is the line a phone reader acts on. --}}
                    <div style="margin-top:8px; padding:10px 12px; border-radius:8px;
                                background-color:{{ $toneBg[$p['verdict']['tone']] }};
                                color:{{ $tone[$p['verdict']['tone']] }};
                                font-size:13px; line-height:1.6;">
                        {{ $p['verdict']['text'] }}
                    </div>

                    {{-- What happened, and what changed against the window before this one. --}}
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:14px;">
                        <tr>
                            @foreach ($p['kpis'] as $kpi)
                                <td class="ch-kpi" width="25%" style="vertical-align:top; padding-{{ $endSide }}:8px;">
                                    <div style="font-size:12px; color:#5b6b68;">{{ $kpi['label'] }}</div>
                                    <div style="font-size:17px; font-weight:700; color:#0f172a;" dir="ltr">{{ $kpi['value'] }}</div>
                                    @if ($kpi['change'])
                                        <div style="font-size:12px; font-weight:700; color:{{ $kpi['change_colour'] }};" dir="ltr">{{ $kpi['change'] }}</div>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    </table>

                    {{--
                      By marketing path — the block that keeps this honest about objectives.

                      Awareness money sits beside awareness results, and only the conversion path
                      carries a cost per order, because only it was bought to produce one.
                    --}}
                    @if ($p['paths'])
                        <div style="font-size:12px; font-weight:700; color:#5b6b68; text-transform:uppercase; letter-spacing:0.4px; margin-top:16px;">{{ $t['by_path'] }}</div>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:6px; font-size:13px;">
                            @foreach ($p['paths'] as $path)
                                <tr>
                                    <td width="28%" style="padding:6px 0; border-bottom:1px solid #eef2f1; color:#0f172a;">{{ $path['label'] }}</td>
                                    {{--
                                        The share of spend, drawn. Nested table with a percentage-width
                                        cell — the same email-safe technique the funnel below already
                                        uses, which is what proves it renders in the clients this
                                        product sends to. «Where did the money go» is read from bar
                                        lengths in one pass and from a column of figures in several.
                                    --}}
                                    <td width="30%" style="padding:6px 0; border-bottom:1px solid #eef2f1;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#eef2f1; border-radius:4px;">
                                            <tr><td width="{{ $path['width'] }}%" style="background-color:#0f766e; height:8px; line-height:8px; border-radius:4px; font-size:0;">&nbsp;</td><td>&nbsp;</td></tr>
                                        </table>
                                    </td>
                                    <td style="padding:6px 0; border-bottom:1px solid #eef2f1; color:#0f172a; text-align:{{ $endSide }};" dir="ltr">{{ $path['spend'] }}</td>
                                    <td style="padding:6px 0; border-bottom:1px solid #eef2f1; color:#5b6b68; text-align:{{ $endSide }};" dir="ltr">{{ $path['result'] }}</td>
                                </tr>
                            @endforeach
                        </table>
                    @endif

                    {{-- Best and worst, among the rows that HAVE a cost per result to be judged on. --}}
                    @if ($p['best'] || $p['worst'])
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:14px; font-size:13px;">
                            @if ($p['best'])
                                <tr>
                                    <td style="padding:4px 0; color:#0f766e; font-weight:700;">{{ $t['best'] }}</td>
                                    <td style="padding:4px 0; color:#0f172a;">{{ $p['best'] }}</td>
                                </tr>
                            @endif
                            @if ($p['worst'])
                                <tr>
                                    <td style="padding:4px 0; color:#b45309; font-weight:700;">{{ $t['worst'] }}</td>
                                    <td style="padding:4px 0; color:#0f172a;">{{ $p['worst'] }}</td>
                                </tr>
                            @endif
                        </table>
                    @endif

                    {{--
                      The funnel — MAIL-005.

                      Bars are table cells with a width, because a `div` with a percentage width is
                      the one layout Outlook renders as a full-width block. Stages nobody reported
                      are absent rather than drawn at zero: a bar of length nothing reads as a step
                      where everybody left.
                    --}}
                    @if ($p['funnel'])
                        <div style="font-size:12px; font-weight:700; color:#5b6b68; text-transform:uppercase; letter-spacing:0.4px; margin-top:16px;">{{ $t['funnel'] }}</div>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:6px; font-size:13px;">
                            @foreach ($p['funnel'] as $stage)
                                <tr>
                                    <td width="35%" style="padding:5px 0; color:#0f172a;">{{ $stage['label'] }}</td>
                                    <td width="40%" style="padding:5px 0;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#eef2f1; border-radius:4px;">
                                            <tr><td width="{{ $stage['width'] }}%" style="background-color:#0f766e; height:8px; line-height:8px; border-radius:4px; font-size:0;">&nbsp;</td><td>&nbsp;</td></tr>
                                        </table>
                                    </td>
                                    <td style="padding:5px 0; color:#0f172a; text-align:{{ $endSide }};" dir="ltr">{{ $stage['count'] }}</td>
                                </tr>
                            @endforeach
                        </table>
                    @endif

                    {{-- Content: what is working, and what has started to slip. --}}
                    @if ($p['creatives'])
                        <div style="font-size:12px; font-weight:700; color:#5b6b68; text-transform:uppercase; letter-spacing:0.4px; margin-top:16px;">{{ $t['content'] }}</div>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:6px; font-size:13px;">
                            @if ($p['creatives']['best'])
                                <tr>
                                    <td style="padding:4px 0; color:#0f766e; font-weight:700; white-space:nowrap;">{{ $t['best_content'] }}</td>
                                    <td style="padding:4px 0; color:#0f172a;">
                                        {{ $p['creatives']['best']['name'] }}
                                        @if ($p['creatives']['best']['reason'])
                                            <span style="color:#5b6b68;">— {{ $p['creatives']['best']['reason'] }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endif
                            @foreach ($p['creatives']['declining'] as $c)
                                <tr>
                                    <td style="padding:4px 0; color:#b45309; font-weight:700; white-space:nowrap;">{{ $t['declining'] }}</td>
                                    <td style="padding:4px 0; color:#0f172a;">{{ $c['name'] }}</td>
                                </tr>
                            @endforeach
                            @foreach ($p['creatives']['fatigued'] as $c)
                                <tr>
                                    <td style="padding:4px 0; color:#b45309; font-weight:700; white-space:nowrap;">{{ $t['fatigued'] }}</td>
                                    <td style="padding:4px 0; color:#0f172a;">{{ $c['name'] }}</td>
                                </tr>
                            @endforeach
                        </table>
                    @endif

                    {{--
                      EMAIL-SETTINGS-DEPTH-001 — approved recommendations, quoted, never generated.

                      Absent when the reader did not ask for them AND when there are none: the digest
                      does not announce a section somebody switched off, and an empty heading would
                      read as «nobody has any advice for you», which is a claim this has not made.
                    --}}
                    @if (!empty($p['recommendations']))
                        <div style="font-size:12px; font-weight:700; color:#5b6b68; text-transform:uppercase; letter-spacing:0.4px; margin-top:16px;">{{ $t['recommendations'] }}</div>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:6px; font-size:13px;">
                            @foreach ($p['recommendations'] as $r)
                                <tr>
                                    <td style="padding:4px 0; color:#0f172a;">
                                        <strong>{{ $r['title'] }}</strong>
                                        @if ($r['body'])
                                            <span style="color:#5b6b68;">— {{ $r['body'] }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </table>
                    @endif

                    {{--
                      EXECUTIVE-DAILY-DIGEST-001 — what happened AFTER the lead arrived.

                      Above the notes, because a manager reading this on a phone at 8am is asking
                      «is anybody being called», and below the money, because the money is what
                      produced them. Counts only: no name, no phone, no email travels in this mail.
                    --}}
                    @if ($p['follow_up'])
                        <div style="font-size:12px; font-weight:700; color:#5b6b68; text-transform:uppercase; letter-spacing:0.4px; margin-top:16px;">{{ $t['follow_up'] }}</div>

                        @if ($p['follow_up']['attention'])
                            {{-- What needs a person, before the figures that merely describe the day. --}}
                            <div style="margin-top:6px; padding:9px 11px; border-radius:8px; background-color:{{ $toneBg['warn'] }}; border-{{ $startSide }}:3px solid {{ $tone['warn'] }};">
                                @foreach ($p['follow_up']['attention'] as $item)
                                    <div style="font-size:13px; font-weight:700; color:#0f172a;">{{ $item }}</div>
                                @endforeach
                            </div>
                        @endif

                        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-top:8px;">
                            @foreach (array_chunk($p['follow_up']['rows'], 3) as $row)
                                <tr>
                                    @foreach ($row as $cell)
                                        <td width="33%" style="padding:6px 0;">
                                            <div style="font-size:11px; color:#8b9a97;">{{ $cell['label'] }}</div>
                                            <div style="font-size:15px; font-weight:700; color:#0f172a;">{{ $cell['value'] }}</div>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </table>

                        @if ($p['follow_up']['owners'])
                            {{-- Who, when there is more than one who. One owner is not a comparison. --}}
                            <div style="font-size:11px; color:#8b9a97; margin-top:10px;">{{ $t['by_owner'] }}</div>
                            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-top:4px;">
                                <tr>
                                    <td style="font-size:11px; color:#8b9a97; padding:3px 0;">{{ $t['owner'] }}</td>
                                    <td style="font-size:11px; color:#8b9a97; padding:3px 0;">{{ $t['received_short'] }}</td>
                                    <td style="font-size:11px; color:#8b9a97; padding:3px 0;">{{ $t['contacted_short'] }}</td>
                                    <td style="font-size:11px; color:#8b9a97; padding:3px 0;">{{ $t['overdue_short'] }}</td>
                                </tr>
                                @foreach ($p['follow_up']['owners'] as $owner)
                                    <tr>
                                        <td style="font-size:13px; color:#0f172a; padding:3px 0;">{{ $owner['name'] }}</td>
                                        <td style="font-size:13px; color:#0f172a; padding:3px 0;">{{ $owner['received'] }}</td>
                                        <td style="font-size:13px; color:#0f172a; padding:3px 0;">{{ $owner['contacted'] }}</td>
                                        <td style="font-size:13px; color:{{ $owner['overdue'] === '0' ? '#5b6b68' : $tone['warn'] }}; padding:3px 0;">{{ $owner['overdue'] }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        @endif
                    @endif

                    {{--
                      The notes: what happened, what it means, what to do.

                      Three at most. A fourth is not read, and a mail that lists ten alerts every
                      morning teaches its reader that the alerts mean nothing.
                    --}}
                    @if ($p['notes'])
                        <div style="font-size:12px; font-weight:700; color:#5b6b68; text-transform:uppercase; letter-spacing:0.4px; margin-top:16px;">{{ $t['notes'] }}</div>
                        @foreach ($p['notes'] as $note)
                            <div style="margin-top:6px; padding:9px 11px; border-radius:8px;
                                        background-color:{{ $toneBg[$note['tone']] }};
                                        border-{{ $startSide }}:3px solid {{ $tone[$note['tone']] }};">
                                <div style="font-size:13px; font-weight:700; color:#0f172a;">{{ $note['title'] }}</div>
                                <div style="font-size:12px; color:#5b6b68; line-height:1.6; padding-top:2px;">{{ $note['detail'] }}</div>
                            </div>
                        @endforeach
                    @endif

                    {{-- How old the figures are, beside the figures (§15.15). --}}
                    <div style="font-size:11px; color:#8b9a97; margin-top:12px;">{{ $p['freshness'] }}</div>

                    <div style="margin-top:14px;">
                        <a href="{{ $p['url'] }}"
                           style="display:inline-block; background-color:#0f766e; color:#ffffff; font-size:13px; font-weight:700;
                                  text-decoration:none; padding:9px 16px; border-radius:8px;">{{ $t['open_dashboard'] }}</a>
                    </div>
                </td>
            </tr>
        </table>
    @endforeach

    <div style="font-size:12px; color:#8b9a97; line-height:1.7; margin-top:4px;">{{ $t['footer_note'] }}</div>
</div>
