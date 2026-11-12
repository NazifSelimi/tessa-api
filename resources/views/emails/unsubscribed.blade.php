@php($shop = app(\App\Services\BusinessProfile::class)->all())
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>{{ $shop['name'] }}</title>
</head>
<body style="margin:0; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; background:#f5f1ee; color:#242124;">
<main style="max-width:460px; margin:12vh auto; padding:32px 28px; background:#fff; border-radius:14px; border-top:4px solid {{ $shop['brand_color'] ?: '#9b5364' }}; text-align:center;">
    @if ($shop['logo_url'])<img src="{{ $shop['logo_url'] }}" alt="{{ $shop['name'] }}" style="height:34px; width:auto; margin-bottom:20px;">@endif
    @if ($subscribed)
        <h1 style="font-size:22px;">You are subscribed again</h1>
        <p style="line-height:1.6;">You will receive news and offers from {{ $shop['name'] }} again.</p>
    @else
        <h1 style="font-size:22px;">You are unsubscribed</h1>
        <p style="line-height:1.6;">You will no longer receive news and offers from {{ $shop['name'] }}. Order emails (confirmations, delivery updates) still arrive.</p>
        <p style="line-height:1.6;">Отпишани сте од новостите. &middot; Jeni çregjistruar nga lajmet.</p>
        <p><a href="{{ $resubscribeUrl }}" style="color:{{ $shop['brand_color'] ?: '#9b5364' }};">Subscribe again</a></p>
    @endif
    <p><a href="{{ $shop['website'] }}" style="color:#6f6a6a;">{{ $shop['website'] }}</a></p>
</main>
</body>
</html>
