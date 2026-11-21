{{--
  The call to action: a centred table cell, not an inline-block link — EMAIL-RTL-001.

  A bare `display:inline-block` anchor sits at the start edge of whatever contains it, so in an Arabic
  message it hugged the right while the header was centred, and Outlook ignores the padding on an
  anchor altogether. A cell carries `bgcolor` (Outlook), a 44px line box (a tap target) and the
  padding on the anchor itself. White on #0b7a62 is 5.3:1; the brand green #0d8a6f is 4.3:1 and
  fails AA at this size, so the button is the one place the darker step is used.

  The `<style>` rule in the layout makes it full width under 480px. It is progressive: without it
  the button is simply centred.

  EMAIL-CLIENT-COMPAT-001 — the label is `white-space:nowrap` with a px line-height (no `min-height`, which mail clients drop), the
  layout `<style>` no longer widens it, and Outlook on Windows draws a VML round-rect (its only way to round a corner and keep
  the whole button clickable); every other client skips that block and draws the cell.

  Needs: $href, $label, $font.
--}}
@php($vmlWidth = max(180, (int) (mb_strlen($label) * 9) + 64))
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; margin:22px 0 6px;">
    <tr>
        <td align="center" style="text-align:center;">
            <!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $href }}" style="height:44px; v-text-anchor:middle; width:{{ $vmlWidth }}px;" arcsize="18%" stroke="f" fillcolor="#0b7a62"><w:anchorlock/><center style="color:#ffffff; font-family:{!! $font !!}; font-size:15px; font-weight:bold;">{{ $label }}</center></v:roundrect><![endif]-->
            <!--[if !mso]><!-- -->
            <table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;">
                <tr>
                    <td align="center" bgcolor="#0b7a62" data-button="cta" style="background-color:#0b7a62; border-radius:8px; text-align:center; white-space:nowrap;">
                        <a class="ch-btn" href="{{ $href }}" style="display:inline-block; line-height:44px; padding:0 32px; font-family:{!! $font !!}; font-size:15px; font-weight:700; color:#ffffff; text-decoration:none; border-radius:8px; text-align:center; white-space:nowrap; mso-line-height-rule:exactly;">{{ $label }}</a>
                    </td>
                </tr>
            </table>
            <!--<![endif]-->
        </td>
    </tr>
</table>
