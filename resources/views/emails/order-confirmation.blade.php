@extends('emails.layouts.shop', ['title' => __('emails.order_confirmation.title'), 'preheader' => __('emails.order_confirmation.intro', ['id' => $order->id])])

@section('content')
    @php($name = trim($order->info?->first_name ?? $order->user?->first_name ?? ''))
    <h1 style="font-size:24px; margin:0 0 8px; color:#242124;">{{ __('emails.order_confirmation.title') }}</h1>
    <p style="font-size:15px; line-height:1.6; margin:0 0 4px;">{{ $name ? __('emails.hi', ['name' => $name]) : __('emails.hi_plain') }}</p>
    <p style="font-size:15px; line-height:1.6; margin:0;">{{ __('emails.order_confirmation.intro', ['id' => $order->id]) }}</p>
    @include('emails.partials.order-summary', ['order' => $order])
    @if (($order->payment_method ?? 'cod') === 'cod' || ! $order->payment_method)
        <p style="font-size:14px; line-height:1.6; margin:24px 0 0; color:#6f6a6a;">{{ __('emails.payment_cod') }}</p>
    @endif
    @php($phone = app(\App\Services\BusinessProfile::class)->get('phone'))
    @if ($phone)
        <p style="font-size:14px; line-height:1.6; margin:8px 0 0; color:#6f6a6a;">{{ __('emails.questions', ['phone' => $phone]) }}</p>
    @endif
@endsection
