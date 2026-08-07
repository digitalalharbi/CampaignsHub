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

<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; color:#0f172a;">

    <div style="font-size:20px; font-weight:800; color:#0f172a; margin:0 0 4px;">{{ $t['greeting'] }}</div>
    <div style="font-size:14px; color:#5b6b68; margin:0 0 20px;">{{ $t['intro'] }}</div>

    {{-- The account line. Spend and results sum across projects; a cost per result deliberately does not. --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
           style="background-color:#f8fafa; border:1px solid #e2e8e6; border-radius:12px; margin-bottom:20px;">
        <tr>
            <td style="padding:14px 16px;">
                <div style="font-size:12px; font-weight:700; color:#5b6b68; text-transform:uppercase; letter-spacing:0.4px;">{{ $t['account_total'] }}</div>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:8px;">
                    <tr>
                        <td class="ch-kpi" width="33%" style="vertical-align:top;">
                            <div style="font-size:12px; color:#5b6b68;">{{ $t['spend'] }}</div>
                            <div style="font-size:20px; font-weight:800; color:#0f172a;" dir="ltr">{{ $totals['spend'] }}</div>
                        </td>
                        <td class="ch-kpi" width="33%" style="vertical-align:top;">
                            <div style="font-size:12px; color:#5b6b68;">{{ $t['results'] }}</div>
                            <div style="font-size:20px; font-weight:800; color:#0f172a;" dir="ltr">{{ $totals['conversions'] }}</div>
                        </td>
                        <td class="ch-kpi" width="33%" style="vertical-align:top;">
                            <div style="font-size:12px; color:#5b6b68;">{{ $t['projects'] }}</div>
                            <div style="font-size:20px; font-weight:800; color:#0f172a;" dir="ltr">{{ $totals['projects'] }}</div>
                        </td>
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

                    {{-- What happened, and what changed against yesterday. --}}
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
                                    <td style="padding:6px 0; border-bottom:1px solid #eef2f1; color:#0f172a;">{{ $path['label'] }}</td>
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
