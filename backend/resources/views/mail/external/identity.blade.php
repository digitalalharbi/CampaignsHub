{{-- The workspace's identity band (spec §8): its mark, its name, and the project badge — in the shell's dark band. Needs $m, $dir, $startSide, $font. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" dir="{{ $dir }}" data-block="identity">
    <tr>
        <td width="44" valign="middle" style="padding:16px {{ $startSide === 'right' ? '24px 16px 0' : '0 16px 24px' }};">
            <table role="presentation" width="40" cellpadding="0" cellspacing="0" border="0"><tr><td width="40" height="40" align="center" bgcolor="#0d8a6f" style="width:40px; height:40px; background-color:#0d8a6f; border-radius:10px; font-family:{!! $font !!}; font-size:19px; font-weight:800; color:#ffffff; text-align:center; line-height:40px;">{{ $m['initials'] }}</td></tr></table>
        </td>
        <td valign="middle" style="padding:14px 0 14px; text-align:{{ $startSide }};">
            <div style="font-family:{!! $font !!}; font-size:16px; line-height:1.4; font-weight:800; color:#f7f5f0;">{{ $m['workspace'] }}</div>
            <span data-badge="project" style="display:inline-block; margin-top:3px; padding:2px 10px; border-radius:999px; font-family:{!! $font !!}; font-size:12px; line-height:16px; font-weight:800; background-color:#16332c; color:#7ee2c5;" dir="auto">{{ $m['badge'] }}</span>
        </td>
    </tr>
</table>
