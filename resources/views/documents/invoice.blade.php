@php use App\Services\Documents\DocumentText as T; @endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head><meta charset="utf-8">@include('documents._style')</head>
<body>
@foreach ($orders as $order)
    @php $l = $order['locale']; @endphp
    @include('documents._header', [
        'locale' => $l,
        'title' => __('documents.invoice_title', [], $l),
        'subtitle' => __('documents.invoice_subtitle', [], $l),
        'meta' => '<div style="margin-top:6px;">' . e(__('documents.order_number', [], $l)) . ' <b>' . $order['number'] . '</b><br>' . e(__('documents.date', [], $l)) . ': ' . e($order['date']) . '</div>',
    ])
    @include('documents._buyer', ['buyerLabel' => __('documents.buyer', [], $l)])

    <table class="lines">
        <thead>
            <tr>
                <th style="width: 5%;">{{ __('documents.row', [], $l) }}</th>
                <th style="width: 13%;">{{ __('documents.code', [], $l) }}</th>
                <th>{{ __('documents.product', [], $l) }}</th>
                <th class="c" style="width: 8%;">{{ __('documents.qty', [], $l) }}</th>
                <th class="r" style="width: 16%;">{{ __('documents.unit_price', [], $l) }}</th>
                <th class="r" style="width: 17%;">{{ __('documents.amount', [], $l) }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order['lines'] as $i => $line)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td class="small">{{ $line['code'] }}</td>
                    <td>{{ $line['name'] }}</td>
                    <td class="c">{{ $line['qty'] }}</td>
                    <td class="r">{{ T::money($line['price'], $l) }}</td>
                    <td class="r">{{ T::money($line['amount'], $l) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals" style="margin-top: 10px;">
        <tr><td style="width: 55%;"></td><td>{{ __('documents.subtotal', [], $l) }}</td><td class="r">{{ T::money($order['subtotal'], $l) }}</td></tr>
        @if ($order['discount'] > 0)
            <tr><td></td><td>{{ __('documents.discount', [], $l) }}@if ($order['couponCode']) ({{ __('documents.coupon', ['code' => $order['couponCode']], $l) }})@endif</td><td class="r">− {{ T::money($order['discount'], $l) }}</td></tr>
        @endif
        @if ($order['storeCredit'] > 0)
            <tr><td></td><td>{{ __('documents.store_credit', [], $l) }}</td><td class="r">− {{ T::money($order['storeCredit'], $l) }}</td></tr>
        @endif
        <tr><td></td><td>{{ __('documents.delivery', [], $l) }}</td><td class="r">{{ $order['shipping'] > 0 ? T::money($order['shipping'], $l) : __('documents.delivery_free', [], $l) }}</td></tr>
        <tr class="grand"><td></td><td>{{ __('documents.total', [], $l) }}</td><td class="r">{{ T::money($order['total'], $l) }}</td></tr>
    </table>

    @if ($order['note'])
        <div class="box" style="margin-top: 14px;"><span class="label">{{ __('documents.customer_note', [], $l) }}</span><br>{{ $order['note'] }}</div>
    @endif

    <p class="small muted" style="margin-top: 18px;">{{ __('documents.not_fiscal', [], $l) }}</p>
    <p style="text-align: center; margin-top: 14px; color: {{ $shop->get('brand_color') }};">{{ __('documents.thanks', [], $l) }}</p>
    @if (! $loop->last)<div class="page-break"></div>@endif
@endforeach
</body>
</html>
