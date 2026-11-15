{{--
  The small outlined button inside a card — EMAIL-CLIENT-COMPAT-001.

  Bullet-proof: the padding, border and white ground sit on a TABLE CELL (Outlook ignores padding on a link; the Gmail app ignored
  `display:block` and wrapped the label), the label is `white-space:nowrap` with a fixed px line-height, and the cell sits on the
  end side through a full-width wrapper cell's `align` — never through `float` or an aligned table, which some clients float.
  Needs $href, $label, $ink; optional $top (px above, default 10), $side (default the end side), $attr (extra attribute on the link).
--}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;">
    <tr>
        <td height="{{ $top ?? 10 }}" style="height:{{ $top ?? 10 }}px; line-height:{{ $top ?? 10 }}px; font-size:1px; mso-line-height-rule:exactly;">&nbsp;</td>
    </tr>
    <tr>
        <td align="{{ $side ?? $endSide }}" style="text-align:{{ $side ?? $endSide }};">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                <tr>
                    <td align="center" bgcolor="#ffffff" data-button="card" style="background-color:#ffffff; border:1px solid {{ $ink }}; border-radius:8px; padding:6px 12px; white-space:nowrap; text-align:center; font-size:12px; line-height:16px; font-weight:700; color:{{ $ink }}; mso-line-height-rule:exactly;"><a href="{{ $href }}" {!! $attr ?? '' !!} style="color:{{ $ink }}; text-decoration:none; white-space:nowrap; font-size:12px; line-height:16px; font-weight:700;">{{ $label }}</a></td>
                </tr>
            </table>
        </td>
    </tr>
</table>
