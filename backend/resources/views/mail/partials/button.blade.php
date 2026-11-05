{{--
  The call to action: a centred table cell, not an inline-block link — EMAIL-RTL-001.

  A bare `display:inline-block` anchor sits at the start edge of whatever contains it, so in an Arabic
  message it hugged the right while the header was centred, and Outlook ignores the padding on an
  anchor altogether. A cell carries `bgcolor` (Outlook), a 44px line box (a tap target) and the
  padding on the anchor itself. White on #0b7a62 is 5.3:1; the brand green #0d8a6f is 4.3:1 and
  fails AA at this size, so the button is the one place the darker step is used.

  The `<style>` rule in the layout makes it full width under 480px. It is progressive: without it
  the button is simply centred.

  Needs: $href, $label, $font.
--}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 6px;">
    <tr>
        <td align="center" style="text-align:center;">
            <table role="presentation" class="ch-btn-wrap" align="center" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;">
                <tr>
                    <td align="center" bgcolor="#0b7a62" style="background-color:#0b7a62; border-radius:8px; text-align:center;">
                        <a class="ch-btn" href="{{ $href }}" style="display:inline-block; min-height:44px; line-height:44px; padding:0 32px; font-family:{!! $font !!}; font-size:15px; font-weight:700; color:#ffffff; text-decoration:none; border-radius:8px; text-align:center;">{{ $label }}</a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
