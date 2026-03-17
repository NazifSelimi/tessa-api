<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Status Update</title>
    <style>
        body { margin: 0; padding: 0; background-color: #f4f4f7; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
        .wrapper { max-width: 600px; margin: 0 auto; padding: 24px 16px; }
        .card { background: #ffffff; border-radius: 8px; padding: 32px; }
        .header { text-align: center; padding-bottom: 24px; border-bottom: 1px solid #eaeaea; margin-bottom: 24px; }
        .header h1 { margin: 0; font-size: 24px; color: #1a1a1a; }
        .status-badge { display: inline-block; padding: 8px 20px; border-radius: 6px; font-size: 16px; font-weight: 700; margin-top: 12px; }
        .status-confirmed { background: #dcfce7; color: #166534; }
        .status-shipped { background: #dbeafe; color: #1d4ed8; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        .status-default { background: #f3f4f6; color: #374151; }
        .section { margin-bottom: 24px; }
        .section h2 { font-size: 14px; color: #555; margin: 0 0 12px 0; text-transform: uppercase; letter-spacing: 0.5px; }
        .summary { font-size: 14px; color: #333; line-height: 1.6; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 8px 4px; font-size: 14px; }
        th { color: #888; font-weight: 600; border-bottom: 1px solid #eaeaea; }
        td { color: #333; border-bottom: 1px solid #f4f4f7; }
        .total-row td { font-weight: 700; font-size: 16px; border-top: 2px solid #1a1a1a; border-bottom: none; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="card">
        <div class="header">
            <h1>Order #{{ $order->id }} Update</h1>
            @php
                $badgeClass = match(strtolower($statusLabel)) {
                    'confirmed' => 'status-confirmed',
                    'shipped' => 'status-shipped',
                    'cancelled' => 'status-cancelled',
                    default => 'status-default',
                };
            @endphp
            <div class="status-badge {{ $badgeClass }}">{{ $statusLabel }}</div>
        </div>

        <div class="section">
            <p style="font-size:15px; color:#333; text-align:center;">
                @switch(strtolower($statusLabel))
                    @case('confirmed')
                        Your order has been confirmed and is being prepared.
                        @break
                    @case('shipped')
                        Your order has been shipped and is on its way!
                        @break
                    @case('cancelled')
                        Your order has been cancelled. If you did not request this, please contact us.
                        @break
                    @default
                        Your order status has been updated to <strong>{{ $statusLabel }}</strong>.
                @endswitch
            </p>
        </div>

        <div class="section">
            <h2>Order Items</h2>
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th style="text-align:center;">Qty</th>
                        <th style="text-align:right;">Price</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $item)
                        <tr>
                            <td>{{ $item->product?->name ?? 'Product #' . $item->product_id }}</td>
                            <td style="text-align:center;">{{ $item->quantity }}</td>
                            <td style="text-align:right;">${{ number_format($item->price * $item->quantity, 2) }}</td>
                        </tr>
                    @endforeach
                    @if ($order->discount > 0)
                        <tr>
                            <td colspan="2">Discount</td>
                            <td style="text-align:right; color:#16a34a;">-${{ number_format($order->discount, 2) }}</td>
                        </tr>
                    @endif
                    <tr class="total-row">
                        <td colspan="2">Total</td>
                        <td style="text-align:right;">${{ number_format($order->total, 2) }}</td>
                    </tr>
                </tbody>
            </table>
            <p style="font-size:13px; color:#888; margin-top:8px;">Placed: {{ $order->created_at->format('M j, Y') }}</p>
        </div>

        @if ($info)
            <div class="section">
                <h2>Shipping Address</h2>
                <div class="summary">
                    {{ $info->first_name }} {{ $info->last_name }}<br>
                    {{ $info->address }}<br>
                    {{ $info->city }}{{ $info->postal_code ? ', ' . $info->postal_code : '' }}<br>
                    {{ $info->country }}
                </div>
            </div>
        @endif
    </div>

    @include('emails.partials.footer')
</div>
</body>
</html>
