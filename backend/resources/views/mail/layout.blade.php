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

  ## The container is fluid WITHOUT the media query — EMAIL-LAYOUT-001

  It was `width:600px`, narrowed only by a rule in `<style>`. A client that drops `<style>` (Gmail on
  a non-Google account, a forward, a clip) then showed a phone a 600px page to scroll sideways, which
  is the «dimensions» complaint. Now `width:100%; max-width:600px` on the table is what every modern
  client obeys with no help, and the `[if mso]` table is the fixed 600 only Outlook on Windows reads,
  because Word ignores `max-width`. The media query is left refining padding and stacking.
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
            .ch-outer { padding: 12px 8px !important; }
            .ch-pad { padding-left: 16px !important; padding-right: 16px !important; }
            .ch-stack { display: block !important; width: 100% !important; box-sizing: border-box !important; }
            .ch-kpi { display: block !important; width: 100% !important; box-sizing: border-box !important; padding-bottom: 12px !important; }
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
            <td class="ch-outer" align="center" style="padding:24px 12px;">
                <!--[if mso]><table role="presentation" align="center" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
                <table role="presentation" class="ch-wrap" align="center" width="100%" cellpadding="0" cellspacing="0" border="0" dir="{{ $dir }}" style="margin:0 auto; width:100%; max-width:600px; background-color:#ffffff; border-radius:14px; overflow:hidden; border:1px solid #e4e0d6;">

                    {{--
                      Header — EMAIL-BRAND-002.

                      A full-width dark band: the transparent cream MARK as the only image, the wordmark as
                      LIVE TEXT. The old raster lockup was an opaque dark rectangle; on a pale or inverted
                      page its hard edges showed. Text always renders, so with images blocked the band still
                      reads as the brand. `bgcolor` + inline colour + the dark-mode rules keep the band
                      dark in Gmail dark mode, which otherwise re-inverts plain background colours. The
                      Gmail app on a phone ignores those rules and turned this band pale (Owner, 2026-11-05):
                      it does not rewrite a background IMAGE, so the same colour is also a one-colour gradient.
                    --}}
                    <tr>
                        <td class="ch-hdr" align="{{ $align }}" dir="{{ $dir }}" bgcolor="#0b1a16" style="background-color:#0b1a16; background-image:linear-gradient(#0b1a16,#0b1a16); padding:0; border-bottom:3px solid #0b7a62; text-align:{{ $align }}; font-family:{!! $font !!}; font-size:20px; line-height:1.4; font-weight:700; color:#f7f5f0;">
                            {{-- The banner IS the band: as wide as the card on every screen, with the mark on the reader's side (Owner, 2026-11-05). Blocked images show the alt text on the same ground. --}}
                            <a href="{{ $urls['home'] }}" style="text-decoration:none; display:block; color:#f7f5f0;">
                                <img src="{{ $logoUrl }}" width="600" alt="{{ $wordmark }}" style="display:block; width:100%; max-width:600px; height:auto; border:0; outline:none; text-decoration:none; color:#f7f5f0;" />
                            </a>
                            @if (!empty($scopeNote ?? ''))
                                <div class="ch-hdr-tag" style="font-family:{!! $font !!}; font-size:12px; font-weight:400; color:#b7c9c2; padding:0 24px 12px; text-align:{{ $align }};">{{ $scopeNote }}</div>
                            @endif
                        </td>
                    </tr>

                    {{-- EMAIL-TEST-SEND-001 — only ever set by `TestSendMark`; a message to a customer has no such row. --}}
                    @if (! empty($testSendNotice ?? null))
                        <tr>
                            <td class="ch-pad" data-test-send-notice="1" dir="{{ $dir }}" align="{{ $align }}" bgcolor="#fffbeb" style="padding:12px 24px; background-color:#fffbeb; border-bottom:2px solid #b45309; text-align:{{ $align }}; direction:{{ $dir }}; font-family:{!! $font !!}; font-size:13px; line-height:1.7; color:#0b1a16;">
                                <strong>{{ $testSendNotice['title'] }}</strong> {{ $testSendNotice['detail'] }}
                            </td>
                        </tr>
                    @endif

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
                                    @if (! empty($unsubscribeUrl ?? null))
                                        <a href="{{ $unsubscribeUrl }}" style="color:#5b6b68; text-decoration:underline;">{{ $t['unsubscribe'] }}</a>
                                        &nbsp;·&nbsp;
                                    @endif
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
                                {{-- Only an agency's digest names a sender here; the banner already says who the platform is (Owner, 2026-11-05). --}}
                                @if (! empty($t['sender'] ?? null))
                                    <br />{{ $t['sender'] }}
                                @endif
                            </div>
                        </td>
                    </tr>
                </table>
                <!--[if mso]></td></tr></table><![endif]-->
            </td>
        </tr>
    </table>
</body>
</html>
