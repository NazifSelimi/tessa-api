<table style="margin: 14px 0 12px;">
    <tr>
        <td class="box" style="width: 60%;">
            <div class="label">{{ $buyerLabel }}</div>
            <div style="font-size: 13px; font-weight: bold;">{{ $order['buyer']['name'] ?: '—' }}</div>
            @if ($order['buyer']['address'])<div>{{ $order['buyer']['address'] }}</div>@endif
            @if ($order['buyer']['phone'])<div>{{ __('documents.phone', [], $l) }}: {{ $order['buyer']['phone'] }}</div>@endif
            @if ($order['buyer']['email'])<div class="muted">{{ $order['buyer']['email'] }}</div>@endif
        </td>
        <td style="width: 3%;"></td>
        <td class="box">
            <div class="label">{{ __('documents.payment', [], $l) }}</div>
            <div>
                @if (in_array(strtolower($order['paymentMethod']), ['', 'cod', 'cash', 'cash_on_delivery'], true))
                    {{ __('documents.payment_cod', [], $l) }}
                @else
                    {{ __('documents.payment_other', ['method' => $order['paymentMethod']], $l) }}
                @endif
            </div>
            <div class="label" style="margin-top: 6px;">{{ __('documents.total', [], $l) }}</div>
            <div style="font-size: 14px; font-weight: bold;">{{ \App\Services\Documents\DocumentText::money($order['total'], $l) }}</div>
        </td>
    </tr>
</table>
