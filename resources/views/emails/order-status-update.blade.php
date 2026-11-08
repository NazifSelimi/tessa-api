@php
    $statusKey = in_array($statusLabel, ['pending', 'confirmed', 'shipped', 'cancelled'], true) ? $statusLabel : strtolower($statusLabel);
    $statusKey = $statusKey === 'paid' ? 'confirmed' : $statusKey;
    $colors = ['confirmed' => ['#e6f4ea', '#16794c'], 'shipped' => ['#e8f0fe', '#1a56db'], 'cancelled' => ['#fdecea', '#b42318'], 'pending' => ['#fff4e0', '#a35c00']];
    [$badgeBg, $badgeInk] = $colors[$statusKey] ?? ['#eef1f5', '#4a5565'];
    $statusText = __('emails.status.' . $statusKey);
    $statusText = str_starts_with($statusText, 'emails.') ? ucfirst($statusKey) : $statusText;
    $message = __('emails.order_status.' . $statusKey);
    $message = str_starts_with($message, 'emails.') ? __('emails.order_status.updated') : $message;
@endphp
@extends('emails.layouts.shop', ['title' => __('emails.order_status.title', ['id' => $order->id]), 'preheader' => $message])

@section('content')
    <p style="margin:0 0 12px;"><span style="display:inline-block; padding:5px 14px; border-radius:999px; font-size:13px; font-weight:700; background:{{ $badgeBg }}; color:{{ $badgeInk }};">{{ $statusText }}</span></p>
    <h1 style="font-size:24px; margin:0 0 8px; color:#242124;">{{ __('emails.order_status.title', ['id' => $order->id]) }}</h1>
    <p style="font-size:16px; line-height:1.6; margin:0;">{{ $message }}</p>
    @if ($order->tracking_number)
        <p style="font-size:14px; margin:12px 0 0;">Tracking: <strong>{{ $order->tracking_number }}</strong></p>
    @endif
    @include('emails.partials.order-summary', ['order' => $order])
    @php($phone = app(\App\Services\BusinessProfile::class)->get('phone'))
    @if ($phone)
        <p style="font-size:14px; line-height:1.6; margin:24px 0 0; color:#6f6a6a;">{{ __('emails.questions', ['phone' => $phone]) }}</p>
    @endif
@endsection
