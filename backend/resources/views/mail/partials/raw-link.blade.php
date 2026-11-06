{{--
  The full address under the button, for every client that blocks or rewrites links — EMAIL-RTL-001.

  Separated by a rule and aligned by locale. The URL itself is a left-to-right island
  (`dir="ltr"` + `unicode-bidi:embed`): inside an Arabic paragraph the bidi algorithm otherwise moves
  the query string's punctuation to the wrong end and the link no longer matches what was issued.
  `word-break:break-all` so a 90-character token wraps instead of widening the card.

  Needs: $url, $t['or_paste'], $dir, $startSide, $font.
--}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:18px; border-top:1px solid #e4e0d6;">
    <tr>
        <td dir="{{ $dir }}" align="{{ $startSide }}" style="padding-top:14px; text-align:{{ $startSide }}; font-family:{!! $font !!}; font-size:12px; line-height:1.7; color:#5b6b68;">
            {{ $t['or_paste'] }}<br />
            <span class="ch-url" dir="ltr" style="direction:ltr; unicode-bidi:embed; display:inline-block; max-width:100%; word-break:break-all; overflow-wrap:anywhere; color:#0b7a62; text-align:left;"><a href="{{ $url }}" style="color:#0b7a62; text-decoration:underline; word-break:break-all;">{{ $url }}</a></span>
        </td>
    </tr>
</table>
