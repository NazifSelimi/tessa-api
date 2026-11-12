@php use App\Services\Documents\DocumentText as T; @endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head><meta charset="utf-8">@include('documents._style')</head>
<body>
@foreach ($orders as $order)
    @php $l = $order['locale']; @endphp
    @include('documents._header', [
        'locale' => $l,
        'title' => __('documents.delivery_title', [], $l),
        'meta' => '<div style="margin-top:6px;">' . e(__('documents.order_number', [], $l)) . ' <b>' . $order['number'] . '</b><br>' . e(__('documents.date', [], $l)) . ': ' . e($order['date']) . '</div>',
    ])
    @include('documents._buyer', ['buyerLabel' => __('documents.deliver_to', [], $l)])

    <table class="lines">
        <thead>
            <tr>
                <th style="width: 6%;">{{ __('documents.row', [], $l) }}</th>
                <th style="width: 15%;">{{ __('documents.code', [], $l) }}</th>
                <th>{{ __('documents.product', [], $l) }}</th>
                <th class="c" style="width: 10%;">{{ __('documents.qty', [], $l) }}</th>
                <th class="c" style="width: 8%;">✓</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order['lines'] as $i => $line)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td class="small">{{ $line['code'] }}</td>
                    <td>{{ $line['name'] }}</td>
                    <td class="c" style="font-weight: bold; font-size: 12px;">{{ $line['qty'] }}</td>
                    <td class="c"><span style="display: inline-block; width: 11px; height: 11px; border: 1px solid #555;"></span></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p style="text-align: right; margin-top: 8px;"><b>{{ __('documents.pieces_total', [], $l) }}: {{ $order['pieces'] }}</b></p>

    @if ($order['note'])
        <div class="box" style="margin-top: 8px;"><span class="label">{{ __('documents.customer_note', [], $l) }}</span><br>{{ $order['note'] }}</div>
    @endif

    <table style="margin-top: 46px;">
        <tr>
            <td style="width: 45%; border-top: 1px solid #555; padding-top: 4px;">{{ __('documents.delivered_by', [], $l) }}<br><span class="small muted">{{ __('documents.signature', [], $l) }}</span></td>
            <td style="width: 10%;"></td>
            <td style="border-top: 1px solid #555; padding-top: 4px;">{{ __('documents.received_by', [], $l) }}<br><span class="small muted">{{ __('documents.signature', [], $l) }}</span></td>
        </tr>
    </table>
    @if (! $loop->last)<div class="page-break"></div>@endif
@endforeach
</body>
</html>
