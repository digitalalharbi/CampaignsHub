{{--
  The shell every CampaignsHub email is built in — MAIL-002.

  ## Why this is written the way it is, and not the way a web page is

  An email client is not a browser. Outlook renders with Word's engine, Gmail strips the `<head>`
  on forwards, and every one of them disagrees about flexbox. So this template is deliberately
  old-fashioned, and each choice is a bug that would otherwise be found by a customer:

  - **Tables, not flex or grid.** A layout that collapses in Outlook is not a styling issue; it is
    a report nobody can read.
  - **Inline styles.** Gmail keeps the `<style>` block on first view and drops it when the message
    is forwarded or clipped, so anything that matters is on the element itself. The block below
    carries only the media query, which cannot be inlined — and everything it changes is a
    refinement, never the difference between legible and not.
  - **No external images.** Most clients block them by default, so a logo would be a grey box and a
    chart would be nothing at all. The identity is type and colour, both of which always render.
  - **`dir` on `<html>`.** Set from the reader's own language, because a right-to-left email laid
    out left-to-right is harder to read than an untranslated one.
  - **`color-scheme` and explicit backgrounds.** Dark-mode clients invert what they are not told
    about; stating both the background and the text colour on the same element stops a card turning
    black-on-black in Apple Mail.

  Widths are absolute for the same reason: 600px is what every client has agreed on for twenty
  years, and the media query below is the only thing that touches it.
--}}
{{--
  `{!! $font !!}`, not `{{ $font }}`, and it matters.

  The stack contains quoted family names — `'SF Arabic'`, `'Noto Sans Arabic'` — because a CSS family
  with spaces needs them. Blade's escaping turns each into `&#039;`, and while a browser decodes that
  inside a style attribute, email clients are not browsers: Outlook in particular does not, so the
  whole stack becomes unparseable and every glyph falls back to the client's default. Which is the
  precise failure the Arabic stack exists to prevent.

  Unescaped is safe here because the value is a config constant. Nothing user-supplied reaches it.
--}}
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="color-scheme" content="light dark" />
    <meta name="supported-color-schemes" content="light dark" />
    <title>{{ $subject }}</title>
    <style>
        /* The only rule that cannot be inlined. Everything it changes is a refinement. */
        @media only screen and (max-width: 620px) {
            .ch-wrap { width: 100% !important; }
            .ch-pad { padding-left: 16px !important; padding-right: 16px !important; }
            .ch-stack { display: block !important; width: 100% !important; }
            .ch-kpi { display: block !important; width: 100% !important; padding-bottom: 12px !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#f1f5f4; -webkit-text-size-adjust:100%;">
    {{-- The preview line email clients show beside the subject. Hidden in the body itself. --}}
    <div style="display:none; max-height:0; overflow:hidden; opacity:0;">{{ $preheader ?? '' }}</div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f1f5f4;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" class="ch-wrap" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px; background-color:#ffffff; border-radius:14px; overflow:hidden; border:1px solid #e2e8e6;">

                    {{-- Header: the brand as type and colour, because an image would be blocked. --}}
                    <tr>
                        <td class="ch-pad" style="background-color:#0f766e; padding:20px 24px;">
                            <div style="font-family:{!! $font !!}; font-size:18px; font-weight:800; color:#ffffff; letter-spacing:-0.2px;">
                                {{ $brand }}
                            </div>
                            <div style="font-family:{!! $font !!}; font-size:13px; color:#c6f0e9; padding-top:2px;">
                                {{ $headerNote }}
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td class="ch-pad" style="padding:24px;">
                            {!! $slot !!}
                        </td>
                    </tr>

                    {{--
                      The footer carries the three policies every portal footer carries, and the way
                      out. An unsubscribe a person cannot find is how a useful digest becomes spam.
                    --}}
                    <tr>
                        <td class="ch-pad" style="padding:16px 24px; background-color:#f8fafa; border-top:1px solid #e2e8e6;">
                            <div style="font-family:{!! $font !!}; font-size:12px; color:#5b6b68; line-height:1.7;">
                                <a href="{{ $urls['preferences'] }}" style="color:#0f766e; text-decoration:underline;">{{ $t['manage_preferences'] }}</a>
                                &nbsp;·&nbsp;
                                <a href="{{ $urls['privacy'] }}" style="color:#5b6b68; text-decoration:underline;">{{ $t['privacy'] }}</a>
                                &nbsp;·&nbsp;
                                <a href="{{ $urls['terms'] }}" style="color:#5b6b68; text-decoration:underline;">{{ $t['terms'] }}</a>
                                &nbsp;·&nbsp;
                                <a href="{{ $urls['security'] }}" style="color:#5b6b68; text-decoration:underline;">{{ $t['security'] }}</a>
                            </div>
                            <div style="font-family:{!! $font !!}; font-size:12px; color:#8b9a97; padding-top:8px;">
                                © {{ $year }} {{ $brand }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
