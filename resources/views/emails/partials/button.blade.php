@php($brand = app(\App\Services\BusinessProfile::class)->get('brand_color') ?: '#9b5364')
<table role="presentation" cellpadding="0" cellspacing="0" align="center" style="margin:24px auto 8px;">
    <tr>
        <td style="border-radius:999px; background:{{ $brand }};">
            <a href="{{ $url }}" style="display:inline-block; padding:13px 28px; font-size:15px; font-weight:700; color:#ffffff; text-decoration:none; border-radius:999px;">{{ $label }}</a>
        </td>
    </tr>
</table>
<p style="font-size:12px; color:#9a9393; text-align:center; margin:4px 0 0; word-break:break-all;">{{ __('emails.button_fallback') }}<br>{{ $url }}</p>
