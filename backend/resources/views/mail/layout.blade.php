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
        /* Gmail / Apple Mail dark mode: the band is dark by design and must stay so. */
        @media (prefers-color-scheme: dark) {
            .ch-hdr { background-color: #0b1a16 !important; }
            .ch-hdr-name { color: #f7f5f0 !important; }
            .ch-hdr-tag { color: #b7c9c2 !important; }
        }
        [data-ogsc] .ch-hdr { background-color: #0b1a16 !important; }
        [data-ogsc] .ch-hdr-name { color: #f7f5f0 !important; }
        [data-ogsc] .ch-hdr-tag { color: #b7c9c2 !important; }
        /* Progressive only: the button is already a centred, 44px-tall cell without this. */
        @media only screen and (max-width: 480px) {
            .ch-btn-wrap { width: 100% !important; }
            .ch-btn { display: block !important; text-align: center !important; }
        }
    </style>
</head>
@php($align = $dir === 'rtl' ? 'right' : 'left')
<body dir="{{ $dir }}" style="margin:0; padding:0; background-color:#f7f5f0; -webkit-text-size-adjust:100%; direction:{{ $dir }};">
    {{-- The preview line email clients show beside the subject. Hidden in the body itself. --}}
    <div style="display:none; max-height:0; overflow:hidden; opacity:0;">{{ $preheader ?? '' }}</div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f7f5f0" dir="{{ $dir }}" style="background-color:#f7f5f0;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" class="ch-wrap" align="center" width="600" cellpadding="0" cellspacing="0" border="0" dir="{{ $dir }}" style="margin:0 auto; width:600px; max-width:600px; background-color:#ffffff; border-radius:14px; overflow:hidden; border:1px solid #e4e0d6;">

                    {{--
                      Header — EMAIL-BRAND-002.

                      A full-width dark band: the transparent cream MARK as the only image, the wordmark as
                      LIVE TEXT. The old raster lockup was an opaque dark rectangle; on a pale or inverted
                      page its hard edges showed. Text always renders, so with images blocked the band still
                      reads as the brand. `bgcolor` + inline colour + the dark-mode rules keep the band
                      dark in Gmail dark mode, which otherwise re-inverts plain background colours.
                    --}}
                    <tr>
                        <td class="ch-hdr ch-pad" align="center" dir="{{ $dir }}" bgcolor="#0b1a16" style="background-color:#0b1a16; padding:28px 24px; border-bottom:3px solid #0b7a62; text-align:center;">
                            <a href="{{ $urls['home'] }}" style="text-decoration:none; display:block; text-align:center;">
                                <img src="{{ $logoUrl }}" width="56" height="56" alt="{{ $brand }}" style="display:block; margin:0 auto; border:0; outline:none; text-decoration:none; width:56px; height:56px; color:#f7f5f0; font-family:{!! $font !!}; font-size:14px;" />
                            </a>
                            <div class="ch-hdr-name" dir="auto" style="font-family:{!! $font !!}; font-size:22px; line-height:1.4; font-weight:700; color:#f7f5f0; text-align:center; padding-top:12px;">{!! $wordmark !!}</div>
                            <div class="ch-hdr-tag" style="font-family:{!! $font !!}; font-size:13px; line-height:1.6; color:#b7c9c2; text-align:center; padding-top:4px;">{{ $headerNote }}</div>
                            @if (!empty($scopeNote ?? ''))
                                <div class="ch-hdr-tag" style="font-family:{!! $font !!}; font-size:12px; color:#b7c9c2; padding-top:4px; text-align:center;">{{ $scopeNote }}</div>
                            @endif
                        </td>
                    </tr>

                    <tr>
                        <td class="ch-pad" dir="{{ $dir }}" align="{{ $align }}" bgcolor="#ffffff" style="padding:28px 24px; background-color:#ffffff; text-align:{{ $align }}; direction:{{ $dir }}; font-family:{!! $font !!}; font-size:14px; line-height:1.8; color:#0b1a16;">
                            {!! $slot !!}
                        </td>
                    </tr>

                    {{--
                      The footer carries the three policies every portal footer carries, and the way
                      out. An unsubscribe a person cannot find is how a useful digest becomes spam.

                      `$showPreferences` is FALSE for account messages — MAIL-009.

                      A password reset, a sign-in code and a security alert are not subscriptions, and
                      offering «إدارة إشعاراتك» beside one is an offer the product cannot honour: it
                      implies the reader could turn this off, and nothing in the preference centre
                      does or should. Worse, a person who follows that link from a security alert
                      leaves the one message that was asking them to act. The footnote below says
                      plainly why the message cannot be switched off instead.
                    --}}
                    <tr>
                        <td class="ch-pad" dir="{{ $dir }}" align="{{ $align }}" bgcolor="#faf8f3" style="padding:18px 24px; background-color:#faf8f3; border-top:1px solid #e4e0d6; text-align:{{ $align }}; direction:{{ $dir }};">
                            <div style="font-family:{!! $font !!}; font-size:12px; color:#5b6b68; line-height:1.7;">
                                @if (($showPreferences ?? true) === true)
                                    <a href="{{ $urls['preferences'] }}" style="color:#0d8a6f; text-decoration:underline;">{{ $t['manage_preferences'] }}</a>
                                    &nbsp;·&nbsp;
                                @endif
                                <a href="{{ $urls['privacy'] }}" style="color:#5b6b68; text-decoration:underline;">{{ $t['privacy'] }}</a>
                                &nbsp;·&nbsp;
                                <a href="{{ $urls['terms'] }}" style="color:#5b6b68; text-decoration:underline;">{{ $t['terms'] }}</a>
                                &nbsp;·&nbsp;
                                <a href="{{ $urls['security'] }}" style="color:#5b6b68; text-decoration:underline;">{{ $t['security'] }}</a>
                                &nbsp;·&nbsp;
                                <a href="{{ $urls['support'] }}" style="color:#5b6b68; text-decoration:underline;">{{ $t['support'] }}</a>
                            </div>
                            @if (($footerNote ?? '') !== '')
                                <div style="font-family:{!! $font !!}; font-size:12px; color:#5b6b68; padding-top:8px; line-height:1.7;">
                                    {{ $footerNote }}
                                </div>
                            @endif
                            <div style="font-family:{!! $font !!}; font-size:12px; color:#5b6b68; padding-top:8px; line-height:1.7;">
                                © {{ $year }} {!! $wordmarkPlain !!} · <a href="{{ $urls['home'] }}" style="color:#5b6b68; text-decoration:underline;">{{ $urls['host'] }}</a>
                                <br />{{ $t['sender'] }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
