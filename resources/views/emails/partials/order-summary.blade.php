{{-- Items, totals and delivery address of an order. Needs $order. --}}
@php
    $money = fn ($amount) => number_format(round((float) $amount), 0, ',', '.') . ' ' . __('emails.currency');
    $items = $order->items ?? collect();
    $info = $order->info;
    $subtotal = $items->sum(fn ($item) => (float) $item->price * (int) $item->quantity);
@endphp
<h2 style="font-size:13px; letter-spacing:1px; text-transform:uppercase; color:#8a7f7b; margin:28px 0 10px;">{{ __('emails.order_items') }}</h2>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px; border-collapse:collapse;">
    @foreach ($items as $item)
        <tr>
            <td style="padding:10px 0; border-bottom:1px solid #f0ebe8;">
                {{ $item->product?->name ?? (__('emails.product') . ' #' . $item->product_id) }}
                <span style="color:#8a7f7b;">&times; {{ $item->quantity }}</span>
            </td>
            <td align="right" style="padding:10px 0; border-bottom:1px solid #f0ebe8; white-space:nowrap;">{{ $money($item->price * $item->quantity) }}</td>
        </tr>
    @endforeach
    <tr><td style="padding:10px 0 4px; color:#6f6a6a;">{{ __('emails.subtotal') }}</td><td align="right" style="padding:10px 0 4px; color:#6f6a6a;">{{ $money($subtotal) }}</td></tr>
    @if ((float) $order->discount > 0)
        <tr><td style="padding:4px 0; color:#16794c;">{{ __('emails.discount') }}{{ $order->coupon_code ? ' (' . $order->coupon_code . ')' : '' }}</td><td align="right" style="padding:4px 0; color:#16794c;">&minus;{{ $money($order->discount) }}</td></tr>
    @endif
    @if ((float) ($order->store_credit_used ?? 0) > 0)
        <tr><td style="padding:4px 0; color:#16794c;">{{ __('emails.store_credit') }}</td><td align="right" style="padding:4px 0; color:#16794c;">&minus;{{ $money($order->store_credit_used) }}</td></tr>
    @endif
    @if ($order->shipping !== null)
        <tr><td style="padding:4px 0; color:#6f6a6a;">{{ __('emails.shipping') }}</td><td align="right" style="padding:4px 0; color:#6f6a6a;">{{ (float) $order->shipping > 0 ? $money($order->shipping) : __('emails.shipping_free') }}</td></tr>
    @endif
    <tr>
        <td style="padding:12px 0 0; font-size:17px; font-weight:800; border-top:2px solid #242124;">{{ __('emails.total') }}</td>
        <td align="right" style="padding:12px 0 0; font-size:17px; font-weight:800; border-top:2px solid #242124; white-space:nowrap;">{{ $money($order->total) }}</td>
    </tr>
</table>
@if ($info)
    <h2 style="font-size:13px; letter-spacing:1px; text-transform:uppercase; color:#8a7f7b; margin:28px 0 8px;">{{ __('emails.delivery_to') }}</h2>
    <p style="font-size:14px; line-height:1.6; margin:0;">
        {{ trim(($info->first_name ?? '') . ' ' . ($info->last_name ?? '')) }}<br>
        @if ($info->address){{ $info->address }}<br>@endif
        {{ trim(($info->city ?? '') . ($info->postal_code ? ', ' . $info->postal_code : ''), ', ') }}
        @if ($info->phone)<br>{{ __('emails.phone') }}: {{ $info->phone }}@endif
    </p>
@endif
@if ($order->message)
    <h2 style="font-size:13px; letter-spacing:1px; text-transform:uppercase; color:#8a7f7b; margin:24px 0 8px;">{{ __('emails.your_message') }}</h2>
    <p style="font-size:14px; line-height:1.6; margin:0;">{{ $order->message }}</p>
@endif
